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
  $o = "";
}
#print_r($info);


$appNumber =  urldecode($_GET['appNumber']);

$file = readJsonFile($communityPaths['community-templates-info']);
$repos = readJsonFile($communityPaths['Repositories']);
if ( ! $repos ) {
  $repos = array();
}
$displayed = readJsonFile($communityPaths['community-templates-displayed']);

$templateIndex = searchArray($displayed['community'],"ID",$appNumber);
if ( $templateIndex === false ) {
  $templateIndex = searchArray($file,"ID",$appNumber);
  if ($templateIndex === false) {
    echo "An unidentified error has happened";
    exit;
  } else {
    $template = $file[$templateIndex];
  }
} else {
  $template = $displayed['community'][$templateIndex];
}
#$template = $file[$templateIndex];

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
      $unRaidVars = parse_ini_file($communityPaths['unRaidVars']);
      $unRaidIP = $unRaidVars['IPADDR'];
      $cAdvisorPath = "//$unRaidIP:$cadvisorPort/docker/".$fullImage[0];
      $o = "<a href='$cAdvisorPath' target='_blank'>More Details</a>";  
      file_put_contents($communityPaths['cAdvisor'],$cAdvisorPath);
    }
  } 
}

if ( ! $template['IconWeb']  ) {
  $template['IconWeb'] = "/plugins/community.applications/images/question.png";
}

$template['Description'] = ltrim($template['Description']);
$category = str_replace("UNCATEGORIZED", "uncategorized", $template['Category']);

$templateDescription = "<style>p { margin-left:20px;margin-right:20px }</style>";
$templateDescription .= "\n<center><table><tr><td><figure style='margin:0px'><img id='icon' src='".$template['IconWeb']."' style='width:96px;height:96px' onerror='this.src=&quot;/plugins/community.applications/images/question.png&quot;';>";

if ( $template['Beta'] == "true" ) {
  $templateDescription .= "<figcaption><font size='1' color='red'><center><strong>(beta)</strong></center></font></figcaption>";
}

$templateDescription .= "</figure>";
$templateDescription .= "</td><td></td><td><table><tr><td><strong>Author: </strong></td><td>".$template['Author']."</td></tr>";

$templateDescription .= "<tr><td><strong>Repository: </strong></td><td>";
if ( $template['Announcement'] ) {
  $templateDescription .= "<a href='".$template['Announcement']."' target='_blank'>".$template['RepoName']."</a>";
} else {
  $templateDescription .= $template['RepoName'];
}

$templateDescription .= "</td></tr>";

if ( $template['Private'] == "true" ) {
    $templateDescription .= "<tr><td></td><td><font color=red>Private Repository</font></td></tr>";
}
$templateDescription .= "<tr><td><strong>Categories: </strong></td><td>".$category."</td></tr>";

if ( $template['Plugin'] ) {
  $template['Base'] = "<font color='red'>unRaid Plugin</font>";
}
if ( strtolower($template['Base']) == "unknown" ) {
  $template['Base'] = $template['BaseImage'];
}
if ( ! $template['Base'] ) {
  $template['Base'] = "Could Not Determine";
}

$templateDescription .= "<tr><td nowrap><strong>Base OS: </strong></td><td>".$template['Base']."</td></tr>";

if ($template['Stars']) {
  $templateDescription .= "<tr><td nowrap><strong>Star Rating: </strong></td><td><img src='/plugins/community.applications/images/red-star.png' style='height:15px;width:15px'> ".$template['Stars']."</td></tr>";
}

if ( $template['Date'] ) {
  $niceDate = date("F j, Y",$template['Date']);
  $templateDescription .= "<tr><td nowrap><strong>Date Updated: </strong></td><td>$niceDate</td></tr>";
}

if ( $template['MinVer'] ) {
  $templateDescription .= "<tr><td nowrap><strong>Minimum OS:</strong></td><td>unRaid v".$template['MinVer']."</td></tr>";
}
if ( $template['MaxVer'] ) {
  $templateDescription .= "<tr><td nowrap><strong>Max OS:</strong></td><td>unRaid v".$template['MaxVer']."</td></tr>";
}

if ( $template['Downloads'] ) {
  $templateDescription .= "<tr><td><strong>Downloads:</strong></td><td>".$template['Downloads']."</td></tr>";
}
if ( $template['Licence'] ) {
  $templateDescription .= "<tr><td><strong>Licence:</strong></td><td>".$template['Licence']."</td></tr>";
}
  
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
$templateDescription .= "</table></td></tr></table></center>\n<strong><hr></strong><p>".$template['Description']."</p>";

if ( $template['ModeratorComment'] ) {
  $templateDescription .= "<br><b><font color='red'>Moderator Comments:</font></b> ".$template['ModeratorComment'];
}

$templateDescription .= "\n<center><table><tr>";
if ($template['Support']) {
  $templateDescription .= "<td><a href='".$template['Support']."' target='_blank'><strong>Support Thread</strong></a></td>";
}

if ( $template['Project'] ) {
  $templateDescription .= "<td></td><td><a href='".$template['Project']."' target='_blank'><strong>Project Page</strong></a></td>";
}

if ( $webPageURL ) {
  $templateDescription .= "<td></td><td><a href='$webPageURL' target='_blank'><strong>Web Page</strong></a></td>";
}

$templateDescription .= "</tr></table>\n<span id='script'></span>";

if ( ($donatelink) && ($donateimg) ) {
  $templateDescription .= "<br><center><font size='0'>$donatetext</font><br><a href='$donatelink' target='_blank'><img src='$donateimg' style='max-height:25px;'></a>";
  if ( $template['RepoName'] != "Squid's plugin Repository" ) {
    $templateDescription .= "<br><font size='0'>The above link is set by the author of the template, not the author of Community Applications</font></center>";
  }
}
$templateDescription .= "
  <script>
    document.getElementById('wait').innerHTML = '';
  </script>
";
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
