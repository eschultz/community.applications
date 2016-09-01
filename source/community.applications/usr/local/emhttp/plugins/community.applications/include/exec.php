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
require_once("/usr/local/emhttp/plugins/dynamix.plugin.manager/include/PluginHelpers.php");
require_once("/usr/local/emhttp/plugins/community.applications/include/xmlHelpers.php");

$plugin = "community.applications";
$DockerTemplates = new DockerTemplates();

################################################################################
#                                                                              #
# Set up any default settings (when not explicitely set by the settings module #
#                                                                              #
################################################################################

$communitySettings = parse_plugin_cfg("$plugin");

if ( $communitySettings['favourite'] != "None" ) {
  $officialRepo = str_replace("*","'",$communitySettings['favourite']);
  $separateOfficial = true;
}

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

###
# Set defaults for deprecated settings
###

$communitySettings['appFeed']    = "true";

exec("mkdir -p ".$communityPaths['tempFiles']);
exec("mkdir -p ".$communityPaths['persistentDataStore']);

if ( !is_dir($communityPaths['templates-community']) ) {
  exec("mkdir -p ".$communityPaths['templates-community']);
  @unlink($infoFile);
}

# Make sure the link is in place
if (is_dir("/usr/local/emhttp/state/plugins/$plugin")) exec("rm -rf /usr/local/emhttp/state/plugins/$plugin");
if (!is_link("/usr/local/emhttp/state/plugins/$plugin")) symlink($communityPaths['templates-community'], "/usr/local/emhttp/state/plugins/$plugin");

#################################################################
#                                                               #
# Functions used to download the templates from various sources #
#                                                               #
#################################################################

function DownloadCommunityTemplates() {
  global $communityPaths, $infoFile, $plugin, $communitySettings;

  $moderation = readJsonFile($communityPaths['moderation']);
  if ( ! is_array($moderation) ) {
      $moderation = array();
  }

  $DockerTemplates = new DockerTemplates();

  if (! $download = download_url($communityPaths['community-templates-url']) ) {
    return false;
  }
  $Repos  = json_decode($download, true);
  if ( ! is_array($Repos) ) {
    return false;
  }
  $appCount = 0;
  $myTemplates = array();

  if (file_exists($communityPaths['special-repos'])) {
    if ( $communitySettings['appFeed'] == "true" ) {
      $myTemplates = readJsonFile($communityPaths['community-templates-info']);
      $appCount = count($myTemplates);
      $Repos = readJsonFile($communityPaths['special-repos']);
    } else {
      $Repos = array_merge($Repos,readJsonFile($communityPaths['special-repos']));
    }
  }

  exec("rm -rf '{$communityPaths['templates-community']}'");
  @unlink($communityPaths['updateErrors']);

  $templates = array();
  foreach ($Repos as $downloadRepo) {
    $downloadURL = randomFile();
    file_put_contents($downloadURL, $downloadRepo['url']);
    $friendlyName = str_replace(" ","",$downloadRepo['name']);
    $friendlyName = str_replace("'","",$friendlyName);
    $friendlyName = str_replace('"',"",$friendlyName);
    $friendlyName = str_replace('\\',"",$friendlyName);
    $friendlyName = str_replace("/","",$friendlyName);

    if ( ! $downloaded = $DockerTemplates->downloadTemplates($communityPaths['templates-community']."/templates/$friendlyName", $downloadURL) ){
      file_put_contents($communityPaths['updateErrors'],$downloadRepo['name']." ".$downloadRepo['url']."<br>",FILE_APPEND);
      @unlink($downloadURL);
    } else {
      $templates = array_merge($templates,$downloaded);
      unlink($downloadURL);
    }
  }

  @unlink($downloadURL);
  $i = $appCount;
  foreach ($Repos as $Repo) {
    if ( ! is_array($templates[$Repo['url']]) ) {
      continue;
    }
    foreach ($templates[$Repo['url']] as $file) {
      if (is_file($file)){
        $o = readXmlFile($file);
        if ( ! $o['Repository'] ) {
          if ( ! $o['Plugin'] ) {
            continue;
          }
        }
        $o['Forum'] = $Repo['forum'];
        $o['RepoName'] = $Repo['name'];
        $o['ID'] = $i;
        $o['Support'] = $o['Support'] ? $o['Support'] : $o['Forum'];
        $o['DonateText'] = $o['DonateText'] ? $o['DonateText'] : $Repo['donatetext'];
        $o['DonateLink'] = $o['DonateLink'] ? $o['DonateLink'] : $Repo['donatelink'];
        $o['DonateImg'] = $o['DonateImg'] ? $o['DonateImg'] : $Repo['donateimg'];
        $o['WebPageURL'] = $Repo['web'];
        $o['Logo'] = $Repo['logo'];
        fixSecurity($o);
        $o = fixTemplates($o);
        $o['Compatible'] = versionCheck($o);

        # Overwrite any template values with the moderated values
        if ( is_array($moderation[$o['Repository']]) ) {
          $o = array_merge($o, $moderation[$o['Repository']]);
        }
        $o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
        $o['Category'] = str_replace("Status:Stable","",$o['Category']);
        $myTemplates[$i] = $o;
        $i = ++$i;
      }
    }
  }
  writeJsonFile($communityPaths['community-templates-info'],$myTemplates);

  return true;
}

#  DownloadApplicationFeed MUST BE CALLED prior to DownloadCommunityTemplates in order for private repositories to be merged correctly.

function DownloadApplicationFeed() {
  global $communityPaths, $infoFile, $plugin, $communitySettings;

  $moderation = readJsonFile($communityPaths['moderation']);
  if ( ! is_array($moderation) ) {
    $moderation = array();
  }

  $Repositories = readJsonFile($communityPaths['Repositories']);
  if ( ! $Repositories ) {
    $Repositories = array();
  }
  $downloadURL = randomFile();

  if ($download = download_url($communityPaths['application-feed'],$downloadURL) ){
    return false;
  }
  $ApplicationFeed  = readJsonFile($downloadURL);
  if ( ! is_array($ApplicationFeed) ) { return false; }

  unlink($downloadURL);
  $i = 0;

  $myTemplates = array();

  foreach ($ApplicationFeed['applist'] as $file) {
    if ( ! $file['Repository'] ) {
      if ( ! $file['Plugin'] ) {
      continue;
      }
    }
    unset($o);
    # Move the appropriate stuff over into a CA data file
    $o = $file;
    $o['ID']            = $i;
    $o['Author']        = preg_replace("#/.*#", "", $o['Repository']);
    $o['DockerHubName'] = strtolower($file['Name']);
    $o['RepoName']      = $file['Repo'];
    $o['SortAuthor']    = $o['Author'];
    $o['SortName']      = $o['Name'];
    $o['Licence']       = $file['License']; # Support Both Spellings
    $o['Licence']       = $file['Licence'];
    $o['Path']          = $communityPaths['templates-community']."/".$i.".xml";
    if ( $o['Plugin'] ) {
      $o['Author']        = $o['PluginAuthor'];
      $o['Repository']    = $o['PluginURL'];
      $o['Category']      .= " Plugins: ";
      $o['SortAuthor']    = $o['Author'];
      $o['SortName']      = $o['Name'];
    }
    $RepoIndex = searchArray($Repositories,"name",$o['RepoName']);
    if ( $RepoIndex != false ) {
      $o['DonateText'] = $Repositories[$RepoIndex]['donatetext'];
      $o['DonateImg']  = $Repositories[$RepoIndex]['donateimg'];
      $o['DonateLink'] = $Repositories[$RepoIndex]['donatelink'];
      $o['WebPageURL'] = $Repositories[$RepoIndex]['web'];
      $o['Logo']       = $Repositories[$RepoIndex]['logo'];
    }
    $o['DonateText'] = $file['DonateText'] ? $file['DonateText'] : $o['DonateText'];
    $o['DonateLink'] = $file['DonateLink'] ? $file['DonateLink'] : $o['DonateLink'];

    if ( ($file['DonateImg']) || ($file['DonateImage']) ) {  #because Sparklyballs can't read the tag documentation
      if ( $file['DonateImage'] ) {
        $o['DonateImg'] = $file['DonateImage'];
      } else {
        $o['DonateImg']     = $file['DonateImg'];
      }
    }
    # Apply various fixes to the templates for CA use
    fixSecurity($o);
    $o = fixTemplates($o);

# Overwrite any template values with the moderated values

    if ( is_array($moderation[$o['Repository']]) ) {
      $o = array_merge($o, $moderation[$o['Repository']]);
      $file = array_merge($file, $moderation[$o['Repository']]);
    }

    $o['Compatible'] = versionCheck($o);

# Update the settings for the template

    $file['Compatible'] = $o['Compatible'];
    $file['Beta'] = $o['Beta'];
    $file['MinVer'] = $o['MinVer'];
    $file['MaxVer'] = $o['MaxVer'];
    $file['Category'] = $o['Category'];
    $o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
    $o['Category'] = str_replace("Status:Stable","",$o['Category']);
    $myTemplates[$i] = $o;
    $templateXML = makeXML($file);
    file_put_contents($o['Path'],$templateXML);

    $i = ++$i;
  }
  writeJsonFile($communityPaths['community-templates-info'],$myTemplates);

  return true;
}

function getConvertedTemplates() {
  global $communityPaths, $infoFile, $plugin, $communitySettings;

# Start by removing any pre-existing private (converted templates)

  $templates = readJsonFile($communityPaths['community-templates-info']);

  foreach ($templates as $template) {
    if ( $template['Private'] ) {
      continue;
    } else {
      $myTemplates[] = $template;
    }
  }

  $appCount = count($myTemplates);

  $moderation = readJsonFile($communityPaths['moderation']);
  if ( ! is_array($moderation) ) {
    $moderation = array();
  }

  $i = $appCount;
  unset($Repos);

  if ( ! is_dir($communityPaths['convertedTemplates']) ) {
    return;
  }
  $Repos = array_diff(scandir($communityPaths['convertedTemplates']),array(".",".."));

  foreach ($Repos as $Repo) {
    if ( ! is_dir($communityPaths['convertedTemplates'].$Repo) ) {
      continue;
    }

    unset($privateTemplates);
    $repoPath = $communityPaths['convertedTemplates'].$Repo."/";

    $privateTemplates = array_diff(scandir($repoPath),array(".",".."));
    if ( empty($privateTemplates) ) {
      continue;
    }
    foreach ($privateTemplates as $template) {
      if ( strpos($template,".xml") === FALSE ) {
        continue;
      }
      if (is_file($repoPath.$template)) {
        $o = readXmlFile($repoPath.$template);
        $o = fixTemplates($o);
        $o['RepoName']     = $Repo." Repository";
        $o['ID']           = $i;
        $o['Date']         = ( $o['Date'] ) ? strtotime( $o['Date'] ) : 0;
        $o['SortAuthor']   = $o['Author'];
        $o['Private']      = "true";
        $o['Forum']        = "";
        $o['Compatible']   = versionCheck($o);
        
        fixSecurity($o);
        $myTemplates[$i]  = $o;
        $i = ++$i;
      }
    }
  }
  writeJsonFile($communityPaths['community-templates-info'],$myTemplates);

  return true;
}

############################################################
#                                                          #
# Routines that actually displays the template containers. #
#                                                          #
############################################################

function display_apps($viewMode) {
  global $communityPaths, $separateOfficial, $officialRepo, $communitySettings;

  $file = readJsonFile($communityPaths['community-templates-displayed']);
  $officialApplications = $file['official'];
  $communityApplications = $file['community'];
  $betaApplications = $file['beta'];
  $privateApplications = $file['private'];

  $totalApplications = count($officialApplications) + count($communityApplications) + count($betaApplications) + count($privateApplications);

  if ( $communitySettings['dockerRunning'] ) {
    $runningDockers=str_replace('/','',shell_exec('docker ps'));
    $imagesDocker=str_replace('/','',shell_exec('docker images'));
  }

  $display = "";
  $navigate = array();

  if ( $separateOfficial ) {
    if ( count($officialApplications) ) {
      $navigate[] = "doesn't matter what's here -> first element gets deleted anyways";
      $display = "<center><b>";

      $logos = readJsonFile($communityPaths['logos']);
      $display .= $logos[$officialRepo] ? "<img src='".$logos[$officialRepo]."' style='width:48px'>&nbsp;&nbsp;" : "";
      $display .= "<font size='4' color='purple' id='OFFICIAL'>$officialRepo</font></b></center><br>";
      $display .= my_display_apps($viewMode,$officialApplications,$runningDockers,$imagesDocker);
    }
  }

  if ( count($communityApplications) ) {
    if ( $communitySettings['superCategory'] == "true" || $separateOfficial ) {
      $navigate[] = "<a href='#COMMUNITY'>Community Supported Applications</a>";
      $display .= "<center><b><font size='4' color='purple' id='COMMUNITY'>Community Supported Applications</font></b></center><br>";
    }
    $display .= my_display_apps($viewMode,$communityApplications,$runningDockers,$imagesDocker);
  }

  if ( $communitySettings['superCategory'] == "true" || $separateOfficial ) {
    if ( count($betaApplications) ) {
      $navigate[] = "<a href='#BETA'>Beta Applications</a>";
      $display .= "<center><b><font size='4' color='purple' id='BETA'>Beta / Work In Progress Applications</font></b></center><br>";
      $display .= my_display_apps($viewMode,$betaApplications,$runningDockers,$imagesDocker);
    }
    if ( count($privateApplications) ) {
      $navigate[] = "<a href='#PRIVATE'>Private Applications</a>";
      $display .= "<center><b><font size='4' color='purple' id='PRIVATE'>Applications From Private Repositories</font></b></center><br>";
      $display .= my_display_apps($viewMode,$privateApplications,$runningDockers,$imagesDocker);
    }
  }

  unset($navigate[0]);

  if ( count($navigate) ) {
    $bookmark = "Jump To: ";
    $bookmark .= implode("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;",$navigate);
  }

  $display .= ( $totalApplications == 0 ) ? "<center><font size='3'>No Matching Content Found</font></center>" : "";
 
  $totalApps = "$totalApplications";
  $totalApps .= (count($privateApplications)) ? " <font size=1>( ".count($privateApplications)." Private )</font>" : "";

  $display .= "<script>$('#Total').html('$totalApps');</script>";
  $display .= changeUpdateTime();

  echo $bookmark;
  echo $display;
}

function my_display_apps($viewMode,$file,$runningDockers,$imagesDocker) {
  global $communityPaths, $info, $communitySettings, $plugin;

  $pinnedApps = getPinnedApps();
  $iconSize = $communitySettings['iconSize'];
  $tabMode = $communitySettings['newWindow'];

  usort($file,"mySort");

  $communitySettings['viewMode'] = $viewMode;

  $skin = readJsonFile($communityPaths['defaultSkin']);
  $ct = $skin[$viewMode]['header'].$skin[$viewMode]['sol'];
  $displayTemplate = $skin[$viewMode]['template'];
  if ( $viewMode == "detail" ) {
    $communitySettings['maxColumn'] = 2; 
    $communitySettings['viewMode'] = "icon";
  }

  $columnNumber = 0;
  foreach ($file as $template) {
    $name = $template['SortName'];
    $appName = str_replace(" ","",$template['SortName']);
    $t = "";
    $ID = $template['ID'];
    $selected = $info[$name]['template'] && stripos($info[$name]['icon'], $template['SortAuthor']) !== false;
    $selected = $template['Uninstall'] ? true : $selected;
    $RepoName = ( $template['Private'] == "true" ) ? $template['RepoName']."<font color=red> (Private)</font>" : $template['RepoName'];
    $template['display_DonateLink'] = $template['DonateLink'] ? "<font size='0'><a href='".$template['DonateLink']."' target='_blank' title='".$template['DonateText']."'>Donate To Author</a></font>" : "";
    $template['display_Project'] = $template['Project'] ? "<a target='_blank' title='Click to go the the Project Home Page' href='".$template['Project']."'><font color=red>Project Home Page</font></a>" : "";
    $template['display_Support'] = $template['Support'] ? "<a href='".$template['Support']."' target='_blank' title='Click to go to the support thread'><font color=red>Support Thread</font></a>" : "";
    $template['display_webPage'] = $template['WebPageURL'] ? "<a href='".$template['WebPageURL']."' target='_blank'><font color='red'>Web Page</font></a></font>" : "";

    if ( $template['display_Support'] && $template['display_Project'] ) { $template['display_Project'] = "&nbsp;&nbsp;&nbsp".$template['display_Project'];}
    if ( $template['display_webPage'] && ( $template['display_Project'] || $template['display_Support'] ) ) { $template['display_webPage'] = "&nbsp;&nbsp;&nbsp;".$template['display_webPage']; }
    if ( $template['UpdateAvailable'] ) {
      $template['display_UpdateAvailable'] = $template['Plugin'] ? "<br><center><font color='red'><b>Update Available.  Click <a onclick='installPLGupdate(&quot;".basename($template['MyPath'])."&quot;,&quot;".$template['Name']."&quot;);' style='cursor:pointer'>Here</a> to Install</b></center></font>" : "<br><center><font color='red'><b>Update Available.  Click <a href='Docker'>Here</a> to install</b></font></center>";
    }
    $template['display_ModeratorComment'] .= $template['ModeratorComment'] ? "</b></strong><font color='red'><b>Moderator Comments:</b></font> ".$template['ModeratorComment'] : "";
    $tempLogo = $template['Logo'] ? "<img src='".$template['Logo']."' height=20px>" : "";
    $template['display_Announcement'] = $template['Forum'] ? "<a href='".$template['Forum']."' target='_blank' title='Click to go to the repository Announcement thread' >$RepoName $tempLogo</a>" : "$RepoName $tempLogo";
    $template['display_Stars'] = $template['stars'] ? "<img src='/plugins/$plugin/images/red-star.png' style='height:15px;width:15px'> <strong>".$template['stars']."</strong>" : "";
    $template['display_Downloads'] = $template['downloads'] ? "<center>".$template['downloads']."</center>" : "<center>Not Available</center>";

    if ( $pinnedApps[$template['Repository']] ) {
      $pinned = "greenButton.png";
      $pinnedTitle = "Click to unpin this application";
    } else {
      $pinned = "redButton.png";
      $pinnedTitle = "Click to pin this application";
    }
    $template['display_pinButton'] = "<img src='/plugins/$plugin/images/$pinned' style='height:15px;width:15px;cursor:pointer' title='$pinnedTitle' onclick='pinApp(this,&quot;".$template['Repository']."&quot;);'>";
    if ( $template['Uninstall'] ) {
      $template['display_Uninstall'] = "<img src='/plugins/dynamix.docker.manager/images/remove.png' title='Uninstall Application' style='width:20px;height:20px;cursor:pointer' ";
      if ( $template['Plugin'] ) {
        $template['display_Uninstall'] .= "onclick='uninstallApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
      } else {
        $template['display_Uninstall'] .= "onclick='uninstallDocker(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
      }
    }
    $template['display_removable'] = $template['Removable'] ? "<img src='/plugins/dynamix.docker.manager/images/remove.png' title='Remove Application From List' style='width:20px;height:20px;cursor:pointer' onclick='removeApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>" : "";
    if ( $template['Date'] > strtotime($communitySettings['timeNew'] ) ) {
      $template['display_newIcon'] = "<img src='/plugins/$plugin/images/star.png' style='width:15px;height:15px;' title='New / Updated - ".date("F d Y",$template['Date'])."'></img>";
    }
    $template['display_changes'] = $template['Changes'] ? " <a style='cursor:pointer'><img src='/plugins/$plugin/images/information.png' onclick=showInfo($ID,'$appName'); title='Click for the changelog / more information'></a>" : "";
    $template['display_humanDate'] = date("F j, Y",$template['Date']);

    if ( $template['Plugin'] ) {
      $pluginName = basename($template['PluginURL']);
      if ( file_exists("/var/log/plugins/$pluginName") ) {
        $pluginSettings = getPluginLaunch($pluginName);
        $template['display_pluginSettings'] = $pluginSettings ? "<input type='submit' style='margin:0px' value='Settings' formtarget='$tabMode' formaction='$pluginSettings' formmethod='post'>" : "";
      } else {
        $buttonTitle = $template['MyPath'] ? "Reinstall Plugin" : "Install Plugin";
        $template['display_pluginInstall'] = "<input type='button' value='$buttonTitle' style='margin:0px' title='Click to install this plugin' onclick=installPlugin('".$template['PluginURL']."');>";
      }
    } else {
      if ( $communitySettings['dockerRunning'] ) {
        if ( $selected ) {
          $template['display_dockerDefault'] = "<input type='submit' value='Default' style='margin:1px' title='Click to reinstall the application using default values' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."'>";
          $template['display_dockerEdit']    = "<input type='submit' value='Edit' style='margin:1px' title='Click to edit the application values' formtarget='$tabMode' formmethod='post' formaction='UpdateContainer?xmlTemplate=edit:".addslashes($info[$name]['template'])."'>";
        } else {
          if ( $template['MyPath'] ) {
            $template['display_dockerReinstall'] = "<input type='submit' style='margin:0px' title='Click to reinstall the application' value='Reinstall' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=user:".addslashes($template['MyPath'])."'>";
          } else {
            $template['display_dockerInstall']   = "<input type='submit' style='margin:0px' title='Click to install the application' value='Add' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."'>";
          }
        }
      } else {
        $template['display_dockerDisable'] = "<font color='red'>Docker Not Enabled</font>";
      }
    }
    if ( ! $template['Compatible'] && ! $template['UnknownCompatible'] ) {
      $template['display_compatible'] = "NOTE: This application is listed as being NOT compatible with your version of unRaid<br>";
      $template['display_compatibleShort'] = "Incompatible";
    }
    $template['display_author'] = "<a style='cursor:pointer' onclick='authorSearch(this.innerHTML);' title='Search for more containers from author'>".$template['Author']."</a>";
    $displayIcon = $template['Icon'];
    $displayIcon = $displayIcon ? $displayIcon : "/plugins/$plugin/images/question.png";
    $template['display_iconSmall'] = "<a onclick='showDesc(".$template['ID'].",&#39;".$name."&#39;);' style='cursor:pointer'><img title='Click to display full description' src='".$displayIcon."' style='width:48px;height:48px;' onError='this.src=\"/plugins/$plugin/images/question.png\";'></a>";
    $template['display_iconSelectable'] = "<img src='$displayIcon' onError='this.src=\"/plugins/$plugin/images/question.png\";' style='width:".$iconSize."px;height=".$iconSize."px;'>";
    $template['display_popupDesc'] = ( $communitySettings['maxColumn'] > 2 ) ? "Click for a full description\n".$template['PopUpDescription'] : "Click for a full description";
    $template['display_dateUpdated'] = $template['Date'] ? "</b></strong><center><strong>Date Updated: </strong>".$template['display_humanDate']."</center>" : "";
    $template['display_iconClickable'] = "<a onclick=showDesc($ID,'$appName'); style='cursor:pointer' title='".$template['display_popupDesc']."'>".$template['display_iconSelectable']."</a>";

    if ( $communitySettings['dockerSearch'] == "yes" && ! $template['Plugin'] ) {
      $template['display_dockerName'] = "<a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search dockerHub for similar containers'>".$template['Name']."</a>";
    } else {
      $template['display_dockerName'] = $template['Name'];
    }
    $template['Category'] = ($template['Category'] == "UNCATEGORIZED") ? "Uncategorized" : $template['Category'];

    if ( ( $template['Beta'] == "true" ) ) {
      $template['display_dockerName'] .= "<span title='Beta Container &#13;See support forum for potential issues'><font size='1' color='red'><strong>(beta)</strong></font></span>";
    }

    $t .= vsprintf($displayTemplate,toNumericArray($template));

    $columnNumber=++$columnNumber;

    if ( $communitySettings['viewMode'] == "icon" ) {
      if ( $columnNumber == $communitySettings['maxColumn'] ) {
        $columnNumber = 0;
        $t .= $skin[$viewMode]['eol'].$skin[$viewMode]['sol'];
      }
    } else {
      $t .= $skin[$viewMode]['eol'].$skin[$viewMode]['sol'];
    }
 
    $ct .= $t;
  }
  $ct .= $skin[$viewMode]['footer'];
  return $ct;
}

#############################
#                           #
# Selects an app of the day #
#                           #
#############################

function appOfDay($file) {
  global $communityPaths;
  
  $oldAppDay = @filemtime($communityPaths['appOfTheDay']);
  if ( ! $oldAppDay ) {
    $oldAppDay = 1;
  }
  $oldAppDay = intval($oldAppDay / 86400);
  $currentDay = intval(time() / 86400);
  if ( $oldAppDay == $currentDay ) {
    $app = readJsonFile($communityPaths['appOfTheDay']);
    if ( $app ) {
      return $app;
    }
  }
  
  while ( true ) {
    $app[0] = mt_rand(0,count($file) -1);
    $app[1] = mt_rand(0,count($file) -1);
    if ($app[0] == $app[1]) continue;
    if ( ! $file[$app[0]]['Compatible'] || ! $file[$app[1]]['Compatible'] ) continue;
    if ( $file[$app[0]]['Blacklist'] || $file[$app[1]]['Blacklist'] ) continue;
    if ( $file[$app[0]]['ModeratorComment'] || $file[$app[1]]['ModeratorComment'] ) continue;
    break;
  }
  writeJsonFile($communityPaths['appOfTheDay'],$app);
  return $app;
}

##########################################################################
#                                                                        #
# function that comes up with alternate search suggestions for dockerHub #
#                                                                        #
##########################################################################

function suggestSearch($filter,$displayFlag) {
  $dockerFilter = str_replace("_","-",$filter);
  $dockerFilter = str_replace("%20","",$dockerFilter);
  $dockerFilter = str_replace("/","-",$dockerFilter);
  $otherSearch = explode("-",$dockerFilter);

  $returnSearch = "";

  if ( count($otherSearch) > 1 ) {
    $returnSearch .= "Suggested Searches: ";

    foreach ( $otherSearch as $suggestedSearch) {
      $returnSearch .= "<a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For $suggestedSearch'><font color='blue'>$suggestedSearch</font></a>&nbsp;&nbsp;&nbsp;&nbsp;";
    }
  } else {
    $otherSearch = preg_split('/(?=[A-Z])/',$dockerFilter);

    if ( count($otherSearch) > 1 ) {
      $returnSearch .= "Suggested Searches: ";

      foreach ( $otherSearch as $suggestedSearch) {
        if ( strlen($suggestedSearch) > 1 ) {
          $returnSearch .= "<a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For $suggestedSearch'><font color='blue'>$suggestedSearch</font></a>&nbsp;&nbsp;&nbsp;&nbsp;";
        } 
      }
    } else {
      if ( $displayFlag ) {
        $returnSearch .= "Suggested Searches: Unknown";
      }
    }
  }
  return $returnSearch;
}

########################################################################################
#                                                                                      #
# function used to display the navigation (page up/down buttons) for dockerHub results #
#                                                                                      #
########################################################################################

function dockerNavigate($num_pages, $pageNumber) {
  $returnValue = "";
  $returnValue .= "<center>";

  if ( $num_pages == 1 || $pageNumber == 1) {
    $returnValue .= "<img src='/plugins/community.applications/images/grey-left.png'>";
  } else {
    $returnValue .= "<a onclick='dockerSearch($pageNumber-1);' style='cursor:pointer' title='Previous Page'><img src='/plugins/community.applications/images/green-left.png'></a>";
  }
  $returnValue .= "<input type='range' max='$num_pages' min='1' id='enterPage' value='$pageNumber' onchange='dockerSearch(this.value);'>";

  if ( $number_pages == 1 || $pageNumber == $num_pages ) {
    $returnValue .= "<img src='/plugins/community.applications/images/grey-right.png'>";
  } else {
    $returnValue .= "<a onclick='dockerSearch($pageNumber+1);' style='cursor:pointer' title='Next Page'><img src='/plugins/community.applications/images/green-right.png'></a>";
  }
  $returnValue .= "</center>";
  $returnValue .= "<span style='float:right;position:relative;bottom:30px'><input type='button' value='Display Recommended' onclick='doSearch();'></span>";

  return $returnValue;
}

##############################################################
#                                                            #
# function that actually displays the results from dockerHub #
#                                                            #
##############################################################

function displaySearchResults($pageNumber,$viewMode) {
  global $communityPaths, $communitySettings, $plugin;

  $tempFile = readJsonFile($communityPaths['dockerSearchResults']);
  $num_pages = $tempFile['num_pages'];
  $file = $tempFile['results'];
  $templates = readJsonFile($communityPaths['community-templates-info']);

  echo dockerNavigate($num_pages,$pageNumber);
  echo "<br><br>";

  $iconSize = $communitySettings['iconSize'];
  $maxColumn = $communitySettings['maxColumn'];

  switch ($viewMode) {
    case "icon":
      $t = "<table>";
      break;
    case "table":
      $t =  "<table class='tablesorter'><thead><th></th><th></th><th>Container</th><th>Author</th><th>Stars</th><th>Description</th></thead>";
      $iconSize = 48;
      break;
    case "detail":
      $t = "<table class='tablesorter'>";
      $viewMode = "icon";
      $maxColumn = 2;
      break;
  }

  $column = 0;

  $t .= "<tr>";

  foreach ($file as $result) {
    $recommended = false;
    foreach ($templates as $template) {
      if ( $template['Repository'] == $result['Repository'] ) {
        $result['Description'] = $template['Description'];
        $result['Description'] = str_replace("'","&#39;",$result['Description']);
        $result['Description'] = str_replace('"',"&quot;",$result['Description']);
      }
    }
    $result['display_stars'] = $result['Stars'] ? "<img src='/plugins/$plugin/images/red-star.png' style='height:20px;width:20px'> <strong>".$result['Stars']."</strong>" : "";
    $result['display_official'] =  $result['Official'] ? "<strong><font color=red>Official</font> ".$result['Name']." container.</strong><br><br>": "";
    $result['display_official_short'] = $result['Official'] ? "<font color='red'><strong>Official</strong></font>" : "";

    if ( $viewMode == "icon" ) {
      $t .= "<td>";
      $t .= "<center>".$result['display_official_short']."</center>";

      $t .= "<center>Author: </strong><font size='3'><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Containers From Author'>".$result['Author']."</a></font></center>";
      $t .= "<center>".$result['display_stars']."</center>";

      $description = "Click to go to the dockerHub website for this container";
      if ( $result['Description'] ) {
        $description = $result['Description']."&#13;&#13;$description";
      }

      $t .= "<figure><center><a href='".$result['DockerHub']."' title='$description' target='_blank'>";
      $t .= "<img style='width:".$iconSize."px;height:".$iconSize."px;' src='".$result['Icon']."' onError='this.src=\"/plugins/$plugin/images/question.png\";'></a>";
      $t .= "<figcaption><strong><center><font size='3'><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For Similar Containers'>".$result['Name']."</a></font></center></strong></figcaption></figure>";
      $t .= "<center><input type='button' value='Add' onclick='dockerConvert(&#39;".$result['ID']."&#39;)' style='margin:0px'></center>";
      $t .= "</td>";

      if ( $maxColumn == 2 ) {
        $t .= "<td style='display:inline-block;width:350px;text-align:left;'>";
        $t .= "<br><br><br>";
        $t .= $result['display_official'];

        if ( $result['Description'] ) {
          $t .= "<strong><span class='desc_readmore' style='display:block'>".$result['Description']."</span></strong><br><br>";
        } else {
          $t .= "<em>Container Overview not available.</em><br><br>";
        }
        $t .= "Click container's icon for full description<br><br>";
        $t .= "</td>";
      }
      $column = ++$column;
      if ( $column == $maxColumn ) {
        $column = 0;
        $t .= "</tr><tr>";
      }
    }
    if ( $viewMode == "table" ) {
      $t .= "<tr><td><a href='".$result['DockerHub']."' target='_blank' title='Click to go to the dockerHub website for this container'>";
      $t .= "<img src='".$result['Icon']."' onError='this.src=\"/plugins/$plugin/images/question.png\";' style='width:".$iconSize."px;height:".$iconSize."px;'>";
      $t .= "</a></td>";
      $t .= "<td><input type='button' value='Add' onclick='dockerConvert(&#39;".$result['ID']."&#39;)';></td>";
      $t .= "<td><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Similar Containers'>".$result['Name']."</a></td>";
      $t .= "<td><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Containers From Author'>".$result['Author']."</a></td>";
      $t .= "<td>".$result['display_stars']."</td>";
      $t .= "<td>";
      $t .= $result['display_official'];
      $t .= "<strong><span class='desc_readmore' style='display:block'>".$result['Description']."</span></strong></td>";
      $t .= "</tr>";
    }
  }
  $t .= "</table>";
  echo $t;
  echo dockerNavigate($num_pages,$pageNumber);
  echo "<script>$('#pageNumber').html('(Page $pageNumber of $num_pages)');</script>";
}

############################################
############################################
##                                        ##
## BEGIN MAIN ROUTINES CALLED BY THE HTML ##
##                                        ##
############################################
############################################


switch ($_POST['action']) {

######################################################################################
#                                                                                    #
# get_content - get the results from templates according to categories, filters, etc #
#                                                                                    #
######################################################################################

case 'get_content':
  $filter   = getPost("filter",false);
  $category = "/".getPost("category",false)."/i";
  $newApp   = getPost("newApp",false);
  $sortOrder = getSortOrder(getPostArray("sortOrder"));

  $newAppTime = strtotime($communitySettings['timeNew']);

  $docker_repos = is_file($docker_repos) ? file($docker_repos,FILE_IGNORE_NEW_LINES) : array();

  if ( file_exists($communityPaths['addConverted']) ) {
    @unlink($infoFile);
    @unlink($communityPaths['addConverted']);
  }

  if ( file_exists($communityPaths['appFeedOverride']) ) {
   $communitySettings['appFeed'] = "false";
   @unlink($communityPaths['appFeedOverride']);
  }

  if (!file_exists($infoFile)) {
    if ( $communitySettings['appFeed'] == "true" ) {
      DownloadApplicationFeed();
      if (!file_exists($infoFile)) {
        $communitySettings['appFeed'] = "false";
        echo "<tr><td colspan='5'><br><center>Download of appfeed failed.  Reverting to legacy mode</center></td></tr>";
        @unlink($infoFile);
      } else {
        if ( file_exists($communityPaths['special-repos'] )) {
          DownloadCommunityTemplates();
        }
      }
    }

    if ($communitySettings['appFeed'] == "false" ) {
      if (!DownloadCommunityTemplates()) {
        echo "<table><tr><td colspan='5'><br><center>Download of source file has failed</center></td></tr></table>";
        break;
      } else {
        $lastUpdated['last_updated_timestamp'] = time();
        writeJsonFile($communityPaths['lastUpdated-old'],$lastUpdated);
        if (is_file($communityPaths['updateErrors'])) {
          echo "<table><td><td colspan='5'><br><center>The following repositories failed to download correctly:<br><br>";
          echo "<strong>".file_get_contents($communityPaths['updateErrors'])."</strong></center></td></tr></table>";
          break;
        }
      }
    }
  }
  getConvertedTemplates();

  $file = readJsonFile($communityPaths['community-templates-info']);
  if (!is_array($file)) break;

  if ( $category === "/NONE/i" ) {
    echo "<center><font size=4>Select A Category Above</font></center>";
    echo changeUpdateTime();
    $displayApplications = array();
    if ( count($file) > 200) {
      $appsOfDay = appOfDay($file);
      $displayApplications['community'] = array($file[$appsOfDay[0]],$file[$appsOfDay[1]]);
      writeJsonFile($communityPaths['community-templates-displayed'],$displayApplications);
      echo "<script>$('#templateSortButtons').hide();$('#sortButtons').hide();</script>";
      echo "<br><center><font size='4' color='purple'><b>Random Apps Of The Day</b></font><br><br>";
      echo my_display_apps("detail",$displayApplications['community'],$runningDockers,$imagesDocker);
      break;
    }
  }

  $display             = array();
  $official            = array();
  $beta                = array();
  $privateApplications = array();

  foreach ($file as $template) {
    if ( $template['Blacklist'] ) {
      continue;
    }
    if ( $communitySettings['hideIncompatible'] == "true" && ! $template['Compatible'] ) {
      continue;
    }
    $name = $template['Name'];

# Skip over installed containers

    if ( $newApp != "true" && $filter == "" && $communitySettings['separateInstalled'] == "true" ) {
      if ( $template['Plugin'] ) {
        $pluginName = basename($template['PluginURL']);

        if ( file_exists("/var/log/plugins/$pluginName") ) {
          continue;
        }
      } else {
        $selected = false;
        foreach ($dockerRunning as $installedDocker) {
          $installedImage = $installedDocker['Image'];
          $installedName = $installedDocker['Name'];

          if ( startsWith($installedImage,$template['Repository']) ) {
            if ( $installedName == $template['Name'] ) {
              $selected = true;
              break;
            }
          }
        }
        if ( $selected ) {
          continue;
        }
      }
    }
    if ( $template['Plugin'] ) {
      if ( file_exists("/var/log/plugins/".basename($template['PluginURL'])) ) {
        $template['UpdateAvailable'] = checkPluginUpdate($template['PluginURL']);
        $template['MyPath'] = $template['PluginURL'];
      }
    }
    if ( $newApp == "true" ) {
      if ( $template['Date'] < $newAppTime ) { continue; }
    }

    if ( $category && ! preg_match($category,$template['Category'])) { continue; }

    if ($filter) {
      if ( ! is_string($template['Name'])  ) $template['Name']=" ";
      if ( ! is_string($template['Author']) ) $template['Author']=" ";
      if ( ! is_string($template['Description']) ) $template['Description']=" ";

      if (preg_match("#$filter#i", $template['Name']) || preg_match("#$filter#i", $template['Author']) || preg_match("#$filter#i", $template['Description']) || preg_match("#$filter#i", $template['Repository'])) {
        $template['Description'] = highlight($filter, $template['Description']);
        $template['Author'] = highlight($filter, $template['Author']);
        $template['Name'] = highlight($filter, $template['Name']);
      } else continue;
    }

    if ( $communitySettings['superCategory'] == "true" ) {
      if ( $template['Beta'] == "true" ) {
        $beta[] = $template;
      } else {
        if ( $template['Private'] == "true" ) {
          $privateApplications[] = $template;
        } else {
          if ( $separateOfficial ) {
            if ( $template['RepoName'] == $officialRepo ) {
              $official[] = $template;
            } else {
              $display[] = $template;
            }
          } else {
            $display[] = $template;
          }
        }
      }
    } else {
      if ( $separateOfficial ) {
        if ( $template['RepoName'] == $officialRepo ) {
          $official[] = $template;
        } else {
          $display[] = $template;
        }
      } else {
        $display[] = $template;
      }
    }
  }

  $displayApplications['official']  = $official;
  $displayApplications['community'] = $display;
  $displayApplications['beta']      = $beta;
  $displayApplications['private']   = $privateApplications;

  writeJsonFile($communityPaths['community-templates-displayed'],$displayApplications);
  display_apps($sortOrder['viewMode']);
  changeUpdateTime();
  break;

########################################################
#                                                      #
# force_update -> forces an update of the applications #
#                                                      #
########################################################

case 'force_update':
  if ( !is_dir($communityPaths['templates-community']) ) {
    exec("mkdir -p ".$communityPaths['templates-community']);
    @unlink($infoFile);
  }

  download_url($communityPaths['moderationURL'],$communityPaths['moderation']);
  $tmpFileName = randomFile();
  download_url($communityPaths['community-templates-url'],$tmpFileName);
  $Repositories = readJsonFile($tmpFileName);
  writeJsonFile($communityPaths['Repositories'],$Repositories);
  $repositoriesLogo = readJsonFile($tmpFileName);
  if ( ! is_array($repositoriesLogo) ) {
    $repositoriesLogo = array();
  }

  foreach ($repositoriesLogo as $repositories) {
    if ( $repositories['logo'] ) {
      $repoLogo[$repositories['name']] = $repositories['logo'];
    }
  }
  writeJsonFile($communityPaths['logos'],$repoLogo);
  @unlink($tmpFileName);

  if ( ! file_exists($infoFile) ) {
    if ( ! file_exists($communityPaths['lastUpdated-old']) ) {
      $latestUpdate['last_updated_timestamp'] = time();
      writeJsonFile($communityPaths['lastUpdated-old'],$latestUpdate);
    }

    break;
  }

  if ( file_exists($communityPaths['lastUpdated-old']) ) {
    $lastUpdatedOld = readJsonFile($communityPaths['lastUpdated-old']);
  } else {
    $lastUpdatedOld['last_updated_timestamp'] = 0;
  }
  @unlink($communityPaths['lastUpdated']);
  download_url($communityPaths['application-feed-last-updated'],$communityPaths['lastUpdated']);

  $latestUpdate = readJsonFile($communityPaths['lastUpdated']);
  if ( ! $latestUpdate['last_updated_timestamp'] ) {
    $latestUpdate['last_updated_timestamp'] = INF;
    @unlink($communityPaths['lastUpdated']);
  }

  if ( $latestUpdate['last_updated_timestamp'] > $lastUpdatedOld['last_updated_timestamp'] ) {
    if ( $latestUpdate['last_updated_timestamp'] != INF ) {
      copy($communityPaths['lastUpdated'],$communityPaths['lastUpdated-old']);
    }
    unlink($infoFile);
  } else {
    moderateTemplates();
  }
  break;

####################################################################################################
#                                                                                                  #
# force_update_button - forces the system temporarily to override the appFeed and forces an update #
#                                                                                                  #
####################################################################################################

case 'force_update_button':
  file_put_contents($communityPaths['appFeedOverride'],"dunno");
  @unlink($infoFile);
  break;

####################################################################################
#                                                                                  #
# display_content - displays the templates according to view mode, sort order, etc #
#                                                                                  #
####################################################################################

case 'display_content':
  $sortOrder = getSortOrder(getPostArray('sortOrder'));
  
  if ( file_exists($communityPaths['community-templates-displayed']) ) {
    display_apps($sortOrder['viewMode']);
  } else {
    echo "<center><font size='4'>Select A Category Above</font></center>";
  }
  break;

########################################################################
#                                                                      #
# change_docker_view - called when the view mode for dockerHub changes #
#                                                                      #
########################################################################

case 'change_docker_view':
  $sortOrder = getSortOrder(getPostArray('sortOrder'));

  if ( ! file_exists($communityPaths['dockerSearchResults']) ) {
    break;
  }

  $file = readJsonFile($communityPaths['dockerSearchResults']);
  $pageNumber = $file['page_number'];
  displaySearchResults($pageNumber,$sortOrder['viewMode']);
  break;

#######################################################################
#                                                                     #
# convert_docker - called when system adds a container from dockerHub #
#                                                                     #
#######################################################################

case 'convert_docker':
  $dockerID = getPost("ID","");

  $file = readJsonFile($communityPaths['dockerSearchResults']);

  $docker = $file['results'][$dockerID];

  $docker['Description'] = str_replace("&", "&amp;", $docker['Description']);

  if ( ! $docker['Official'] ) {
    $dockerURL = $docker['DockerHub']."~/dockerfile/";

    download_url($dockerURL,$communityPaths['dockerfilePage']);

    $mystring = file_get_contents($communityPaths['dockerfilePage']);

    @unlink($communityPaths['dockerfilePage']);

    $thisstring = strstr($mystring,'"dockerfile":"');
    $thisstring = trim($thisstring);
    $thisstring = explode("}",$thisstring);
    $thisstring = explode(":",$thisstring[0]);
    unset($thisstring[0]);
    $teststring = implode(":",$thisstring);

    $teststring = str_replace('\n',"\n",$teststring);
    $teststring = str_replace("\u002F", "/", $teststring);
    $teststring = trim($teststring,'"');
    $teststring = stripslashes($teststring);
    $teststring = substr($teststring,2);

    $docker['Description'] = str_replace("&", "&amp;", $docker['Description']);

    $dockerFile = explode("\n",$teststring);

    $volumes = array();
    $ports = array();

    foreach ( $dockerFile as $dockerLine ) {
      $dockerCompare = trim(strtoupper($dockerLine));

      $dockerCmp = strpos($dockerCompare, "VOLUME");
      if ( $dockerCmp === 0 ) {
        $dockerLine = str_replace("'", " ", $dockerLine);
        $dockerLine = str_replace("[", " ", $dockerLine);
        $dockerLine = str_replace("]", " ", $dockerLine);
        $dockerLine = str_replace(",", " ", $dockerLine);
        $dockerLine = str_replace('"', " ", $dockerLine);

        $volumes[] = $dockerLine;
      }

      $dockerCmp = strpos($dockerCompare, "EXPOSE");
      if ( $dockerCmp === 0 ) {
        $dockerLine = str_replace("'", " ", $dockerLine);
        $dockerLine = str_replace("[", " ", $dockerLine);
        $dockerLine = str_replace("]", " ", $dockerLine);
        $dockerLine = str_replace(",", " ", $dockerLine);
        $dockerLine = str_replace('"', " ", $dockerLine);

        $ports[] = $dockerLine;
      }
    }

    $allVolumes = array();
    foreach ( $volumes as $volume ) {
      $volumeList = explode(" ", $volume);
      unset($volumeList[0]);

      foreach ($volumeList as $myVolume) {
        $allVolumes[] = $myVolume;
      }
    }

    $allPorts = array();
    foreach ( $ports as $port) {
      $portList = str_replace("/tcp", "", $port);
      $portList = explode(" ", $portList);
      unset($portList[0]);
      foreach ( $portList as $myPort ) {
        $allPorts[] = $myPort;
      }
    }
    
    $dockerfile['Name'] = $docker['Name'];
    $dockerfile['Support'] = $docker['DockerHub'];
    $dockerfile['Description'] = $docker['Description']."\n\n[b]Converted By Community Applications[/b]";
    $dockerfile['Overview'] = $dockerfile['Description'];
    $dockerfile['Registry'] = $dockerURL;
    $dockerfile['Repository'] = $docker['Repository'];
    $dockerfile['BindTime'] = "true";
    $dockerfile['Privileged'] = "false";
    $dockerfile['Networking']['Mode'] = "bridge";

    foreach ($allPorts as $addPort) {
      if ( strpos($addPort, "/udp") === FALSE ) {
        $dockerfileport['HostPort'] = $addPort;
        $dockerfileport['ContainerPort'] = $addPort;
        $dockerfileport['Protocol'] = "tcp";
        $webUI[] = $addPort;
        $dockerfile['Networking']['Publish']['Port'][] = $dockerfileport;
      } else {
        $addPort = str_replace("/udp","",$addPort);
        $dockerfileport['HostPort'] = $addPort;
        $dockerfileport['ContainerPort'] = $addPort;
        $dockerfileport['Protocol'] = "udp";
        $dockerfile['Networking']['Publish']['Port'][] = $dockerfileport;
      }
    }
    foreach ( $allVolumes as $addVolume ) {
      if ( ! $addVolume ) { continue; }
      $dockervolume['HostDir'] = "";
      $dockervolume['ContainerDir'] = $addVolume;
      $dockervolume['Mode'] = "rw";
      $dockerfile['Data']['Volume'][] = $dockervolume;
    }
    $dockerfile['Icon'] = $docker['Icon'];

    if ( count($webUI) == 1 ) {
      $dockerfile['WebUI'] .= "http://[IP]:[PORT:".$webUI[0]."]";
    }
    if ( count($webUI) > 1 ) {
      foreach ($webUI as $web) {
        if ( $web[0] == "8" ) {
          $webPort = $web;
        }
      }
      $dockerfile['WebUI'] .= "http://[IP]:[PORT:".$webPort."]";
    }
  } else {
# Container is Official.  Add it as such
    $dockerURL = $docker['DockerHub'];
    $dockerfile['Name'] = $docker['Name'];
    $dockerfile['Support'] = $docker['DockerHub'];
    $dockerfile['Overview'] = $docker['Description']."\n[b]Converted By Community Applications[/b]";
    $dockerfile['Description'] = $dockerfile['Overview'];
    $dockerfile['Registry'] = $dockerURL;
    $dockerfile['Repository'] = $docker['Repository'];
    $dockerfile['BindTime'] = "true";
    $dockerfile['Privileged'] = "false";
    $dockerfile['Networking']['Mode'] = "bridge";
    $dockerfile['Icon'] = $docker['Icon'];
  }
  $dockerXML = makeXML($dockerfile);

  $xmlFile = $communityPaths['convertedTemplates']."DockerHub/";
  if ( ! is_dir($xmlFile) ) {
    exec("mkdir -p ".$xmlFile);
  }
  $xmlFile .= str_replace("/","-",$docker['Repository']).".xml";
  file_put_contents($xmlFile,$dockerXML);
  file_put_contents($communityPaths['addConverted'],"Dante");
  echo $xmlFile;

  break;

#########################################################
#                                                       #
# search_dockerhub - returns the results from dockerHub #
#                                                       #
#########################################################

case 'search_dockerhub':
  $filter     = getPost("filter","");
  $pageNumber = getPost("page","1");
  $sortOrder  = getSortOrder(getPostArray('sortOrder'));
  
  $communityTemplates = readJsonFile($communityPaths['community-templates-info']);

  $filter = str_replace(" ","%20",$filter);

  $jsonPage = shell_exec("curl -s -X GET 'https://registry.hub.docker.com/v1/search?q=$filter\&page=$pageNumber'");

  $pageresults = json_decode($jsonPage,true);
  $num_pages = $pageresults['num_pages'];

  echo "<script>$('#Total').html(".$pageresults['num_results'].");</script>";

  if ($pageresults['num_results'] == 0) {
    echo "<center>No matching content found on dockerhub</center>";
    echo suggestSearch($filter,true);
    echo "<script>$('#dockerSearch').hide();$('#Total').html('0');</script>";
    @unlink($communityPaths['dockerSerchResults']);
    break;
  }

  $i = 0;

  foreach ($pageresults['results'] as $result) {
    unset($o);
    $o['Repository'] = $result['name'];
    $details = explode("/",$result['name']);
    $o['Author'] = $details[0];
    $o['Name'] = $details[1];
    $o['Description'] = $result['description'];
    $o['Automated'] = $result['is_automated'];
    $o['Stars'] = $result['star_count'];
    $o['Official'] = $result['is_official'];
    $o['Trusted'] = $result['is_trusted'];
    if ( $o['Official'] ) {
      $o['DockerHub'] = "https://hub.docker.com/_/".$result['name']."/";
      $o['Name'] = $o['Author'];
    } else {
      $o['DockerHub'] = "https://hub.docker.com/r/".$result['name']."/";
    }
    $o['ID'] = $i;
    $searchName = str_replace("docker-","",$o['Name']);
    $searchName = str_replace("-docker","",$searchName);
    $iconMatch = searchArray($communityTemplates,"DockerHubName",$searchName);
    if ( $iconMatch !== false) {
      $o['Icon'] = $communityTemplates[$iconMatch]['Icon'];
    }

    $dockerResults[$i] = $o;
    $i=++$i;
  }
  $dockerFile['num_pages'] = $num_pages;
  $dockerFile['page_number'] = $pageNumber;
  $dockerFile['results'] = $dockerResults;

  writeJsonFile($communityPaths['dockerSearchResults'],$dockerFile);
  echo suggestSearch($filter,false);
  displaySearchResults($pageNumber, $sortOrder['viewMode']);
  break;

#####################################################################
#                                                                   #
# dismiss_warning - dismisses the warning from appearing at startup #
#                                                                   #
#####################################################################

case 'dismiss_warning':
  file_put_contents("/boot/config/plugins/community.applications/accepted","warning dismissed");
  break;

###############################################################
#                                                             #
# Displays the list of installed or previously installed apps #
#                                                             #
###############################################################

case 'previous_apps':
  $installed = getPost("installed","");
  $dockerUpdateStatus = readJsonFile($communityPaths['dockerUpdateStatus']);
  
  if ( is_file($communityPaths['moderation']) ) {
    $moderation = readJsonFile($communityPaths['moderation']);
  } else {
    $moderation = array();
  }

  $DockerClient = new DockerClient();
  $info = $DockerClient->getDockerContainers();
  $file = readJsonFile($communityPaths['community-templates-info']);

# $info contains all installed containers

# now correlate that to a template;
# this section handles containers that have not been renamed from the appfeed
  if ( $installed == "true" ) {
    foreach ($info as $installedDocker) {
      $installedImage = $installedDocker['Image'];
      $installedName = $installedDocker['Name'];

      foreach ($file as $template) {
        if ( $installedName == $template['Name'] ) {
          $template['testrepo'] = $installedImage;
          if ( startsWith($installedImage,$template['Repository']) ) {
            $template['Uninstall'] = true;
            $template['MyPath'] = $template['Path'];
            if ( $dockerUpdateStatus[$installedImage]['status'] == "false" || $dockerUpdateStatus[$template['Name']] == "false" ) {
              $template['UpdateAvailable'] = true;
              $template['FullRepo'] = $installedImage;
            }
            $displayed[] = $template;
            break;
          }
        }
      }
    }
    $all_files = @array_diff(@scandir("/boot/config/plugins/dockerMan/templates-user"),array(".",".."));

# handle renamed containers
    foreach ($all_files as $xmlfile) {
      if ( pathinfo($xmlfile,PATHINFO_EXTENSION) == "xml" ) {
        $o = readXmlFile("/boot/config/plugins/dockerMan/templates-user/$xmlfile",$moderation);
        $o['MyPath'] = "/boot/config/plugins/dockerMan/templates-user/$xmlfile";
        $o['UnknownCompatible'] = true;

        if ( is_array($moderation[$o['Repository']]) ) {
          $o = array_merge($o, $moderation[$o['Repository']]);
        }
        $flag = false;
        $containerID = false;
        foreach ($file as $templateDocker) {
# use startsWith to eliminate any version tags (:latest)
          if ( startsWith($templateDocker['Repository'], $o['Repository']) ) {
            if ( $templateDocker['Name'] == $o['Name'] ) {
              $flag = true;
              $containerID = $template['ID'];
              break;
            }
          }
        }
        if ( ! $flag ) {
          $runningflag = false;
          foreach ($info as $installedDocker) {
            $installedImage = $installedDocker['Image'];
            $installedName = $installedDocker['Name'];

            if ( startsWith($installedImage, $o['Repository']) ) {
              if ( $installedName == $o['Name'] ) {
                $runningflag = true;
                $searchResult = searchArray($file,'Repository',$o['Repository']);
                if ( $searchResult !== false ) {
                  $tempPath = $o['MyPath'];
                  $containerID = $file[$searchResult]['ID'];
                  $o = $file[$searchResult];
                  $o['Name'] = $installedName;
                  $o['MyPath'] = $tempPath;
                  $o['SortName'] = $installedName;
                  if ( $dockerUpdateStatus[$installedImage]['status'] == "false" || $dockerUpdateStatus[$template['Name']] == "false" ) {
                    $o['UpdateAvailable'] = true;
                    $o['FullRepo'] = $installedImage;
                  }
                }
                break;;
              }
            }
          }
          if ( $runningflag ) {
            $o['Uninstall'] = true;
            $o['ID'] = $containerID;
            $displayed[] = $o;
          }
        }
      }
    }
  } else {
# now get the old not installed docker apps

    $all_files = @array_diff(@scandir("/boot/config/plugins/dockerMan/templates-user"),array(".",".."));

    foreach ($all_files as $xmlfile) {
      if ( pathinfo($xmlfile,PATHINFO_EXTENSION) == "xml" ) {
        $o = readXmlFile("/boot/config/plugins/dockerMan/templates-user/$xmlfile");
        $o['MyPath'] = "/boot/config/plugins/dockerMan/templates-user/$xmlfile";        
        $o['UnknownCompatible'] = true;
        $o['Removable'] = true;
# is the container running?

        $flag = false;
        foreach ($info as $installedDocker) {
          $installedImage = $installedDocker['Image'];
          $installedName = $installedDocker['Name'];

          if ( startsWith($installedImage, $o['Repository']) ) {
            if ( $installedName == $o['Name'] ) {
              $flag = true;
              continue;
            }
          }
        }
        if ( ! $flag ) {
# now associate the template back to a template in the appfeed
          foreach ($file as $appTemplate) {
            if ($appTemplate['Repository'] == $o['Repository']) {
              $tempPath = $o['MyPath'];
              $tempName = $o['Name'];
              $o = $appTemplate;
              $o['Removable'] = true;
              $o['MyPath'] = $tempPath;
              $o['Name'] = $tempName;
              break;
            }
          }
          $displayed[] = $o;
        }
      }
    }
  }

# Now work on plugins

  if ( $installed == "true" ) {
    foreach ($file as $template) {
      if ( ! $template['Plugin'] ) {
        continue;
      }
      $filename = pathinfo($template['Repository'],PATHINFO_BASENAME);

      if ( file_exists("/var/log/plugins/$filename") ) {
        $localURL = parse_url(plugin("pluginURL","/var/log/plugins/$filename"));
        $remoteURL = parse_url($template['PluginURL']);

        if ( $localURL['path'] == $remoteURL['path'] ) {
          $template['MyPath'] = "/var/log/plugins/$filename";
          $template['Uninstall'] = true;
          if ( checkPluginUpdate($filename) ) {
            $template['UpdateAvailable'] = true;
          }
          $displayed[] = $template;
       }
      }
    }
  } else {
    $all_plugs = @array_diff(@scandir("/boot/config/plugins-removed/"),array(".",".."));

    foreach ($all_plugs as $oldplug) {
      foreach ($file as $template) {
        if ( $oldplug == pathinfo($template['Repository'],PATHINFO_BASENAME) ) {
          if ( ! file_exists("/boot/config/plugins/$oldplug") ) {
            $template['Removable'] = true;
            $template['MyPath'] = "/boot/config/plugins-removed/$oldplug";
            $displayed[] = $template;
            break;
          }
        }
      }
    }
  }

  $displayedApplications['community'] = $displayed;
  writeJsonFile($communityPaths['community-templates-displayed'],$displayedApplications);
  echo "ok";
  break;

####################################################################################
#                                                                                  #
# Removes an app from the previously installed list (ie: deletes the user template #
#                                                                                  #
####################################################################################

case 'remove_application':
  $application = getPost("application","");
  @unlink($application);
  echo "ok";
  break;

#######################
#                     #
# Uninstalls a plugin #
#                     #
#######################

case 'uninstall_application':
  $application = getPost("application","");

  $filename = pathinfo($application,PATHINFO_BASENAME);
  shell_exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin remove '$filename'");
  echo "ok";
  break;

#######################
#                     #
# Uninstalls a docker #
#                     #
#######################

case 'uninstall_docker':
  $application = getPost("application","");

# get the name of the container / image
  $doc = new DOMDocument();
  $doc->load($application);
  $containerName  = stripslashes($doc->getElementsByTagName( "Name" )->item(0)->nodeValue);

  $DockerClient = new DockerClient();
  $dockerInfo = $DockerClient->getDockerContainers();
  $container = searchArray($dockerInfo,"Name",$containerName);

# stop the container

  shell_exec("docker stop $containerName");
  shell_exec("docker rm  $containerName");
  shell_exec("docker rmi ".$dockerInfo[$container]['ImageId']);

  $path = findAppdata($dockerInfo[$container]['Volumes']);
  if ( ! $path ) {
    $path = "***";
  }

  echo $path;
  break;

##################################
#                                #
# Deletes the appdata for an app #
#                                #
##################################

case 'remove_appdata':
  $appdata = getPost("appdata","");
  $appdata = trim($appdata);

  $commandLine = $communityPaths['deleteAppdataScript'].' "'.$appdata.'" > /dev/null | at NOW -M >/dev/null 2>&1';
  exec($commandLine);
  break;


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
    if ( $runningTemplate ) {
      $container['Icon'] = $templates[$runningTemplate]['Icon'];
    } else {
      $container['Icon'] = "/plugins/community.applications/images/question.png";
    }
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

      if ( $running[$container]['NetworkMode'] == "host" ) {
        $display['IO'] = "<em><font color='red'>Unable to determine</font></em>";
      } else {
        $display['IO'] = $containerStats[4];
      }
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
      $unRaidVars = parse_ini_file($communityPaths['unRaidVars']);
      $unRaidIP = $unRaidVars['IPADDR'];
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
  
#################################################
#                                               #
# Setup the json file for the cron autoupdating #
#                                               #
#################################################

case 'autoUpdatePlugins':
  $globalUpdate = getPost("globalUpdate","no");
  $pluginList   = getPost("pluginList","");

  $updateArray['Global'] = ( $globalUpdate == "yes" ) ? "true" : "false";

  $plugins = explode("*",$pluginList);
  if ( is_array($plugins) ) {
    foreach ($plugins as $plg) {
      if (is_file("/var/log/plugins/$plg") ) {
        $updateArray[$plg] = "true";
      }
    }
  }
  writeJsonFile($communityPaths['autoUpdateSettings'],$updateArray);
  break;

#########################################
#                                       #
# Displays the orphaned appdata folders #
#                                       #
#########################################

case 'getOrphanAppdata':
  $all_files = @array_diff(@scandir("/boot/config/plugins/dockerMan/templates-user"),array(".",".."));
  if ( is_dir("/var/lib/docker/tmp") ) {
    $DockerClient = new DockerClient();
    $info = $DockerClient->getDockerContainers();
  } else {
    $info = array();
  }

  # Get the list of appdata folders used by all of the my* templates
  
  foreach ($all_files as $xmlfile) {
    if ( pathinfo($xmlfile,PATHINFO_EXTENSION) == "xml" ) {
      $o = XML2Array::createArray(file_get_contents("/boot/config/plugins/dockerMan/templates-user/$xmlfile"));
      reset($o);
      $first_key = key($o);
      $o = $o[$first_key]; # get the name of the first key (root of the xml)
      if ( isset($o['Data']['Volume']) ) {
        if ( $o['Data']['Volume'][0] ) {
          $volumes = $o['Data']['Volume'];
        } else {
          unset($volumes);
          $volumes[] = $o['Data']['Volume'];
        }
        foreach ( $volumes as $volumeArray ) {
          $volumeList[0] = $volumeArray['HostDir'].":".$volumeArray['ContainerDir'];
          if ( findAppdata($volumeList) ) {
            $temp['Name'] = $o['Name'];
            $temp['HostDir'] = $volumeArray['HostDir'];
            $availableVolumes[$volumeArray['HostDir']] = $temp;
          }
        }
      } 
    }
  }

  # remove from the list the folders used by installed docker apps
  
  foreach ($info as $installedDocker) {
    if ( ! is_array($installedDocker['Volumes']) ) {
      continue;
    }
     foreach ($installedDocker['Volumes'] as $volume) {
       $folders = explode(":",$volume);
       $cacheFolder = str_replace("/mnt/user/","/mnt/cache/",$folders[0]);
       $userFolder = str_replace("/mnt/cache/","/mnt/user/",$folders[0]);
       unset($availableVolumes[$cacheFolder]);
       unset($availableVolumes[$userFolder]);
     }
  }
  
  # remove from list any folders which don't actually exist
  
  $temp = $availableVolumes;
  foreach ($availableVolumes as $volume) {
    $userFolder = str_replace("/mnt/cache/","/mnt/user/",$volume['HostDir']);
    
    if ( ! is_dir($userFolder) ) {
      unset($temp[$volume['HostDir']]);
    }
  }
  $availableVolumes = $temp;

  # remove from list any folders which are equivalent 
  $tempArray = $availableVolumes;
  foreach ( $availableVolumes as $volume ) {
    $flag = false;
    foreach ( $availableVolumes as $testVolume ) {
      if ( $testVolume['HostDir'] == $volume['HostDir'] ) {
        continue; # ie: its the same index in the array;
      }
     $cacheFolder = str_replace("/mnt/user/","/mnt/cache/",$volume['HostDir']);
     $userFolder = str_replace("/mnt/cache/","/mnt/user/",$volume['HostDir']);
      if ( startswith($testVolume['HostDir'],$cacheFolder) || startsWith($testVolume['HostDir'],$userFolder) ) {
        $flag = true;
        break;
      }
    }
    if ( $flag ) {
      unset($tempArray[$volume['HostDir']]);
    }
  }
  $availableVolumes = $tempArray;
  
  foreach ($tempArray as $testVolume) {
    if ( ! $installedDocker['Volumes'] ) {
      continue;
    }
    foreach ($installedDocker['Volumes'] as $volume) {
      $folders = explode(":",$volume);
      $cacheFolder = str_replace("/mnt/user/","/mnt/cache/",$folders[0]);
      $userFolder = str_replace("/mnt/cache/","/mnt/user/",$folders[0]);
      if ( startswith($cacheFolder,$testVolume['HostDir']) || startsWith($userFolder,$testVolume['HostDir']) ) {
        unset($availableVolumes[$testVolume['HostDir']]);
      }
    }
  }
  
  if ( empty($availableVolumes) ) {
    echo "No orphaned appdata folders found <script>$('#selectAll').prop('disabled',true);</script>";
  } else {
    foreach ($availableVolumes as $volume) {
      echo "<input type='checkbox' class='appdata' value='".$volume['HostDir']."' onclick='$(&quot;#deleteButton&quot;).prop(&quot;disabled&quot;,false);'>".$volume['Name'].":  <b>".$volume['HostDir']."</b><br>";
    }
  }
  break;
  
########################################
#                                      #
# Deletes the selected appdata folders #
#                                      #
########################################

case "deleteAppdata":
  $paths = getPost("paths","no");
  $paths = explode("*",$paths);
  foreach ($paths as $path) {
    $userPath = str_replace("/mnt/cache/","/mnt/user/",$path);
    exec ("rm -rf ".escapeshellarg($userPath));
  }
  echo "deleted";
  break;
  
##################################################
#                                                #
# Pins / Unpins an application for later viewing #
#                                                #
##################################################

case "pinApp":
  $repository = getPost("repository","oops");
  
  $pinnedApps = readJsonFile($communityPaths['pinned']);
  if ( $pinnedApps[$repository] ) {
    unset($pinnedApps[$repository]);
  } else {
    $pinnedApps[$repository] = $repository;
  }
  writeJsonFile($communityPaths['pinned'],$pinnedApps);
  writeJsonFile($communityPaths['pinnedRam'],$pinnedApps);
  break;
  
####################################
#                                  #
# Displays the pinned applications #
#                                  #
####################################

case "pinnedApps":
  $pinnedApps = getPinnedApps();
  $file = readJsonFile($communityPaths['community-templates-info']);

  foreach ($pinnedApps as $pinned) {
    $index = searchArray($file,"Repository",$pinned);
    if ( $index === false ) {
      continue;
    } else {
      $displayed[] = $file[$index];
    }
  }
  $displayedApplications['community'] = $displayed;
  $displayedApplications['pinnedFlag']  = true;
  writeJsonFile($communityPaths['community-templates-displayed'],$displayedApplications);  
  echo "fini!";
  break;  
}
?>
