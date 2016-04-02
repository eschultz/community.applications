<?
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################
 
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");

require_once 'webGui/include/Markdown.php';

  $appNumber = urldecode($_GET['appNumber']);

  if ( $appNumber == "CA" ) {
    $template['Changes'] = shell_exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin changes /tmp/plugins/community.applications.plg");
    $template['Plugin'] = true;
    $template['Support'] = "http://lime-technology.com/forum/index.php?topic=40262.0";
  } else {
    $file = readJsonFile($communityPaths['community-templates-info']);
    $templateIndex = searchArray($file,"ID",$appNumber);
    $repos = readJsonFile($communityPaths['community-templates-url']);
  
    if ($templateIndex === false) {
      echo "An unidentified error has happened";
      exit;
    }
    $template = $file[$templateIndex];
    $repoIndex = searchArray($repos,"name",$template['RepoName']);
    $webPageURL = $repos[$repoIndex]['web'];
  }
  if ( $template['Plugin'] )
  {
    $appInformation = Markdown($template['Changes']);
  } else {
    $appInformation = $template['Changes'];
    $appInformation = str_replace("\n","<br>",$appInformation);
    $appInformation = str_replace("[","<",$appInformation);
    $appInformation = str_replace("]",">",$appInformation);
  }
  $appInformation .= "<hr><center><table>";
  if ($template['Support']) {
    $appInformation .= "<tr><td><a href='".$template['Support']."' target='_blank'><strong>Support Thread</strong></a></td><td></td>";
  }

  if ( $template['Project'] ) {
    $appInformation .= "<td><a href='".$template['Project']."' target='_blank'><strong>Project Page</strong></a></td>";
  }

  if ( $webPageURL ) {
    $appInformation .= "<td></td><td><a href='$webPageURL' target='_blank'><strong>Web Page</strong></a></td>";
  }

  $appInformation .= "</tr></table>\n";

  echo $appInformation;
?>
