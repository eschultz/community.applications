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
$unRaidSettings = parse_ini_file($communityPaths['unRaidVersion']);
$unRaidVersion = $unRaidSettings['version'];

if ( ! $communitySettings['timeNew'] )           { $communitySettings['timeNew'] = "-3 Months"; }
if ( ! $communitySettings['maxColumn'] )         { $communitySettings['maxColumn'] = 5; }
if ( ! $communitySettings['viewMode'] )          { $communitySettings['viewMode'] = "detail"; }
if ( ! $communitySettings['iconSize'] )          { $communitySettings['iconSize'] = "96"; }
if ( ! $communitySettings['dockerSearch'] )      { $communitySettings['dockerSearch'] = "no"; }
if ( ! $communitySettings['superCategory'] )     { $communitySettings['superCategory'] = "true"; }
if ( ! $communitySettings['newWindow'] )         { $communitySettings['newWindow'] = "_self"; }
if ( ! $communitySettings['hideIncompatible'] )  { $communitySettings['hideIncompatible'] = "true"; }
if ( ! $communitySettings['favourite'] )         { $communitySettings['favourite'] = "None"; }
if ( ! $communitySettings['separateInstalled'] ) { $communitySettings['separateInstalled'] = "true"; }

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

if ( !is_dir($communityPaths['tempFiles']) ) {
  exec("mkdir -p ".$communityPaths['tempFiles']);
}

if ( !is_dir($communityPaths['persistentDataStore']) ) {
  exec("mkdir -p ".$communityPaths['persistentDataStore']);
}

if ( !is_dir($communityPaths['templates-community']) ) {
  exec("mkdir -p ".$communityPaths['templates-community']);
  @unlink($infoFile);
}

$iconSize = $communitySettings['iconSize'];

# Make sure the link is in place
if (is_dir("/usr/local/emhttp/state/plugins/$plugin")) exec("rm -rf /usr/local/emhttp/state/plugins/$plugin");
if (!is_link("/usr/local/emhttp/state/plugins/$plugin")) symlink($communityPaths['templates-community'], "/usr/local/emhttp/state/plugins/$plugin");


#################################################################
#                                                               #
# Functions used to download the templates from various sources #
#                                                               #
#################################################################

function DownloadCommunityTemplates() {
  global $communityPaths, $infoFile, $DockerTemplates, $plugin, $communitySettings, $unRaidVersion;

  if ( is_file($communityPaths['moderation']) ) {
    $moderation = readJsonFile($communityPaths['moderation']);
    if ( ! is_array($moderation) ) {
      $moderation = array();
    }
  } else {
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
  file_put_contents("/tmp/debug",print_r($templates,1));
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
        $o['Announcement'] = $Repo['forum'];
        $o['RepoName'] = $Repo['name'];
        $o['ID'] = $i;
        if (!$o['Support']) {
          $o['Support'] = $o['Announcement'];
        }
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
  global $communityPaths, $infoFile, $DockerTemplates, $plugin, $communitySettings, $unRaidVersion;

  if ( file_exists($communityPaths['moderation']) ) {
    $moderation = readJsonFile($communityPaths['moderation']);
    if ( ! is_array($moderation) ) {
      $moderation = array();
    }
  } else {
    $moderation = array();
  }

  $downloadURL = randomFile();

  if ($download = download_url($communityPaths['application-feed'],$downloadURL) ){
    return false;
  }
  $ApplicationFeed  = readJsonFile($downloadURL);

  if ( ! is_array($ApplicationFeed) ) {
    return false;
  }

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
    $o['Path']          = $file['Path'];
    $o['Repository']    = $file['Repository'];
    $o['Author']        = preg_replace("#/.*#", "", $o['Repository']);
    $o['Name']          = $file['Name'];
    $o['DockerHubName'] = strtolower($file['Name']);
    $o['Beta']          = $file['Beta'];
    $o['Changes']       = $file['Changes'];
    $o['Date']          = $file['Date'];
    $o['RepoName']      = $file['Repo'];
    $o['Project']       = $file['Project'];
    $o['ID']            = $i;
    $o['Base']          = $file['Base'];
    $o['SortAuthor']    = $o['Author'];
    $o['SortName']      = $o['Name'];
    $o['Licence']       = $file['License']; # Support Both Spellings
    $o['Licence']       = $file['Licence'];

    $o['Plugin']        = $file['Plugin'];
    $o['PluginURL']     = $file['PluginURL'];
    $o['PluginAuthor']  = $file['PluginAuthor'];
    $o['MinVer']        = $file['MinVer'];
    $o['MaxVer']        = $file['MaxVer'];
    $o['Category']      = $file['Category'];
    $o['Description']   = $file['Description'];
    $o['Overview']      = $file['Overview'];
    $o['Downloads']     = $file['downloads'];
    $o['Stars']         = $file['stars'];
    $o['Announcement']  = $file['Forum'];
    $o['Support']       = $file['Support'];
    $o['IconWeb']       = $file['Icon'];
    $o['Path']          = $communityPaths['templates-community']."/".$i.".xml";
    if ( $o['Plugin'] ) {
      $o['Author']        = $o['PluginAuthor'];
      $o['Repository']    = $o['PluginURL'];
      $o['Category']      .= " Plugins: ";
      $o['SortAuthor']    = $o['Author'];
      $o['SortName']      = $o['Name'];
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
  global $communityPaths, $infoFile, $DockerTemplates, $plugin, $communitySettings, $unRaidVersion;

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

  if ( is_file($communityPaths['moderation']) ) {
    $moderation = readJsonFile($communityPaths['moderation']);
    if ( ! is_array($moderation) ) {
      $moderation = array();
    }
  } else {
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

      $file = $repoPath.$template;

      if (is_file($file)) {
        $o = readXmlFile($file);
        $o = fixTemplates($o);
        $o['RepoName']     = $Repo." Repository";
        $o['ID']           = $i;
        $o['Date']         = ( $o['Date'] ) ? strtotime( $o['Date'] ) : 0;
        $o['SortAuthor']   = $o['Author'];
        $o['Private']      = "true";
        $o['Announcement'] = "";
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

      if ( is_file($communityPaths['logos']) ) {
        $logos = readJsonFile($communityPaths['logos']);

        if ( $logos[$officialRepo] ) {
          $display .= "<img src='".$logos[$officialRepo]."' style='width:48px'>&nbsp;&nbsp;";
        }
      }

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

  if ( $totalApplications == 0 ) {
    $display .= "<center><font size='3'>No Matching Content Found</font></center>";
  }

  $totalApps = "$totalApplications";

  if ( count($privateApplications) ) {
    $totalApps .= " <font size=1>( ".count($privateApplications)." Private )</font>";
  }

  $display .= "<script>$('#Total').html('$totalApps');</script>";
  $display .= changeUpdateTime();

  echo $bookmark;
  echo $display;
}

function my_display_apps($viewMode,$file,$runningDockers,$imagesDocker) {
  global $communityPaths, $info, $communitySettings, $plugin, $iconSize;

  $tabMode = $communitySettings['newWindow'];

  usort($file,"mySort");

  $communitySettings['viewMode'] = $viewMode;

  switch ( $viewMode ) {
    case "icon":
      $ct = "<table class='tablesorter'><tr>";
      break;
    case "table":
      $ct = "<table class='tablesorter'><thead><th></th><th style='width:100px'></th><th>Application</th><th>Downloads</th><th>Author</th><th>Description</th><th>Repository</th></tr></thead><tr>";
      break;
    case "detail":
      $ct = "<table class='tablesorter'><tr>";
      $communitySettings['maxColumn'] = 2;       /* Temporarily set configuration values to reflect icon details mode */
      $communitySettings['viewMode'] = "icon";
      break;
  }

  $columnNumber = 0;

  foreach ($file as $template) {
    $t = "";

    $name = $template['SortName'];
    $appName = str_replace(" ","",$template['SortName']);

    $dockerRepo="/".str_replace('/','',$template['Repository'])."/i";

    if ( $communitySettings['dockerSearch'] == "yes" && ! $template['Plugin'] ) {
      $dockerName = "<a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search dockerHub for similar containers'>".$template['Name']."</a>";
    } else {
      $dockerName = $template['Name'];
    }

    if ( $template['Category'] == "UNCATEGORIZED" )  $template['Category'] = "Uncategorized";

    if ( ( $template['Beta'] == "true" ) ) {
      $dockerName .= "<span title='Beta Container &#13;See support forum for potential issues'><font size='1' color='red'><strong>(beta)</strong></font></span>";
    }
    $ID = $template['ID'];
    $selected = $info[$name]['template'] && stripos($info[$name]['icon'], $template['Author']) !== false;

    $changes = "";
    if ( $template['Changes'] ) {
      $changes= " <a style='cursor:pointer'><img src='/plugins/$plugin/images/information.png' onclick=showInfo($ID,'$appName'); title='Click for the changelog / more information'></a>";
    }

    $newIcon="";
    if ( $template['Date'] > strtotime($communitySettings['timeNew'] ) ) {
      $newDate = date("F d Y",$template['Date']);
      $newIcon="<img src='/plugins/$plugin/images/star.png' style='width:15px;height:15px;' title='New / Updated - $newDate'></img>";
    }
    $displayIcon = $template['IconWeb'];
    $displayIcon = $displayIcon ? $displayIcon : "/plugins/$plugin/images/question.png";

    if ( $template['Uninstall'] ) {
      $selected = true;
    }

    if ( $communitySettings['viewMode'] == "icon" ) {
      $popUp = "Click for a full description\n".$template['PopUpDescription'];
      $t .= "<td>";
      $t .= "<center>Author:<strong><a style='cursor:pointer' onclick='authorSearch(this.innerHTML);' title='Search for more containers from author'>".$template['Author']."</a></strong></center>";
      $t .= "<center><font size='1'>";

      if ( $template['Private'] == "true" ) {
        $RepoName = $template['RepoName']."<br><font color=red>Private</font>";
      } else {
        $RepoName = $template['RepoName'];
      }

      if ( $template['Announcement'] ) {
        $t .= "<a href='".$template['Announcement']."' target='_blank' title='Click to go to the repository Announcement thread'>$RepoName</a>";
      } else {
        $t .= $RepoName;
      }
      $t .= "</font></center>";

      if ($template['Stars']) {
        $t .= "<center><img src='/plugins/$plugin/images/red-star.png' style='height:15px;width:15px'> <strong>".$template['Stars']."</strong></center>";
      }

      $t .= "<figure><center><a onclick=showDesc($ID,'$appName'); style='cursor:pointer' title='";

      if ( $communitySettings['maxColumn'] > 2 ) {
        $t .= $popUp;
      } else {
        $t .= "Click for a full description";
      }

      if ( $template['Removable'] ) {
        $removable = "<img src='/plugins/dynamix.docker.manager/images/remove.png' title='Remove Application From List' style='width:20px;height:20px;cursor:pointer' onclick='removeApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
      } else {
        $removable = "";
      }
      if ( $template['Uninstall'] ) {
        $uninstall = "<img src='/plugins/dynamix.docker.manager/images/remove.png' title='Uninstall Application' style='width:20px;height:20px;cursor:pointer' ";

        if ( $template['Plugin'] ) {
          $uninstall .= "onclick='uninstallApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
        } else {
          $uninstall .= "onclick='uninstallDocker(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
        }
      } else {
        $uninstall = "";
      }

      $t .= "'><img src='$displayIcon' onError='this.src=\"/plugins/$plugin/images/question.png\";' style='width:".$iconSize."px;height=".$iconSize."px;'></a></center><figcaption><strong><center><font size='3'>$dockerName</font><br>$newIcon$changes$removable$uninstall</center></strong></figcaption></figure>";

      if ( ! $template['Compatible'] ) {
        if ( ! $template['UnknownCompatible'] ) {
          $t .= "<center><font color='red'>Incompatible</font></center>";
        }
      }

      if ( $template['Plugin'] ) {
        $pluginName = basename($template['PluginURL']);

        if ( file_exists("/var/log/plugins/$pluginName") ) {
          $pluginSettings = getPluginLaunch($pluginName);

          if ( $pluginSettings ) {
            $t .= "<center><input type='submit' value='Settings' style='margin:0px' formtarget='$tabMode' formaction='$pluginSettings' formmethod='post'></center></input>";
          }
        } else {
          $t .= "<center>";
          $buttonTitle = $template['MyPath'] ? "Reinstall Plugin" : "Install Plugin";
          $t .= "<input type='button' value='$buttonTitle' style='margin:0px' title='Click to install this plugin' onclick=installPlugin('".$template['PluginURL']."');>";
        }
      } else {
        if ( $communitySettings['dockerRunning'] ) {
          if ( $selected ) {
            $t .= "<center>";
            $t .= "<input type='submit' value='Default' style='margin:1px' title='Click to reinstall the application using default values' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."'>";

            $t .= "<input type='submit' value='Edit' style='margin:1px' title='Click to edit the application values' formtarget='$tabMode' formmethod='post' formaction='UpdateContainer?xmlTemplate=edit:".addslashes($info[$name]['template'])."'>";
            $t .= "</center><br>";
          } else {
            $t .= "<center>";

            if ( $template['MyPath'] ) {
              $t .= "<input type='submit' style='margin:0px' title='Click to reinstall the application' value='Reinstall' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=user:".addslashes($template['MyPath'])."'>";
            } else {
              $t .= "<input type='submit' style='margin:0px' title='Click to install the application' value='Add' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."'>";
            }

            $t .= "</center><br>";
          }
        } else {
          $t .= "<center><font color='red'>Docker Not Enabled</font></center>";
        }
      }
      if ( $communitySettings['maxColumn'] > 2 ) {
        $t .= ($template['Support']) ? "<center><a href='".$template['Support']."' target='_blank' title='Click to go to the support thread'>[Support]</a></center>" : "";
        if ( $template['UpdateAvailable'] ) {
          $t .= "<br><center><font color='red'><b>Update Available.  Click <a onclick='installPLGupdate(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);' style='cursor:pointer'>Here</a> to Install</b></center></font>";
        }
      }
      $t .= "</td>";

      if ( $communitySettings['maxColumn'] == 2 ) {
        $t .= "<td style='display:inline-block;width:350px;text-align:left'>";
        $t .= "<strong>Categories: </strong>".$template['Category']."<br><br>";
        $t .= "<span class='desc_readmore' style='display:block'>";

        if ( ! $template['Compatible'] && ! $template['UnknownCompatible'] ) {
          $t .= "<font color='red'>NOTE: This application is listed as being NOT compatible with your version of unRaid<br><br></font>";
        }

        $t .= $template['Description'];

        if ( $template['Date'] ) {
          $t .= "</b></strong><center><strong>Date Updated: </strong>".date("F j, Y",$template['Date'])."</center>";
        }
        $t .= "</span><br>";

        if ( $template['ModeratorComment'] ) {
          $t .= "</b></strong><font color='red'><b>Moderator Comments:</b></font> ".$template['ModeratorComment'];
        }
        if ( $template['UpdateAvailable'] ) {
          $t .= "<br><center><font color='red'><b>Update Available.  Click <a onclick='installPLGupdate(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);' style='cursor:pointer'>Here</a> to Install</b></center></font>";
        }
        $t .= "</b></strong><center>";
        $t .= ($template['Support']) ? "<a href='".$template['Support']."' target='_blank' title='Click to go to the support thread'><font color=red>Support Thread</font></a>" : "";

        if ( $template['Project'] ) {
          $t .= "<a target='_blank' title='Click to go the the Project Home Page' href='".$template['Project']."'><font color=red>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Project Home Page</font></a>";
        }
        $t .= "</center></font>";
        $t .= "</td>";
      }
###################################################
# TABLE MODE
    } else {
      $appName = str_replace(" ","",$template['SortName']);

      $t .= "<tr><td style='margin:0;padding:0'>";
      $t .= "<a onclick='showDesc(".$template['ID'].",&#39;".$name."&#39;);' style='cursor:pointer'><img title='Click to display full description' src='".$displayIcon."' style='width:48px;height:48px;' onError='this.src=\"/plugins/$plugin/images/question.png\";'></a></td>";

      $t .= "<td><center>";

      if ( ! $template['Compatible'] ) {
        if ( ! $template['UnknownCompatible'] ) {
          $t .= "<center><font color='red'>Incompatible</font></center>";
        }
      }

      if ( $template['Plugin'] ) {
        $pluginName = basename($template['PluginURL']);

        if ( file_exists("/var/log/plugins/$pluginName") ) {
          $pluginSettings = getPluginLaunch($pluginName);

          if ( $pluginSettings ) {
            $t .= "<center><input type='submit' style='margin:0px' value='Settings' formtarget='$tabMode' formaction='$pluginSettings' formmethod='post'></center></input>";
          }
        } else {
          $buttonTitle = $template['MyPath'] ? "Reinstall Plugin" : "Install Plugin";
          $t .= "<input type='button' style='margin:0px' value='$buttonTitle' title='Click to install plugin' onclick=installPlugin('".$template['PluginURL']."');>";
        }
      } else {
        if ( $communitySettings['dockerRunning'] ) {
          if ($selected) {
            $t .= "<input type='submit' style='margin:0px' title='Click to reinstall the application using default values' value='Default' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".$template['Path']."'>";
          } else {
            if ( $template['MyPath'] ) {
              $t .= "<input type='submit' style='margin:0px' title='Click to install the application' value='Reinstall' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=user:".$template['MyPath']."'>";
            } else {
              $t .= "<input type='submit' style='margin:0px' title='Click to install the application' value='Add' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".$template['Path']."'>";
            }
          }

          if ($selected) {
            $t .= "<input type='submit' style='margin:0px' title='Click to install the application' value='Edit' formtarget='$tabMode' formmethod='post' formaction='UpdateContainer?xmlTemplate=edit:".addslashes($info[$name][template])."'>";
          }
        } else {
          $t .= "<center><font color='red'>Docker Not Enabled</font></center>";
        }
      }

      $t .= "</center></td>";

      if ( $template['Removable'] ) {
        $removable = "<img src='/plugins/dynamix.docker.manager/images/remove.png' title='Remove Application From List' style='width:20px;height:20px;cursor:pointer' onclick='removeApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
      } else {
        $removable = "";
      }
      if ( $template['Uninstall'] ) {
        $uninstall = "<img src='/plugins/dynamix.docker.manager/images/remove.png' title='Uninstall Application' style='width:20px;height:20px;cursor:pointer' ";
        if ( $template['Plugin'] ) {
          $uninstall .= "onclick='uninstallApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
        } else {
          $uninstall .= "onclick='uninstallDocker(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
        }
      } else {
        $uninstall = "";
      }

      $stars = $template['Stars'] ? "&nbsp;<img src='/plugins/$plugin/images/red-star.png' style='width:15px'><strong>".$template['Stars']."</strong>": "";

      $t .= "<td><center>$dockerName<br>$changes$newIcon$removable$uninstall$stars</center>";

      $t .= "<div><center><a href='".$template['Support']."' target='_blank' title='Click to go to the support thread'>[Support]</a></center></div></td>";

      $t .= $template['Downloads'] ? "<td><center>".$template['Downloads']."</center></td>" : "<td><center>Not Available</center></td>";

      $t .= "<td><a style='cursor:pointer' onclick='authorSearch(this.innerHTML);' title='Search for more containers from author'>".$template['Author']."</a></td>";

      $t .= "<td><span class='desc_readmore' style='display:block' title='Categories: ".$template['Category']."'>".$template['Description']."</span>";

      if ( $template['ModeratorComment'] ) {
        $t .= "</strong></b><b><font color='red'>Moderator Comments:</font></b> ".$template['ModeratorComment'];
      }
      if ( $template['UpdateAvailable'] ) {
        $t .= "<br><center><font color='red'><b>Update Available.  Click <a onclick='installPLGupdate(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);' style='cursor:pointer'>Here</a> to Install</b></center></font>";
      }

      $t .= "</td>";

      $t .= "<td style='text-align:left'><font size=1px>";

      if ( $template['Private'] == "true" ) {
        $RepoName = $template['RepoName']."<font color=red> (Private)</font>";
      } else {
        $RepoName = $template['RepoName'];
      }
      if ( $template['Announcement'] ) {
        $t .="<a href='".$template['Announcement']."' target='_blank' title='Click to go to the repository Announcement thread' >$RepoName</a>";
      } else {
        $t .= $RepoName;
      }
      $t .= "</font></td>";
      $t .= "</tr>";
    }
    $columnNumber=++$columnNumber;

    if ( $communitySettings['viewMode'] == "icon" ) {
      if ( $columnNumber == $communitySettings['maxColumn'] ) {
        $columnNumber = 0;
        $t .= "</tr><tr>";
      }
    }
    $ct .= $t;
  }

  $ct .= "</table>";

  return $ct;
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

  switch ($viewMode) {
    case "icon":
      $t = "<table>";
      break;
    case "table":
      $t =  "<table class='tablesorter'><thead><th></th><th></th><th>Container</th><th>Author</th><th>Stars</th><th>Description</th></thead>";
      break;
    case "detail":
      $t = "<table class='tablesorter'>";
      break;
  }

  $iconSize = $communitySettings['iconSize'];

  if ( $viewMode == "table" ) {
    $iconSize = 48;
  }

  $maxColumn = $communitySettings['maxColumn'];
  if ( $viewMode == "detail" ) {
    $viewMode = "icon";
    $maxColumn = 2;
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
        $recommended = true;
      }
    }

    if ( $viewMode == "icon" ) {
      $t .= "<td>";

      if ( $result['Official'] ) {
        $t .= "<center><font color='red'><strong>Official</strong></font></center>";
      }

      $t .= "<center>Author: </strong><font size='3'><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Containers From Author'>".$result['Author']."</a></font></center>";

      if ( $result['Stars'] ) {
        $t .= "<center><img src='/plugins/$plugin/images/red-star.png' style='height:20px;width:20px'> <strong>".$result['Stars']."</strong></center>";
      }

      $description = "Click to go to the dockerHub website for this container";
      if ( $result['Description'] ) {
        $description = $result['Description']."&#13;&#13;$description";
      }

      $t .= "<figure><center><a href='".$result['DockerHub']."' title='$description' target='_blank'>";
      $t .= "<img style='width:".$iconSize."px;height:".$iconSize."px;' src='".$result['Icon']."' onError='this.src=\"/plugins/$plugin/images/question.png\";'></a>";
      $t .= "<figcaption><strong><center><font size='3'><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For Similar Containers'>".$result['Name']."</a></font></center></strong></figcaption></figure>";

      if ( $recommended ) {
        $searchTerm = explode("/",$result['Repository']);
        $t .= "<center><input type='button' value='Display Recommended' onclick='recommendedSearch(&#39;".$searchTerm[1]."&#39;)' style='margin:0px'></center>";
      } else {
        $t .= "<center><input type='button' value='Add' onclick='dockerConvert(&#39;".$result['ID']."&#39;)' style='margin:0px'></center>";
      }

      $t .= "</td>";

      if ( $maxColumn == 2 ) {
        $t .= "<td style='display:inline-block;width:350px;text-align:left;'>";
        $t .= "<br><br><br>";

        if ( $result['Official'] ) {
          $t .= "<strong><font color=red>Official</font> ".$result['Name']." container.</strong><br><br>";
        }

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

      if ( $recommended ) {
        $searchTerm = explode("/",$result['Repository']);
        $t .= "<td><input type='button' value='Display Recommended' onclick='recommendedSearch(&#39;".$searchTerm[1]."&#39;)' style='margin:0px'></td>";
      } else {
        $t .= "<td><input type='button' value='Add' onclick='dockerConvert(&#39;".$result['ID']."&#39;)';></td>";
      }

      $t .= "<td><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Similar Containers'>".$result['Name']."</a></td>";
      $t .= "<td><a style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Containers From Author'>".$result['Author']."</a></td>";
      if ( $result['Stars'] ) {
        $t .= "<td><img src='/plugins/$plugin/images/red-star.png' style='height:20px;width:20px'> <strong>".$result['Stars']."</strong></td>";
      } else {
        $t .= "<td></td>";
      }

      $t .= "<td>";
      if ( $result['Official'] ) {
        $t .= "<strong><font color=red>Official</font> ".$result['Name']." container.</strong><br><br>";
      }
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
  $filter   = isset($_POST['filter']) ? urldecode(($_POST['filter'])) : false;
  $category = isset($_POST['category']) ? '/'.urldecode(($_POST['category'])).'/i' : false;
  $newApp   = isset($_POST['newApp']) ? urldecode(($_POST['newApp'])) : false;

  $viewMode = isset($_POST['viewMode']) ? urldecode(($_POST['viewMode'])) : "Icon";
  $sortKey  = isset($_POST['sortBy']) ? urldecode(($_POST['sortBy'])) : "Name";
  $sortDir  = isset($_POST['sortDir']) ? urldecode(($_POST['sortDir'])) : "Up";

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
        echo "<tr><td colspan='5'><br><center>Download of source file has failed</center></td></tr>";
        break;
      } else {
        $lastUpdated['last_updated_timestamp'] = time();
        writeJsonFile($communityPaths['lastUpdated-old'],$lastUpdated);
        if (is_file($communityPaths['updateErrors'])) {
          echo "<td><td colspan='5'><br><center>The following repositories failed to download correctly:<br><br>";
          echo "<strong>".file_get_contents($communityPaths['updateErrors'])."</strong></center></td></tr>";
          break;
        }
      }
    }
  }
  getConvertedTemplates();
  if ( $category === "/NONE/i" ) {
    echo "<center><font size=4>Select A Category Above</font></center>";
    echo changeUpdateTime();
    @unlink($communityPaths['community-templates-displayed']);
    break;
  }

  $file = readJsonFile($communityPaths['community-templates-info']);

  if (!is_array($file)) break;

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

  $displayApplications              = array();
  $displayApplications['official']  = $official;
  $displayApplications['community'] = $display;
  $displayApplications['beta']      = $beta;
  $displayApplications['private']   = $privateApplications;

  writeJsonFile($communityPaths['community-templates-displayed'],$displayApplications);

  display_apps($viewMode);

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

  download_url($communityPaths['repositoriesURL'],$tmpFileName);

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

  download_url($communityPaths['application-feed-last-updated'],$communityPaths['lastUpdated']);

  $latestUpdate = readJsonFile($communityPaths['lastUpdated']);

  if ( $latestUpdate['last_updated_timestamp'] > $lastUpdatedOld['last_updated_timestamp'] ) {
    copy($communityPaths['lastUpdated'],$communityPaths['lastUpdated-old']);
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
  $viewMode = isset($_POST['viewMode']) ? urldecode(($_POST['viewMode'])) : "icon";
  $sortKey  = isset($_POST['sortBy']) ? urldecode(($_POST['sortBy'])) : "Name";
  $sortDir  = isset($_POST['sortDir']) ? urldecode(($_POST['sortDir'])) : "Up";

  if ( file_exists($communityPaths['community-templates-displayed']) ) {
    display_apps($viewMode);
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
  $viewMode = isset($_POST['view']) ? urldecode(($_POST['view'])) : "icon";

  if ( ! file_exists($communityPaths['dockerSearchResults']) ) {
    break;
  }

  $file = readJsonFile($communityPaths['dockerSearchResults']);
  $pageNumber = $file['page_number'];

  displaySearchResults($pageNumber,$viewMode);

  break;

#######################################################################
#                                                                     #
# convert_docker - called when system adds a container from dockerHub #
#                                                                     #
#######################################################################

case 'convert_docker':
  $dockerID = isset($_POST['ID']) ? urldecode(($_POST['ID'])) : "";

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
  $filter     = isset($_POST['filter']) ? urldecode(($_POST['filter'])) : "";
  $pageNumber = isset($_POST['page']) ? urldecode(($_POST['page'])) : "1";
  $viewMode   = isset($_POST['view']) ? urldecode(($_POST['view'])) : "icon";

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
      $o['Icon'] = $communityTemplates[$iconMatch]['IconWeb'];
    }

    $dockerResults[$i] = $o;

    $i=++$i;
  }
  $dockerFile['num_pages'] = $num_pages;
  $dockerFile['page_number'] = $pageNumber;
  $dockerFile['results'] = $dockerResults;

  writeJsonFile($communityPaths['dockerSearchResults'],$dockerFile);

  echo suggestSearch($filter,false);

  displaySearchResults($pageNumber, $viewMode);

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

  $installed = isset($_POST['installed']) ? urldecode(($_POST['installed'])) : "";

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

  if ( $installed == "true" ) {
    foreach ($info as $installedDocker) {
      $installedImage = $installedDocker['Image'];
      $installedName = $installedDocker['Name'];

      foreach ($file as $template) {
        if ( $installedName == $template['Name'] ) {
          if ( startsWith($installedImage,$template['Repository']) ) {
            $template['Uninstall'] = true;
            $template['MyPath'] = $template['Path'];
            $displayed[] = $template;
            break;
          }
        }
      }
    }
    $all_files = @array_diff(@scandir("/boot/config/plugins/dockerMan/templates-user"),array(".",".."));

    foreach ($all_files as $xmlfile) {
      if ( pathinfo($xmlfile,PATHINFO_EXTENSION) == "xml" ) {
        $o = readXmlFile("/boot/config/plugins/dockerMan/templates-user/$xmlfile",$moderation);
        $o['MyPath'] = "/boot/config/plugins/dockerMan/templates-user/$xmlfile";
        $o['UnknownCompatible'] = true;
    # Overwrite any template values with the moderated values

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
          $o['Removable'] = true;

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
  $application = isset($_POST['application']) ? urldecode(($_POST['application'])) : "";

  @unlink($application);

  echo "ok";
  break;

#######################
#                     #
# Uninstalls a plugin #
#                     #
#######################

case 'uninstall_application':
  $application = isset($_POST['application']) ? urldecode(($_POST['application'])) : "";

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
  $application = isset($_POST['application']) ? urldecode(($_POST['application'])) : "";

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
  $appdata = isset($_POST['appdata']) ? urldecode(($_POST['appdata'])) : "";

  $appdata = trim($appdata);

  $commandLine = $communityPaths['deleteAppdataScript'].' "'.$appdata.'" > /dev/null | at NOW -M >/dev/null 2>&1';
  exec($commandLine);

  break;

###########################################
#                                         #
# Accept the docker not installed warning #
#                                         #
###########################################

case 'accept_docker_warning':
  file_put_contents($communityPaths['accept_docker_warning'],"accepted");
  break;

case 'resourceMonitor':
  $sortKey = isset($_POST['sortBy']) ? urldecode(($_POST['sortBy'])) : "Name";
  $sortDir = isset($_POST['sortDir']) ? urldecode(($_POST['sortDir'])) : "Up";

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
      $container['Icon'] = $templates[$runningTemplate]['IconWeb'];
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
  if ( is_file($communityPaths['calculateAppdataProgress']) ) {
    $o .= "<script>$('#calculateAppdata').prop('disabled',true);</script>";
  } else {
    $o .= "<script>$('#calculateAppdata').prop('disabled',false);</script>";
  }
  echo $o;
  break;

case 'calculateAppdata':
  $commandLine = $communityPaths['calculateAppdataScript']." > /dev/null | at NOW -M >/dev/null 2>&1";
  exec($commandLine);
  break;

case 'checkCalculations':
    if ( is_file($communityPaths['calculateAppdataProgress']) ) {
    $o .= "<script>$('#calculateAppdata').prop('disabled',true);</script>";
  } else {
    $o .= "<script>$('#calculateAppdata').prop('disabled',false);</script>";
  }
  echo $o;
  break;

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
