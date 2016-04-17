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

if ( ! is_file("/boot/config/plugins/community.applications/AutoUpdate.json") ) {
  exit;
}

$appList = json_decode(file_get_contents("/boot/config/plugins/community.applications/AutoUpdate.json"),true);

$pluginsInstalled = scandir("/boot/config/plugins");
exec("logger Community Applications Auto Update Running");
foreach ($pluginsInstalled  as $plugin) {
  if ( ! is_file("/boot/config/plugins/$plugin") ) {
    continue;
  }
  if ( checkPluginUpdate($plugin) ) {
    if ( $appList['Global'] == "true" || $appList[$plugin] ) {
      exec("logger Auto Updating $plugin");
      exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin check $plugin");
      exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin update $plugin");
    } else {
      exec("logger Update available for $plugin - Skipping Auto Update");
    }
  }
}
?>
