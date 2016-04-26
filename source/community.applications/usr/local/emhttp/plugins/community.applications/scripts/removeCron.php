#!/usr/bin/php
<?PHP
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
  
?>

