#!/usr/bin/php
<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

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


if ( is_file($communityPaths['backupProgress']) ) {
  exit;
}

if ( is_file($communityPaths['restoreProgress']) ) {
  exit;
}
@unlink($communityPaths['backupLog']);

file_put_contents($communityPaths['restoreProgress'],getmypid());
  
$dockerClient = new DockerClient();
$dockerRunning = $dockerClient->getDockerContainers();

$backupOptions = readJsonFile($communityPaths['backupOptions']);
  
if ( ! $backupOptions ) {
  exit;
}

if ( ! $backupOptions['dockerIMG'] ) {
  $backupOptions['dockerIMG'] = "exclude";
}

logger('#######################################');
logger("Community Applications appData Restore");
logger("Applications will be unavailable during");
logger("this process.  They will automatically");
logger("be restarted upon completion.");
logger('#######################################');
notify("Community Applications","appData Restore","Restore of appData starting.  This may take awhile");
  
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
  logger("Restoring appData from ".$backupOptions['destination']."/".$backupOptions['destinationShare']." to ".$backupOptions['source']);
  $command = '/usr/bin/rsync '.$backupOptions['rsyncOption'].' '.$dockerIMGFilter.' '.$rsyncExcluded.' --log-file="'.$communityPaths['backupLog'].'" "'.$backupOptions['destination'].'/'.$backupOptions['destinationShare'].'/" "'.$backupOptions['source'].'" > /dev/null 2>&1';
  logger('Using command: '.$command);
  exec($command,$output,$returnValue);
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
logger("appData restore complete");
logger('#######################');
if ( $returnValue > 0 ) {
  $message = getRsyncReturnValue($returnValue);
  $status = "- Errors occurred";
  $type = "warning";
  logger("Rsync Errors Occurred: $message");
} else {
  $type = "normal";
}
toDOS($communityPaths['backupLog'],"/boot/config/plugins/community.applications/backup.log");
notify("Community Applications","appData Backup","Restore of appData complete $status - Log is available on the flash drive at /config/plugins/community.applications/backup.log",$message,$type);

unlink($communityPaths['restoreProgress']);
  
?>
  