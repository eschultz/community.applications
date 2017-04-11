<?
###############################################################
#                                                             #
# Community Applications copyright 2015-2017, Andrew Zawadzki #
#                                                             #
###############################################################
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");

switch ($_POST['action']) {
  
############################################################
#                                                          #
# Displays a table of basic stats for runnings docker apps #
#                                                          #
############################################################

case 'resourceMonitor':
  $sortOrder = getSortOrder(getPostArray('sortOrder'));
  $sortOrder['sortBy'] = $sortOrder['resourceKey'];  #move the key and dir to the appropriate value for the sort 
  $sortOrder['sortDir'] = $sortOrder['resourceDir'];
#get running containers

  if ( ! is_dir("/var/lib/docker/containers") ) {
    echo "Docker Not Running!";
    break;
  }
  if ( is_file($communityPaths['cAdvisor']) ) {
    $cAdvisorPath = file_get_contents($communityPaths['cAdvisor']);
  }
  $dockerClient = new DockerClient();
  $dockerRunning = $dockerClient->getDockerContainers();
  $templates = readJsonFile($communityPaths['community-templates-info']);

  foreach ($dockerRunning as $docker) {
    if ( ! $docker['Running'] ) {
      continue;
    }
    $runningTemplate = searchArray($templates,'Name',$docker['Name']);
    if ( $runningTemplate === false ) {
      $tempRepo = explode(":",$docker['Image']);
      $runningTemplate = searchArray($templates,'Repository',$tempRepo[0]);
      if ( $runningTemplate === false ) {
        $runningTemplate = searchArray($templates,'Repository',$docker['Image']);
      }
    }
    $container['Name'] = $docker['Name'];
    $container['ID'] = $docker['Id'];
    $container['Image'] = $docker['Image'];
    $container['NetworkMode'] = $docker['NetworkMode'];
    $containerID .= $docker['Id']." ";
    $container['Icon'] = $runningTemplate ? $templates[$runningTemplate]['Icon'] : "/plugins/community.applications/images/question.png";
    $running[] = $container;
  }

  $stats      = explode("\n",shell_exec("docker stats --no-stream=true $containerID"));
  $fullImages = explode("\n",shell_exec("docker ps --no-trunc"));

  unset($stats[0]);
  unset($fullImages[0]);

  $numCPU = shell_exec("nproc");

  foreach ( $stats as $line ) {
    $containerStats = explode("*",preg_replace("/  +/","*",$line));
    $container = searchArray($running,"ID",$containerStats[0]);
    if ( $container !== false ) {
      $display['Icon'] = $running[$container]['Icon'];
      $display['Name'] = $running[$container]['Name'];
      $display['CPU'] = round($containerStats[1] / $numCPU,2);
      $display['Memory'] = $containerStats[2];
      $display['MemPercent'] = str_replace("%","",$containerStats[3]);
      $display['IO'] = ( $running[$container]['NetworkMode'] == "host" ) ? "<em><font color='red'>Unable to determine</font></em>" : $containerStats[4];
      $display['ID'] = $running[$container]['ID'];
      $containerRepo = explode(":",$running[$container]['Image']);
      $imageSizes = explode("\n",shell_exec("docker images $containerRepo[0]"));
      $statsLine = explode(" ",preg_replace('!\s+!', ' ', $imageSizes[1]));
      $display['Repo'] = $running[$container]['Image'];
      $display['Size'] = $statsLine[6]." ".$statsLine[7];

# now get the full image id for cAdvisor usage
      if ( $cAdvisorPath ) {
        $display['Link'] = "";
        foreach ($fullImages as $ImagesLine) {
          if ( strpos($ImagesLine,$display['Name']) ) {
            $completeImage = explode(" ",$ImagesLine);
            $display['Link'] = $cAdvisorPath."/docker/".$completeImage[0];
            break;
          }
        }
      }
      $myDisplay[] = $display;
    }
  }
  if ( ! count($myDisplay) ) {
    echo "</table><center>No docker applications running!</center>";
    break;
  }
  usort($myDisplay,"mySort");

  foreach ($myDisplay as $display) {
    if ( $display['CPU'] > 80 ) {
      $display['CPU'] = "<font color=red>".$display['CPU']."</font>";
    }
    if ( $cAdvisorPath ) {
      $o .= "<tr><td><a href='".$display['Link']."' target='_blank' title='Click For Complete Details'><img src='".$display['Icon']."' width=48px ></a></td>";
    } else {
      $o .= "<tr><td><img src='".$display['Icon']."' width=48px></td>";
    }
    $o .= "<td>".$display['Name']."</td>";
    $o .= "<td>".$display['Repo']."</td>";
    $o .= "<td>".$display['CPU']." %</td>";
    $o .= "<td>".$display['Memory']."</td>";
    $o .= "<td>".$display['MemPercent']." %</td>";
    $o .= "<td>".$display['IO']."</td>";
    $o .= "<td>".$display['Size']."</td>";

    if ( is_file($communityPaths['appdataSize'].$display['ID']) ) {
      $o .= "<td>".file_get_contents($communityPaths['appdataSize'].$display['ID'])."</td>";
    } else {
      $o .= "<td><center><font color='red'>Unknown</font></center></td>";
    }
    $o .= "</tr>";
  }
  echo $o;
  break;

#################################################
#                                               #
# Initialization stuff for the resource monitor #
#                                               #
#################################################

case 'resourceInitialize':
  $dockerClient = new DockerClient();
  $dockerRunning = $dockerClient->getDockerContainers();

  $o = "<a href='AddContainer?xmlTemplate=default:/usr/local/emhttp/plugins/community.applications/xml/cadvisor.xml'>here</a> (This will install cAdvisor)<br> Note: when adding cAdvisor, do not change any of the volume mappings.  They are ALL correct as is.  Only change the HOST port if needed";
  $cadvisor = searchArray($dockerRunning,"Image","google/cadvisor:latest");

  if ( $cadvisor !== false ) {
    if ( $dockerRunning[$cadvisor]['Running'] ) {
      $cadvisorPort = $dockerRunning[$cadvisor]['Ports'][0]['PublicPort'];
      $unRaidVars = my_parse_ini_file($communityPaths['unRaidVars']);
      $unRaidIP = $unRaidVars['NAME'];
      $cAdvisorPath = "//$unRaidIP:$cadvisorPort";
      $o = "<a href='$cAdvisorPath' target='".$communitySettings['newWindow']."'>here</a> or click on the icon for the application";  
      file_put_contents($communityPaths['cAdvisor'],$cAdvisorPath);
    } else {
      $o = "<a onclick='startCadvisor();' style='cursor:pointer'>HERE</a> to start the cAdvisor application";
      @unlink($communityPaths['cAdvisor']);
    }
  } else {
    @unlink($communityPaths['cAdvisor']);
  }
  $o .= ( is_file($communityPaths['calculateAppdataProgress']) ) ? "<script>$('#calculateAppdata').prop('disabled',true);</script>" : "<script>$('#calculateAppdata').prop('disabled',false);</script>";

  echo $o;
  break;

####################################################
#                                                  #
# starts a script to calculate the size of appData #
#                                                  #
####################################################
  
case 'calculateAppdata':
  $commandLine = $communityPaths['calculateAppdataScript']." > /dev/null | at NOW -M >/dev/null 2>&1";
  exec($commandLine);
  break;

##############################################################
#                                                            #
# Enable / disable the calc appdata button if script running #
#                                                            #
##############################################################

case 'checkCalculations':
  $o .= ( is_file($communityPaths['calculateAppdataProgress']) ) ? "<script>$('#calculateAppdata').prop('disabled',true);</script>" : "<script>$('#calculateAppdata').prop('disabled',false);</script>";
  echo $o;
  break;

###############################################
#                                             #
# starts the cAdvisor app bypassing dockerMan #
#                                             #
###############################################

case 'startCadvisor':
  $dockerClient = new DockerClient();
  $dockerRunning = $dockerClient->getDockerContainers();

  $cadvisor = searchArray($dockerRunning,"Image","google/cadvisor:latest");
  
  shell_exec("docker start ".$dockerRunning[$cadvisor]['Name']);
  sleep (5);
  echo "done";
  break;
}
?>
