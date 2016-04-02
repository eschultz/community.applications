<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################
 
$imageID = $_POST['imageID'];

$rawStats = explode("\n",shell_exec("docker stats --no-stream=true $imageID"));
$statsLine = explode("*",preg_replace("/  +/","*",$rawStats[1]));
$numCPU = shell_exec("nproc");

$cpuPercent = round($statsLine[1] / $numCPU, 2);
$memory = $statsLine[2];
echo "
  <script>
    $('#percent').html('$cpuPercent%');
    $('#memory').html('$memory');
  </script>
";

?>
