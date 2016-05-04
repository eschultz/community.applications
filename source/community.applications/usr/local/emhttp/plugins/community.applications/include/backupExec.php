<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");

switch ($_POST['action']) {

##############################################################
#                                                            #
# Returns errors on settings for backup / restore of appData #
#                                                            #
##############################################################

case 'validateBackupOptions':
  $source = isset($_POST['source']) ? urldecode(($_POST['source'])) : "";
  $destination = isset($_POST['destination']) ? urldecode(($_POST['destination'])) : "";
  $stopScript = isset($_POST['stopScript']) ? urldecode(($_POST['stopScript'])) : "";
  $startScript = isset($_POST['startScript']) ? urldecode(($_POST['startScript'])) : "";
  $destinationShare = isset($_POST['destinationShare']) ? urldecode(($_POST['destinationShare'])) : "";
  
  $destinationShare = str_replace("/mnt/user/","",$destinationShare);
  $destinationShare = rtrim($destinationShare,'/');
  
  if ( $source == "" ) {
    $errors .= "Source Must Be Specified<br>";
  }
  if ( $destination == "" || $destinationShare == "" ) {
    $errors .= "Destination Must Be Specified<br>";
  }
  
  if ( $source != "" && $source == $destination ) {
    $errors .= "Source and Destination Cannot Be The Same<br>";
  } else {
    $destDir = ltrim($destinationShare,'/');
    $destDirPaths = explode('/',$destDir);
    if ( basename($source) == $destDirPaths[0] ) {
      $errors .= "Destination cannot be a subfolder from source<br>";
    }
  }
  
  if ( basename($source) == $destinationShare ) {
    $errors .= "Source and Destination Cannot Be The Same Share";
  }
  
  if ( $stopScript ) {
    if ( ! is_file($stopScript) ) {
      $errors .= "No Script at $stopScript<br>";
    } else {
      if ( ! is_executable($stopScript) ) {
        $errors .= "Stop Script $stopScript is not executable<br>";
      }
    }
  }
  if ( $startScript ) {
    if ( ! is_file($startScript) ) {
      $errors .= "No Script at $startScript";
    } else {
        if ( ! is_executable($startScript) ) {
        $errors .= "Start Script $startScript is not executable<br>";
      }
    }
  }
  
  if ( ! $errors ) {
    $errors = "NONE";
  }
  echo $errors;
  
  break;
  
######################################
#                                    #
# Applies the backup/restore options #
#                                    #
######################################

case 'applyBackupOptions':
  $backupOptions['source']      = isset($_POST['source']) ? urldecode(($_POST['source'])) : "";
  $backupOptions['destinationShare'] = isset($_POST['destinationShare']) ? urldecode(($_POST['destinationShare'])) : "";
  $backupOptions['destination'] = isset($_POST['destination']) ? urldecode(($_POST['destination'])) : "";
  $backupOptions['stopScript']  = isset($_POST['stopScript']) ? urldecode(($_POST['stopScript'])) : "";
  $backupOptions['startScript'] = isset($_POST['startScript']) ? urldecode(($_POST['startScript'])) : "";
  $backupOptions['rsyncOption'] = isset($_POST['rsyncOption']) ? urldecode(($_POST['rsyncOption'])) : "";
  $backupOptions['cronSetting'] = isset($_POST['cronSetting']) ? urldecode(($_POST['cronSetting'])) : "";
  $backupOptions['cronDay']     = isset($_POST['cronDay']) ? urldecode(($_POST['cronDay'])) : "";
  $backupOptions['cronMonth']   = isset($_POST['cronMonth']) ? urldecode(($_POST['cronMonth'])) : "";
  $backupOptions['cronHour']    = isset($_POST['cronHour']) ? urldecode(($_POST['cronHour'])) : "";
  $backupOptions['cronMinute']  = isset($_POST['cronMinute']) ? urldecode(($_POST['cronMinute'])) : "";
  $backupOptions['cronCustom']  = isset($_POST['cronCustom']) ? urldecode(($_POST['cronCustom'])) : "";
  $backupOptions['runRsync']    = isset($_POST['runRsync']) ? urldecode(($_POST['runRsync'])) : "";
  $backupOptions['dockerIMG']   = isset($_POST['dockerIMG']) ? urldecode(($_POST['dockerIMG'])) : "";
  $backupOptions['notification'] = isset($_POST['notification']) ? urldecode(($_POST['notification'])) : "";
  $backupOptions['excluded']    = isset($_POST['excluded']) ? urldecode(($_POST['excluded'])) : "";
  $backupOptions['logBackup']   = isset($_POST['logBackup']) ? urldecode(($_POST['logBackup'])) : "";
  
  $backupOptions['excluded'] = trim($backupOptions['excluded']);
  
  $backupOptions['destinationShare'] = str_replace("/mnt/user/","",$backupOptions['destinationShare']);  # make new options conform to old layout of json
  $backupOptions['destinationShare'] = rtrim($backupOptions['destinationShare'],'/');
  
  writeJsonFile($communityPaths['backupOptions'],$backupOptions);
    
  exec($communityPaths['addCronScript']);
       
  break; 
  
###########################################
#                                         #
# Checks the status of a backup / restore #
#                                         #
###########################################

case 'checkBackup':
  if ( is_file($communityPaths['backupLog']) ) {
    $backupLines = "<font size='0'>".shell_exec("tail -n2 ".$communityPaths['backupLog'])."</font>";
    $backupLines = str_replace("\n","<br>",$backupLines);
  } else {
    $backupLines = "<br><br><br>";
  }
  if ( is_file($communityPaths['backupProgress']) || is_file($communityPaths['restoreProgress']) ) {
    $backupLines .= "<script>$('#backupStatus').html('<font color=red>Running</font> Your docker containers will be automatically restarted at the conclusion of the backup/restore');$('#restore').prop('disabled',true);$('#abort').prop('disabled',false);$('#Backup').attr('data-running','true');$('#Backup').prop('disabled',true);</script>";
  } else {
    $backupLines .= "<script>$('#backupStatus').html('<font color=green>Not Running</font>');$('#abort').prop('disabled',true);$('#Backup').attr('data-running','false');if ( appliedChanges == false ) { $('#Backup').prop('disabled',false);}</script>";
    if ( is_file($communityPaths['backupOptions']) ) {
    $backupLines .= "<script>if ( appliedChanges == false ) { $('#restore').prop('disabled',false); } else { $('#restore').prop('disabled',true); }</script>";
    }
  }
  echo $backupLines;
  break;

############################################################################################
#                                                                                          #
# backupNow, restoreNow, abortBackup - executes scripts to start / stop backups / restores #
#                                                                                          #
############################################################################################

case 'backupNow':
  shell_exec("/usr/local/emhttp/plugins/community.applications/scripts/backup.sh");
  break;
  
case 'restoreNow':
  shell_exec("/usr/local/emhttp/plugins/community.applications/scripts/restore.sh");
  break;
  
case 'abortBackup':
  shell_exec("/usr/local/emhttp/plugins/community.applications/scripts/killRsync.php");
  break;
}