#!/usr/bin/php
<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

if ( $argv[1] == "restore" ) {
  $restore = true;
  $restoreMsg = "Restore";
} else {
  $restore = false;
  $restoreMsg = "Backup";
}

require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");

exec("mkdir -p /tmp/community.applications/tempFiles/");

function getRsyncReturnValue($returnValue) {
  $returnMessage[0] = "Success";
  $returnMessage[1] = "Syntax or usage error";
  $returnMessage[2] = "Protocol incompatibility";
  $returnMessage[3] = "Errors selecting input/output files, dirs";
  $returnMessage[4] = "Requested action not supported: an attempt was made to manipulate 64-bit files on a platform that cannot support them; or an option was specified that is supported by the client and not by the server.";
  $returnMessage[5] = "Error starting client-server protocol";
  $returnMessage[6] = "Daemon unable to append to log-file";
  $returnMessage[10] = "Error in socket I/O";
  $returnMessage[11] = "Error in file I/O";
  $returnMessage[12] = "Error in rsync protocol data stream";
  $returnMessage[13] = "Errors with program diagnostics";
  $returnMessage[14] = "Error in IPC code";
  $returnMessage[20] = "Received SIGUSR1 or SIGINT";
  $returnMessage[21] = "Some error returned by waitpid()";
  $returnMessage[22] = "Error allocating core memory buffers";
  $returnMessage[23] = "Partial transfer due to error";
  $returnMessage[24] = "Partial transfer due to vanished source files";
  $returnMessage[25] = "The --max-delete limit stopped deletions";
  $returnMessage[30] = "Timeout in data send/receive";
  $returnMessage[35] = "Timeout waiting for daemon connection";
  
  $return = $returnMessage[$returnValue] ? $returnMessage[$returnValue] : "Unknown Error";
  return $return;
}

if ( is_file($communityPaths['backupProgress']) ) {
  logger("Backup already in progress.  Aborting");
  exit;
}
if ( is_file($communityPaths['restoreProgress']) ) {
  logger("Restore in progress. Aborting");
  exit;
}
@unlink($communityPaths['backupLog']);
$dockerSettings = @parse_ini_file($communityPaths['unRaidDockerSettings']);

if ( $restore ) {
  file_put_contents($communityPaths['restoreProgress'],getmypid());
} else {
  file_put_contents($communityPaths['backupProgress'],getmypid());
}
  
$dockerClient = new DockerClient();
$dockerRunning = $dockerClient->getDockerContainers();

$backupOptions = readJsonFile($communityPaths['backupOptions']);

if ( ! $backupOptions ) {
  exit;
}
$backupOptions['dockerIMG'] = "exclude";

if ( ! $backupOptions['backupFlash'] ) { $backupOptions['backupFlash'] = "appdata"; }
if ( ! $backupOptions['backupXML'] )   { $backupOptions['backupXML'] = "appdata"; }

$basePathBackup = $backupOptions['destination']."/".$backupOptions['destinationShare'];

if ( ! $backupOptions['dockerIMG'] )     { $backupOptions['dockerIMG'] = "exclude"; }
if ( ! $backupOptions['notification'] )  { $backupOptions['notification'] = "always"; }
if ( $backupOptions['deleteOldBackup'] == 0 ) { $backupOptions['deleteOldBackup'] = ""; }

if ( $restore ) {
  if ( $backupOptions['datedBackup'] == "yes" ) {
    $backupOptions['destinationShare'] = $backupOptions['destinationShare']."/".$argv[2];
  }
} else {
  if ( $backupOptions['datedBackup'] == "yes" ) {
    $newFolderDated = exec("date +%F@%H.%M");
    $backupOptions['destinationShare'] = $backupOptions['destinationShare']."/".$newFolderDated;

    if ( $backupOptions['fasterRsync'] == "yes" ) {
      $currentDate = date_create(now);
      $dirContents = array_diff(scandir($basePathBackup),array(".",".."));
      foreach ($dirContents as $dir) {
        $folderDate = date_create_from_format("Y-m-d@G.i",$dir);
        if ( ! $folderDate ) { continue; }
        $interval = date_diff($currentDate,$folderDate);
        $age = $interval->format("%R%a");
        if ( $age <= (0 - $backupOptions['deleteOldBackup']) ) {
          logger("Renaming $basePathBackup/$dir to $basePathBackup/$newFolderDated");
          exec("mv ".escapeshellarg($basePathBackup)."/".$dir." ".escapeshellarg($basePathBackup)."/".$newFolderDated);
          break;
        }   
      }
    }
  }
}

logger('#######################################');
logger("Community Applications appData $restoreMsg");
logger("Applications will be unavailable during");
logger("this process.  They will automatically");
logger("be restarted upon completion.");
logger('#######################################');
if ( $backupOptions['notification'] == "always" ) {
  notify("Community Applications","appData $restoreMsg","$restoreMsg of appData starting.  This may take awhile");
}
  
if ( $backupOptions['stopScript'] ) {
  logger("executing custom stop script ".$backupOptions['stopScript']);
  file_put_contents($communityPaths['backupLog'],"Executing custom stop script",FILE_APPEND);
  shell_exec($backupOptions['stopScript']);
}
if ( is_array($dockerRunning) ) {
  foreach ($dockerRunning as $docker) {
    if ($docker['Running']) {
      logger("Stopping ".$docker['Name']);
      file_put_contents($communityPaths['backupLog'],"Stopping ".$docker['Name']."\n",FILE_APPEND);
      shell_exec("docker stop ".$docker['Name']);
    }
  }
}
if ( $restore ) {
  $source = $backupOptions['destination']."/".$backupOptions['destinationShare']."/";
  $destination = $backupOptions['source'];
} else {
  $source = $backupOptions['source']."/";
  $destination = $backupOptions['destination']."/".$backupOptions['destinationShare'];
  if ( $backupOptions['backupFlash'] == "appdata" ) {
    $usbDestination = $source."Community_Applications_USB_Backup";
  } else {
    $usbDestination = $backupOptions['usbDestination'];
  }
  if ( $backupOptions['backupXML'] == "appdata" ) {
    $xmlDestination = $source."Community_Applications_VM_XML_Backup";
  } else {
    $xmlDestination = $backupOptions['xmlDestination'];
  }
  
  if ( $backupOptions['backupFlash'] != "no" ) {
    logger("Deleting Old USB Backup");
    exec("rm -rf '$usbDestination'");
    logger("Backing up USB Flash drive config folder to $usbDestination");
    file_put_contents($communityPaths['backupLog'],"Backing up USB Flash Drive\n",FILE_APPEND);
    exec("mkdir -p '$usbDestination'");
    $availableDisks = parse_ini_file("/var/local/emhttp/disks.ini",true);
    $txt .= "Disk Assignments as of ".date(DATE_RSS)."\r\n";
    foreach ($availableDisks as $Disk) {
      $txt .= "Disk: ".$Disk['name']."  Device: ".$Disk['id']."  Status: ".$Disk['status']."\r\n";
    }
    file_put_contents("/boot/config/DISK_ASSIGNMENTS.txt",$txt);
    exec("cp /boot/* '$usbDestination' -R -v");

    exec("mv '$usbDestination/config/super.dat' '$usbDestination/config/super.dat.CA_BACKUP'");
  }
  if ( $backupOptions['backupXML'] != "no" ) {
    logger("Deleting Old XML Backup");
    exec("rm -rf '$xmlDestination'");
    logger("Backing up VM XML's to $xmlDestination");
    file_put_contents($communityPaths['backupLog'],"Backing up VM XML's\n",FILE_APPEND);
    exec("mkdir -p '$xmlDestination'");
    $xmlList = @scandir("/etc/libvirt/qemu");
    if ( is_array($xml) ) {
      foreach ($xmlList as $xml) {
        if (is_dir("/etc/libvirt/qemu/$xml")) {
          continue;
        }
        if ( stripos($xml,".xml") ) {
          exec("todos < '/etc/libvirt/qemu/$xml' > '$xmlDestination/$xml'");
        }
      }
    }
  }
}
if ( $backupOptions['dockerIMG'] == "exclude" ) {
  $dockerIMGFilter = '--exclude "'.str_replace($backupOptions['source']."/","",$dockerSettings['DOCKER_IMAGE_FILE']).'"';
}

if ( $backupOptions['excluded'] ) {
  $exclusions = explode(",",$backupOptions['excluded']);
  
  foreach ($exclusions as $excluded) {
    $rsyncExcluded .= '--exclude "'.$excluded.'" ';
  }
}

if ( $backupOptions['runRsync'] == "true" ) {
  $logLine = $restore ? "Restoring " : "Backing Up";
  logger("$logLine appData from $source to $destination");
  $command = '/usr/bin/rsync '.$backupOptions['rsyncOption'].' '.$dockerIMGFilter.' '.$rsyncExcluded.' --log-file="'.$communityPaths['backupLog'].'" "'.$source.'" "'.$destination.'" > /dev/null 2>&1';
  logger('Using command: '.$command);
  file_put_contents($communityPaths['backupLog'],"Executing rsync: $command",FILE_APPEND);
  exec("mkdir -p ".escapeshellarg($destination));
  exec($command,$output,$returnValue);
  logger("$restoreMsg Complete");
}

if ( is_array($dockerRunning) ) {
  foreach ($dockerRunning as $docker) {
    if ($docker['Running']) {
      logger("Restarting ".$docker['Name']);
      file_put_contents($communityPaths['backupLog'],"Restarting ".$docker['Name']."\n",FILE_APPEND);
      shell_exec("docker start ".$docker['Name']);
    }
  }
}
if ( $backupOptions['startScript'] ) {
  logger("Executing custom start script ".$backupOptions['startScript']);
  file_put_contents($communityPaths['backupLog'],"Executing custom start script\n",FILE_APPEND);
  shell_exec($backupOptions['startScript']);
}
logger('#######################');
logger("appData $restoreMsg complete");
logger('#######################');

$message = getRsyncReturnValue($returnValue);

if ( $returnValue > 0 ) {
  $status = "- Errors occurred";
  $type = "warning";
} else {
  $type = "normal";
}

file_put_contents($communityPaths['backupLog'],"Backup/Restore Complete.  Rsync Status: $message\n",FILE_APPEND);

switch ($backupOptions['logBackup']) {
  case 'yes':
    toDOS($communityPaths['backupLog'],"/boot/config/plugins/community.applications/backup.log");
    $logMessage = " - Log is available on the flash drive at /config/plugins/community.applications/backup.log";
    break;
  case 'append':
    toDOS($communityPaths['backupLog'],"/boot/config/plugins/community.applications/backup.log",true);
    $logMessage = " - Log is available on the flash drive at /config/plugins/community.applications/backup.log";
    break;
  default:
    $logMessage = "";
    logger("Rsync log to flash drive disabled");
    break;
  case 'no':
    $logMessage = "";
    logger("Rsync log to flash drive disabled");
    break;
}

if ( ($backupOptions['notification'] == "always") || ($backupOptions['notification'] == "completion") || ( ($backupOptions['notification'] == "errors") && ($type == "warning") )  ) {
  notify("Community Applications","appData $restoreMsg","$restoreMsg of appData complete $status$logMessage",$message,$type);
}

if ( ! $restore && ($backupOptions['datedBackup'] == 'yes') ) {
  if ( $backupOptions['deleteOldBackup'] ) {
    if ( $returnValue > 0 ) {
      logger("rsync returned errors.  Not deleting old backup sets of appdata");
      file_put_contents($communityPaths['backupLog'],"rsync returned errors.  Not deleting old backup sets of appdata\n",FILE_APPEND);
      logger("Renaming $destination to $destination-error\n");
      file_put_contents($communityPaths['backupLog'],"Renaming $destination to $destination-error",FILE_APPEND);
      
      exec("mv ".escapeshellarg("$destination")." ".escapeshellarg("$destination-error"));
    } else {
      $currentDate = date_create(now);
      $dirContents = array_diff(scandir($basePathBackup),array(".",".."));
      foreach ($dirContents as $dir) {
        $folderDate = date_create_from_format("Y-m-d@G.i",$dir);
        if ( ! $folderDate ) { continue; }
        $interval = date_diff($currentDate,$folderDate);
        $age = $interval->format("%R%a");
        if ( $age <= (0 - $backupOptions['deleteOldBackup']) ) {
          logger("Deleting $basePathBackup/$dir");
          file_put_contents($communityPaths['backupLog'],"Deleting Dated Backup set: $basePathBackup/$dir\n",FILE_APPEND);
          exec("echo Deleting $basePathBackup/$dir >> ".$communityPaths['backupLog']."\n");
          exec('rm -rf '.escapeshellarg($basePathBackup).'/'.$dir);
        }   
      }
    }
  }
}
if ( ! startsWith($destination,"/mnt/user") ) {
  if ( $restore) {
    $temp = explode("/",$destination);
    $shareName = $temp[3];

    $shareCfg = @file_get_contents("/boot/config/shares/$shareName.cfg");
    if ( ! $shareCfg ) {
      $shareCfg = file_get_contents($communityPaths['defaultShareConfig']);
    }
    $shareCfg = str_replace('shareUseCache="no"','shareUseCache="only"',$shareCfg);
    file_put_contents($communityPaths['backupLog'],"Setting $shareName share to be cache-only\n",FILE_APPEND);
    file_put_contents("/boot/config/shares/$shareName.cfg",$shareCfg);
    file_put_contents($communityPaths['backupLog'],"Deleting any appdata files stored on the array\n",FILE_APPEND);
    exec('rm -rf '.escapeshellarg("/mnt/user0/$shareName"));
  
    file_put_contents($communityPaths['backupLog'],"Restore finished.  Ideally you should now restart your server\n",FILE_APPEND);
  }
}
if ( $returnValue > 0 ) {
  logger("Rsync Errors Occurred: $message");
  logger("Possible rsync errors:");
  exec("cat ".$communityPaths['backupLog']." | grep rsync",$rsyncLog);
  foreach ($rsyncLog as $logLine) {
    logger($logLine);
    file_put_contents($communityPaths['backupLog'],$logLine,FILE_APPEND);
  }
}
if ( $restore ) {
  unlink($communityPaths['restoreProgress']);
} else {
  unlink($communityPaths['backupProgress']);
}

?>