<?PHP
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
$moderation = @file_get_contents($communityPaths['totalDeprecated_txt']);
if ( ! $moderation ) {
  echo "<br><br><center><b>No deprecated apps found</b></center>";
  return;
}
#$moderation = str_replace(" ","&nbsp;",$moderation);
#$moderation = str_replace("\n","<br>",$moderation);
echo "Deprecated Applications are able to still be installed if you have previously had them installed.  New installations of these applications are blocked unless you enable Display Deprecated Applications within CA's General Settings<br><br><tt>$moderation";
?>

