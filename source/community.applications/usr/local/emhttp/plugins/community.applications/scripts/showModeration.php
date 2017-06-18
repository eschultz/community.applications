<?PHP
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
$moderation = @file_get_contents($communityPaths['moderation']);
if ( ! $moderation ) {
  echo "<br><br><center><b>No moderation entries found</b></center>";
}
$moderation = str_replace(" ","&nbsp;",$moderation);
$moderation = str_replace("\n","<br>",$moderation);
echo "<tt>$moderation";
?>

