#!/usr/bin/php
<?PHP
require_once("/usr/local/emhttp/plugins/dynamix.plugin.manager/include/PluginHelpers.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");

function checkPluginUpdate($filename) {
  global $unRaidVersion;
  
  $filename = basename($filename);
  $installedVersion = plugin("version","/var/log/plugins/$filename");
  if ( is_file("/tmp/plugins/$filename") ) {
    $upgradeVersion = plugin("version","/tmp/plugins/$filename");
  } else {
    $upgradeVersion = "0";
  }
  exec("logger $installedVersion");
  if ( $installedVersion < $upgradeVersion ) {
    $unRaid = plugin("unRAID","/tmp/plugins/$filename");
    if ( $unRaid === false || version_compare($unRaidVersion['version'],$unRaid,">=") ) {
      return true;
    } else {
      return false;
    }
  }
  return false;
}

function notify($event,$subject,$description,$message="",$type="normal") {
  $command = '/usr/local/emhttp/plugins/dynamix/scripts/notify -e "'.$event.'" -s "'.$subject.'" -d "'.$description.'" -m "'.$message.'" -i "'.$type.'"';
  shell_exec($command);
}
$unRaidVersion = parse_ini_file("/etc/unraid-version");
$appList = json_decode(@file_get_contents("/boot/config/plugins/community.applications/AutoUpdate.json"),true);
if ( ! $appList ) {
  $appList['community.applications.plg'] = "true";
  $appList['fix.common.problems.plg'] = "true";
}

$pluginsInstalled = array("community.applications.plg") + array_diff(scandir("/var/log/plugins"),array(".","..","community.applications.plg"));
exec("logger Community Applications Auto Update Running");
foreach ($pluginsInstalled  as $plugin) {
  if ( is_file($communityPaths['autoUpdateKillSwitch']) ) {
    exec("logger Auto Update Kill Switch Activated.  Most likely details why on the forums");
    notify("Community Applications","AutoUpdate Kill Switch has been activated.  See Forum for details","","error");
    break;
  }
  if ( ! is_file("/boot/config/plugins/$plugin") ) {
    continue;
  }
  if ( $plugin == "unRAIDServer.plg" ) { continue; }
  if ( checkPluginUpdate($plugin) ) {
    if ( $appList['Global'] == "true" || $appList[$plugin] ) {
      exec("logger Auto Updating $plugin");
      exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin check '$plugin'");
      exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin update '$plugin'");
      if ( $appList['notify'] != "no" ) {
        notify("Community Applications","Application Auto Update",$plugin." Automatically Updated");
      }
    } else {
      exec("logger Update available for $plugin - Skipping Auto Update");
    }
  }
}
?>
