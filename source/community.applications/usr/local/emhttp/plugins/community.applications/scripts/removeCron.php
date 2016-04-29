#!/usr/bin/php
<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");

exec("crontab -l",$oldCronSettings);
  
foreach ($oldCronSettings as $oldCron) {
  if ( ! strpos($oldCron,$communityPaths['backupScript']) ) {
    $newCronSettings[] = $oldCron;
  }
}
$cronFile = randomFile();
file_put_contents($cronFile,implode("\n",$newCronSettings)."\n");
exec("crontab $cronFile");
unlink($cronFile);
  
?>

