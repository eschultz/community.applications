<?
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################
 
echo "<span id='wait'><center>Gathering Information...</center></span><br>";

require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");
require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

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
$selected = $info[$name]['template'] && stripos($info[$name]['icon'], $template['Author']) !== false;

if ( $selected ) {
  $command = "docker ps -f name=".$template['Name']." --no-trunc";
  $fullImages = explode("\n",shell_exec($command));
  $fullImage = explode(" ",$fullImages[1]);
  $cadvisor = searchArray($dockerRunning,"Image","google/cadvisor:latest");

  if ( $cadvisor !== false ) {
    if ( $dockerRunning[$cadvisor]['Running'] ) {
      $cadvisorPort = $dockerRunning[$cadvisor]['Ports'][0]['PublicPort'];
      $unRaidVars = my_parse_ini_file($communityPaths['unRaidVars']);
      $unRaidIP = $unRaidVars['NAME'];
      $cAdvisorPath = "//$unRaidIP:$cadvisorPort/docker/".$fullImage[0];
      $o = "<a href='$cAdvisorPath' target='_blank'>More Details</a>";  
      file_put_contents($communityPaths['cAdvisor'],$cAdvisorPath);
    }
  } 
}

$template['Icon'] = $template['Icon'] ? $template['Icon'] : "/plugins/community.applications/images/question.png";
$template['Description'] = ltrim($template['Description']);

$templateDescription = "<style>p { margin-left:20px;margin-right:20px }</style>";
$templateDescription .= "\n<center><table><tr><td><figure style='margin:0px'><img id='icon' src='".$template['Icon']."' style='width:96px;height:96px' onerror='this.src=&quot;/plugins/community.applications/images/question.png&quot;';>";
$templateDescription .= ($template['Beta'] == "true") ? "<figcaption><font size='1' color='red'><center><strong>(beta)</strong></center></font></figcaption>" : "";
$templateDescription .= "</figure>";
$templateDescription .= "</td><td></td><td><table><tr><td><strong>Author: </strong></td><td>".$template['Author']."</td></tr>";
$templateDescription .= "<tr><td><strong>Repository: </strong></td><td>";
$templateDescription .= $template['Forum'] ? "<a href='".$template['Forum']."' target='_blank'>".$template['RepoName']."</a>" : $template['RepoName'];
$templateDescription .= "</td></tr>";
$templateDescription .= ($template['Private'] == "true") ? "<tr><td></td><td><font color=red>Private Repository</font></td></tr>" : "";
$templateDescription .= "<tr><td><strong>Categories: </strong></td><td>".$template['Category']."</td></tr>";

$template['Base'] = $template['Plugin'] ? "<font color='red'>unRaid Plugin</font>" : $template['Base'];

if ( strtolower($template['Base']) == "unknown" ) {
  $template['Base'] = $template['BaseImage'];
}
if ( ! $template['Base'] ) {
  $template['Base'] = "Could Not Determine";
}

$templateDescription .= "<tr><td nowrap><strong>Base OS: </strong></td><td>".$template['Base']."</td></tr>";
$templateDescription .= $template['stars'] ? "<tr><td nowrap><strong>Star Rating: </strong></td><td><img src='/plugins/community.applications/images/red-star.png' style='height:15px;width:15px'> ".$template['stars']."</td></tr>" : "";

if ( $template['Date'] ) {
  $niceDate = date("F j, Y",$template['Date']);
  $templateDescription .= "<tr><td nowrap><strong>Date Updated: </strong></td><td>$niceDate</td></tr>";
}
$templateDescription .= $template['MinVer'] ? "<tr><td nowrap><strong>Minimum OS:</strong></td><td>unRaid v".$template['MinVer']."</td></tr>" : "";
$templateDescription .= $template['MaxVer'] ? "<tr><td nowrap><strong>Max OS:</strong></td><td>unRaid v".$template['MaxVer']."</td></tr>" : "";
$templateDescription .= $template['downloads'] ? "<tr><td><strong>Downloads:</strong></td><td>".$template['downloads']."</td></tr>" : "";
$templateDescription .= $template['Licence'] ? "<tr><td><strong>Licence:</strong></td><td>".$template['Licence']."</td></tr>" : "";
  
if ( $selected ) {
  $result = searchArray($dockerRunning,'Name',$template['Name']);
   
  if ( $dockerRunning[$result]['Running'] ) {
    $imageID = $dockerRunning[$result]['Id'];
      
    $templateDescription .= "<tr><td nowrap><strong>% CPU:</strong></td><td><span id='percent'>Calculating</span></td></tr>";
    $templateDescription .= "<tr><td nowrap><strong>Memory:</strong></td><td><span id='memory'>Calculating</span></td></tr>";
    $templateDescription .= "<tr><td></td><td>$o</td></tr>";
  } else {
    $templateDescription .= "<tr><td nowrap><strong>% CPU:</strong></td><td>Not running</td></tr>";
    $templateDescription .= "<tr><td nowrap><strong>Memory:</strong></td><td>Not running</td></tr>";
  }
}
$templateDescription .= "</table></td></tr></table></center>\n<strong><hr></strong><p>".$template['Description'];
$templateDescription .= $template['ModeratorComment'] ? "<br><br><b><font color='red'>Moderator Comments:</font></b> ".$template['ModeratorComment'] : "";
$templateDescription .= "</p>\n<center><table><tr>";
$templateDescription .= $template['Support'] ? "<td><a href='".$template['Support']."' target='_blank'><strong>Support Thread</strong></a></td>" : "";
$templateDescription .= $template['Project'] ? "<td></td><td><a href='".$template['Project']."' target='_blank'><strong>Project Page</strong></a></td>" : "";
$templateDescription .= $template['WebPageURL'] ? "<td></td><td><a href='".$template['WebPageURL']."' target='_blank'><strong>Web Page</strong></a></td>" : "";
$templateDescription .= "</tr></table>\n<span id='script'></span>";

if ( ($donatelink) && ($donateimg) ) {
  $templateDescription .= "<br><center><font size='0'>$donatetext</font><br><a href='$donatelink' target='_blank'><img src='$donateimg' style='max-height:25px;'></a>";
  if ( $template['RepoName'] != "Squid's plugin Repository" ) {
    $templateDescription .= "<br><font size='0'>The above link is set by the author of the template, not the author of Community Applications</font></center>";
  }
}
$templateDescription .= "<script>document.getElementById('wait').innerHTML = '';</script>";
if ( $imageID ) {
  $templateDescription .= "
    <script src='/webGui/javascript/dynamix.js'></script>
    <script>
      var URL = '/plugins/community.applications/scripts/showDescExec.php';
      var Interval = setTimeout(updateStats,1000);        
     
      function updateStats() {
        $.post(URL,{imageID:'$imageID'},function(data) {
          if (data) {
            $('#script').html(data);
            Interval = setTimeout(updateStats,1000);
          }
        });
      }
    </script>
  ";
}
     
echo $templateDescription;
?>
