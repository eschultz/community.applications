#!/usr/bin/php
<?PHP

function checkPluginUpdate($filename) {
  $filename = basename($filename);
  $installedVersion = exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin version /var/log/plugins/$filename");
  if ( is_file("/tmp/plugins/$filename") ) {
    $upgradeVersion = exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin version /tmp/plugins/$filename");
  } else {
    $upgradeVersion = "0";
  }
  if ( $installedVersion < $upgradeVersion ) {
    return true;
  } else {
    return false;
  }
}
function notify($event,$subject,$description,$message="",$type="normal") {
  $command = '/usr/local/emhttp/plugins/dynamix/scripts/notify -e "'.$event.'" -s "'.$subject.'" -d "'.$description.'" -m "'.$message.'" -i "'.$type.'"';
  shell_exec($command);
}

$appList = json_decode(@file_get_contents("/boot/config/plugins/community.applications/AutoUpdate.json"),true);
if ( ! $appList ) {
  $appList['community.applications.plg'] = "true";
  $appList['fix.common.problems.plg'] = "true";
}

$pluginsInstalled = array_diff(scandir("/var/log/plugins"),array(".",".."));
exec("logger Community Applications Auto Update Running");
foreach ($pluginsInstalled  as $plugin) {
  if ( ! is_file("/boot/config/plugins/$plugin") ) {
    continue;
  }
  if ( $plugin == "unRAIDServer.plg" ) { continue; }
  if ( checkPluginUpdate($plugin) ) {
    if ( $appList['Global'] == "true" || $appList[$plugin] ) {
      exec("logger Auto Updating $plugin");
      exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin check $plugin");
      exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin update $plugin");
      if ( $appList['notify'] != "no" ) {
        notify("Community Applications","Application Auto Update",$plugin." Automatically Updated");
      }
    } else {
      exec("logger Update available for $plugin - Skipping Auto Update");
    }
  }
}
?>
