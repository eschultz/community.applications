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
  $backupFlash = isset($_POST['backupFlash']) ? urldecode(($_POST['backupFlash'])) : "";
  $usbDestination = isset($_POST['usbDestination']) ?urldecode(($_POST['usbDestination'])) : "";
  
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
    $errors .= "Source and Destination Cannot Be The Same Share<br>";
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
      $errors .= "No Script at $startScript<br>";
    } else {
        if ( ! is_executable($startScript) ) {
        $errors .= "Start Script $startScript is not executable<br>";
      }
    }
  }
  
  if ( $backupFlash == "separate" ) {
    if ( ! $usbDestination ) {
      $errors .= "Destination for the USB Backup Must Be Specified<br>";
    } else {
      $origUSBDestination = $usbDestination;
      $availableDisks = parse_ini_file("/var/local/emhttp/disks.ini",true);
      foreach ($availableDisks as $disk) {
        $usbDestination = str_replace("/mnt/".$disk['name']."/","",$usbDestination);
      }
      $usbDestination = str_replace("/mnt/user0/","",$usbDestination);
      $usbDestination = str_replace("/mnt/user/","",$usbDestination);
      
      if ( $usbDestination == "" ) {
        $errors .= "USB Destination cannot be the root directory of /mnt/user or of a disk<br>";
      }
      if ( ! is_dir($origUSBDestination) ) {
        $errors .= "USB Destination Not A Valid Directory<br>";
      }
    }
  }
  if ( startsWith($usbDestination,$destinationShare) ) {
    $errors .= "USB Destination cannot be a sub-folder of Appdata destination<br>";
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
  $backupOptions['datedBackup'] = isset($_POST['datedBackup']) ? urldecode(($_POST['datedBackup'])) : "";
  $backupOptions['deleteOldBackup'] = isset($_POST['deleteOldBackup']) ? urldecode(($_POST['deleteOldBackup'])) : "";
  $backupOptions['fasterRsync'] = isset($_POST['fasterRsync']) ? urldecode(($_POST['fasterRsync'])) : "";
  $backupOptions['backupFlash'] = isset($_POST['backupFlash']) ? urldecode(($_POST['backupFlash'])) : "";
  $backupOptions['usbDestination'] = isset($_POST['usbDestination']) ?urldecode(($_POST['usbDestination'])) : "";
  
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
    $backupLines = "<font size='0'>".shell_exec("tail -n10 ".$communityPaths['backupLog'])."</font>";
    $backupLines = str_replace("\n","<br>",$backupLines);
  } else {
    $backupLines = "<br><br><br>";
  }
  if ( is_file($communityPaths['backupProgress']) || is_file($communityPaths['restoreProgress']) ) {
    $backupLines .= "
      <script>$('#backupStatus').html('<font color=red>Running</font> Your docker containers will be automatically restarted at the conclusion of the backup/restore');
      $('#restore').prop('disabled',true);
      $('#abort').prop('disabled',false);
      $('#Backup').attr('data-running','true');
      $('#Backup').prop('disabled',true);
      $('#deleteOldBackupSet').prop('disabled',true);
      </script>";
  } else {
    $backupLines .= "
    <script>
    $('#backupStatus').html('<font color=green>Not Running</font>');
    $('#abort').prop('disabled',true);
    $('#deleteOldBackupSet').prop('disabled',false);
    $('#Backup').attr('data-running','false');
    if ( appliedChanges == false ) {
      $('#Backup').prop('disabled',false);
    }
    </script>";
    if ( is_file($communityPaths['backupOptions']) ) {
      $backupLines .= "
      <script>if ( appliedChanges == false ) {
        $('#restore').prop('disabled',false);
      } else { 
        $('#restore').prop('disabled',true);
      }
      </script>";
    }
  }
  if ( is_file($communityPaths['deleteProgress']) ) {
    $backupLines .= "<script>$('#deleteOldBackupSet').prop('disabled',true);</script>";
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
  $backupOptions['availableDates'] = isset($_POST['availableDates']) ? urldecode(($_POST['availableDates'])) : "";
  shell_exec("/usr/local/emhttp/plugins/community.applications/scripts/restore.sh ".$backupOptions['availableDates']);
  break;
  
case 'deleteOldBackupSets':
  $backupOptions = readJsonFile($communityPaths['backupOptions']);
  $deleteFolder = escapeshellarg("/mnt/user/".$backupOptions['destinationShare']);
  shell_exec("/usr/local/emhttp/plugins/community.applications/scripts/deleteOldBackupSets.sh $deleteFolder");
  break;
  
case 'abortBackup':
  shell_exec("/usr/local/emhttp/plugins/community.applications/scripts/killRsync.php");
  break;
  
case 'getDates':
  $backupOptions['destinationShare'] = isset($_POST['destinationShare']) ? urldecode(($_POST['destinationShare'])) : "";
  $backupOptions['destination'] = isset($_POST['destination']) ? urldecode(($_POST['destination'])) : "";
  $availableDates = @array_diff(@scandir($backupOptions['destination']."/".$backupOptions['destinationShare']),array(".",".."));
  if ( ! is_array($availableDates) ) {
    $availableDates = array();
  }
  $output = "<select id='date'>";
  foreach ($availableDates as $date) {
    $output .= "<option value='$date'>$date</option>";
  }
  $output .= "</select>";
  echo $output;
  break;

case 'getBackupShare':
  $backupOptions = readJsonFile($communityPaths['backupOptions']);
  echo "/mnt/user/".$backupOptions['destinationShare'];
  break;
}