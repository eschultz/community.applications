<?PHP

###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");

function addError($description,$action) {
  global $errors;
  $errors .= "<tr><td><font color='red'>$description</font></td><td>$action</td></tr>";
}

function addLinkButton($buttonName,$link) {
  $link = str_replacE("'","&quot;",$link);
  return "<input type='button' value='$buttonName' onclick='window.location.href=&quot;$link&quot;'>";
}
function addButton($buttonName,$action) {
  $action = str_replace("'","&quot;",$action);
  return "<input type='button' value='$buttonName' onclick='$action'>";
}

switch ($_POST['action']) {
  case 'scan':
    $shareList = array_diff(scandir("/mnt/user"),array(".",".."));

    foreach ($shareList as $share) {
      if ( ! is_file("/boot/config/shares/$share.cfg") ) {
        if ( is_dir("/mnt/user0/$share") ) {
          $shareURL = str_replace(" ","+",$share);
          addError("Share <b><font color='purple'>$share</font></b> is a implied <em>cache-only</em> share, but files exist on the array","Set <b><em>Use Cache</em></b> appropriately, then rerun this analysis. ".addLinkButton("$share Settings","/Shares/Share?name=$shareURL"));
        }
      }
    }

    foreach ($shareList as $share) {
      if ( is_file("/boot/config/shares/$share.cfg") ) {
        $shareCfg = parse_ini_file("/boot/config/shares/$share.cfg");
        if ( $shareCfg['shareUseCache'] == "only" ) {
          if (is_dir("/mnt/user0/$share") ) {
            $shareURL = str_replace(" ","+",$share);
            addError("Share <b><font color='purple'>$share</font></b> set to <em>cache-only</em>, but files exist on the array",addButton("Move Files To Cache","moveToCache('$share');")." or change the share's settings appropriately ".addLinkButton("$share Settings","/Shares/Share?name=$shareURL"));
          }
        }
      }
    }

    foreach ($shareList as $share) {
      if ( is_file("/boot/config/shares/$share.cfg") ) {
        $shareCfg = parse_ini_file("/boot/config/shares/$share.cfg");
        if ( $shareCfg['shareUseCache'] == "no" ) {
          if ( is_dir("/mnt/cache/$share") ) {
            $shareURL = str_replace(" ","+",$share);
            addError("Share <b><font color='purple'>$share</font></b> set to <em>not use the cache</em>, but files exist on the cache drive",addButton("Move Files To Array","moveToArray('$share');")." or change the share's settings appropriately ".addLinkButton("$share Settings","/Shares/Share?name=$shareURL"));
          }
        }
      }
    }

    if ( ! is_file("/boot/config/plugins/dynamix/plugin-check.cron") ) {
      addError("<font color='purple'><b>Plugin Update Check</b></font> not enabled",addLinkButton("Notification Settings","/Settings/Notifications"));
    }

    $autoUpdateSettings = readJsonFile($communityPaths['autoUpdateSettings']);
    if ( $autoUpdateSettings['Global'] != "true" ) {
      if ( $autoUpdateSettings['community.applications.plg'] != "true" ) {
        addError("<font color='purple'><b>Community Applications</b></font> not set to auto update</font>",addLinkButton("Auto Update Settings","/Settings/AutoUpdate"));
      }
      if ( $autoUpdateSettings['dynamix.plg'] != "true" ) {
        addError("<font color='purple'><b>Dynamix WebUI</b></font> not set to auto update</font>",addLinkButton("Auto Update Settings","/Settings/AutoUpdate"));
      }
    }

    foreach ( $shareList as $share ) {
      $dupShareList = array_diff(scandir("/mnt/user/"),array(".","..",$share));
      foreach ($dupShareList as $dup) {
        if ( strtolower($share) == strtolower($dup) ) {
          addError("Same share <font color='purple'>($share)</font> exists in a different case","This will confuse SMB shares.  Manual intervention required.  Use the dolphin docker app to combine the shares into one unified spelling");
          break;
        }
      }
    }
      

    exec("ping -c 2 lksjfdslkfj.com",$dontCare,$pingReturn);
    if ( $pingReturn ) {
      addError("Unable to communicate with GitHub.com","Reset your modem / router or try again later, or set your ".addLinkButton("DNS Settings","/Settings/NetworkSettings")." to 8.8.8.8 and 8.8.4.4");
    }

    if ( ! $errors ) {
      echo "No configuration errors found!";
    } else {
      echo "<table class='tablesorter'><thead><th>Problem</th><th>Suggestion</th></thead>$errors</table>";
    }
    break;
  
}
?>