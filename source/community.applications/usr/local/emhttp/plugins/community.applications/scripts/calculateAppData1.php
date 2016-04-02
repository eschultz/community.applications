#!/usr/bin/php
<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################
 
require_once("/usr/local/emhttp/plugins/community.applications/include/paths.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/helpers.php");


require_once("/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php");
require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

$communitySettings = parse_plugin_cfg("community.applications");

shell_exec("mkdir -p ".$communityPaths['appdataSize']);
file_put_contents($communityPaths['calculateAppdataProgress'],getmypid());

$dockerClient = new DockerClient();
$dockerRunning = $dockerClient->getDockerContainers();
foreach ($dockerRunning as $docker) {
  $appdataPath = findAppdata($docker['Volumes']);
  
  if ( $appdataPath ) {
    file_put_contents($communityPaths['appdataSize'].$docker['Id'],"<center><em>Calculating</em></center>");
    $sizeRaw = explode("\t",shell_exec('du -s -b "'.$appdataPath.'"'));
    $size = "<center>".human_filesize($sizeRaw[0],2)."<br><font size='0'>( ".date("Y/m/d H:i")." )<br>$appdataPath";
  } else {
    $size = "<center><font color='red'>Could not determine appdata share</center>";
  }
  file_put_contents($communityPaths['appdataSize'].$docker['Id'],$size);
  
}

unlink($communityPaths['calculateAppdataProgress']);

?>
