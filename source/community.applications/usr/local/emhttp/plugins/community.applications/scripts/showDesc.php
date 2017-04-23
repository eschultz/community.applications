<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2017, Andrew Zawadzki #
#                                                             #
###############################################################
 
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");
require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

file_put_contents("/tmp/hello","hello");
$DockerTemplates = new DockerTemplates();

if ( is_dir("/var/lib/docker/containers") ) {
  $communitySettings['dockerRunning'] = "true";
} else {
  $communitySettings['dockerSearch'] = "no";
}
if ( $communitySettings['dockerRunning'] ) {
  $info = $DockerTemplates->getAllInfo();
  $DockerClient = new DockerClient();
  $dockerRunning = $DockerClient->getDockerContainers();
} else {
  $info = array();
  $dockerRunning = array();
}
$appNumber =  urldecode($_GET['appNumber']);
$appName = urldecode($_GET['appName']);
if ( ! $appNumber ) {
  $appNumber = $_POST['appNumber'];
  $color="<font color='white'>";
}

$file = readJsonFile($communityPaths['community-templates-info']);
$repos = readJsonFile($communityPaths['Repositories']);
if ( ! $repos ) {
  $repos = array();
}
$displayed = readJsonFile($communityPaths['community-templates-displayed']);

foreach ($file as $template) {
  if ( $template['ID'] == $appNumber ) {
    break;
  }
}

$repoIndex = searchArray($repos,"name",$template['RepoName']);
$webPageURL = $repos[$repoIndex]['web'];

$donatelink = $template['DonateLink'];
$donateimg = $template['DonateImg'];
$donatetext = $template['DonateText'];

$name = $template['Name'];

$template['Icon'] = $template['Icon'] ? $template['Icon'] : "/plugins/community.applications/images/question.png";
$template['Description'] = ltrim($template['Description']);

$templateDescription = "<style>p { margin-left:20px;margin-right:20px }</style>";
if ( $color ) {
  $templateDescription .= "<br><br><br>";
}
$templateDescription .= "<center><table><tr><td><figure style='margin:0px'><img id='icon' src='".$template['Icon']."' style='width:96px;height:96px' onerror='this.src=&quot;/plugins/community.applications/images/question.png&quot;';>";
$templateDescription .= ($template['Beta'] == "true") ? "<figcaption><font size='1' color='red'><center><strong>(beta)</strong></center></font></figcaption>" : "";
$templateDescription .= "</figure>";
$templateDescription .= "</td><td></td><td><table><tr><td>$color<strong>Author: </strong></td><td>$color".$template['Author']."</td></tr>";
$templateDescription .= "<tr><td>$color<strong>Repository: </strong></td><td>$color";
$templateDescription .= $template['Forum'] ? "<a href='".$template['Forum']."' target='_blank'>".$template['RepoName']."</a>" : $template['RepoName'];
$templateDescription .= "</td></tr>";
$templateDescription .= ($template['Private'] == "true") ? "<tr><td></td><td><font color=red>Private Repository</font></td></tr>" : "";
$templateDescription .= "<tr><td>$color<strong>Categories: </strong></td><td>$color".$template['Category']."</td></tr>";

$template['Base'] = $template['Plugin'] ? "$color<font color='red'>unRaid Plugin</font>" : $template['Base'];

if ( strtolower($template['Base']) == "unknown" ) {
  $template['Base'] = $template['BaseImage'];
}
if ( ! $template['Base'] ) {
  $template['Base'] = "Could Not Determine";
}

$templateDescription .= "<tr><td nowrap>$color<strong>Base OS: </strong></td><td>$color".$template['Base']."</td></tr>";
$templateDescription .= $template['stars'] ? "<tr><td nowrap>$color<strong>Star Rating: </strong></td><td>$color<img src='/plugins/community.applications/images/red-star.png' style='height:15px;width:15px'> ".$template['stars']."</td></tr>" : "";

if ( $template['Date'] ) {
  $niceDate = date("F j, Y",$template['Date']);
  $templateDescription .= "<tr><td nowrap>$color<strong>Date Updated: </strong></td><td>$color$niceDate</td></tr>";
}
$templateDescription .= $template['MinVer'] ? "<tr><td nowrap>$color<b>Minimum OS:</strong></td><td>{$color}unRaid v".$template['MinVer']."</td></tr>" : "";
$templateDescription .= $template['MaxVer'] ? "<tr><td nowrap>$color<strong>Max OS:</strong></td><td>{$color}unRaid v".$template['MaxVer']."</td></tr>" : "";
$templateDescription .= $template['downloads'] ? "<tr><td>$color<strong>Downloads:</strong></td><td>{$color}".$template['downloads']."</td></tr>" : "";
$templateDescription .= $template['Licence'] ? "<tr><td>$color<strong>Licence:</strong></td><td>$color".$template['Licence']."</td></tr>" : "";
  
$templateDescription .= "</table></td></tr></table></center>";
$templateDescription .= $template['Description'];
$templateDescription .= $template['ModeratorComment'] ? "<br><br><b><font color='red'>Moderator Comments:</font></b> ".$template['ModeratorComment'] : "";
$templateDescription .= "</p><br><center><table><tr>";
$templateDescription .= $template['Support'] ? "<td><a href='".$template['Support']."' target='_blank'><strong>Support Thread</strong></a></td>" : "";
$templateDescription .= $template['Project'] ? "<td></td><td><a href='".$template['Project']."' target='_blank'><strong>Project Page</strong></a></td>" : "";
$templateDescription .= $template['WebPageURL'] ? "<td></td><td><a href='".$template['WebPageURL']."' target='_blank'><strong>Web Page</strong></a></td>" : "";
$templateDescription .= "</tr></table><br><span id='script'></span>";

if ( ($donatelink) && ($donateimg) ) {
  $templateDescription .= "<br><center><font size='0'>$donatetext</font><br><a href='$donatelink' target='_blank'><img src='$donateimg' style='max-height:25px;'></a>";
  if ( $template['RepoName'] != "Squid's plugin Repository" ) {
    $templateDescription .= "<br><font size='0'>The above link is set by the author of the template, not the author of Community Applications</font></center>";
  }
}

echo "<div style='overflow:scroll; max-height:450px; height:450px; overflow-x:hidden; overflow-y:auto;'>";
echo $templateDescription;
echo "</div>";
?>
