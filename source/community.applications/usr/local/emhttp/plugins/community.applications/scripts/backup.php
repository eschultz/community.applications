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
  
  $return = $returnMessage[$returnValue];
  if ( ! $return ) {
    $return = "Unknown Error";
  }
  return $return;
}
if ( ! is_file($communityPaths['backupOptions']) ) {
  exit;
}

if ( is_file($communityPaths['backupProgress']) ) {
  exit;
}
if ( is_file($communityPaths['restoreProgress']) ) {
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

if ( ! $backupOptions['dockerIMG'] )     { $backupOptions['dockerIMG'] = "exclude"; }
if ( ! $backupOptions['notification'] )  { $backupOptions['notification'] = "always"; }

if ( $restore ) {
  if ( $backupOptions['datedBackup'] == "yes" ) {
    $backupOptions['destinationShare'] = $backupOptions['destinationShare']."/".$argv[2];
  }
} else {
  if (  $backupOptions['datedBackup'] == "yes" ) {
    $backupOptions['destinationShare'] = $backupOptions['destinationShare']."/".exec("date +%F@%H.%M");
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
  shell_exec($backupOptions['stopScript']);
}
if ( is_array($dockerRunning) ) {
  foreach ($dockerRunning as $docker) {
    if ($docker['Running']) {
      logger("Stopping ".$docker['Name']);
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
  if ( $restore ) {
    $logLine = "Restoring ";
  } else {
    $logLine = "Backing up ";
  }
  logger("$logLine appData from $source to $destination");
  $command = '/usr/bin/rsync '.$backupOptions['rsyncOption'].' '.$dockerIMGFilter.' '.$rsyncExcluded.' --log-file="'.$communityPaths['backupLog'].'" "'.$source.'" "'.$destination.'" > /dev/null 2>&1';
  logger('Using command: '.$command);
  exec($command,$output,$returnValue);
  logger("$restoreMsg Complete");
}

if ( is_array($dockerRunning) ) {
  foreach ($dockerRunning as $docker) {
    if ($docker['Running']) {
      logger("Restarting ".$docker['Name']);
      shell_exec("docker start ".$docker['Name']);
    }
  }
}
if ( $backupOptions['startScript'] ) {
  logger("Executing custom start script ".$backupOptions['startScript']);
  shell_exec($backupOptions['startScript']);
}
logger('#######################');
logger("appData $restoreMsg complete");
logger('#######################');
if ( $returnValue > 0 ) {
  $message = getRsyncReturnValue($returnValue);
  $status = "- Errors occurred";
  $type = "warning";
  logger("Rsync Errors Occurred: $message");
  logger("Last 10 lines of rsync log:");
  exec("tail -n10 ".$communityPaths['backupLog'],$rsyncLog);
  foreach ($rsyncLog as $logLine) {
    logger($logLine);
  }
} else {
  $type = "normal";
}
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
if ( $restore ) {
  unlink($communityPaths['restoreProgress']);
} else {
  unlink($communityPaths['backupProgress']);
}
  
?>