<?PHP

###############################################################
#                                                             #
# Community Applications copyright 2015-2017, Andrew Zawadzki #
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

$unRaidSettings = my_parse_ini_file($communityPaths['unRaidVersion']);
$unRaidVersion = $unRaidSettings['version'];
if ($unRaidVersion == "6.2") $unRaidVersion = "6.2.0";
$unRaid64 = (version_compare($unRaidVersion,"6.4.0-rc0",">=")) || (is_file("/usr/local/emhttp/plugins/dynamix/styles/dynamix-gray.css"));
if ( ! $unRaid64 ) {
  $communityPaths['defaultSkin'] = $communityPaths['legacySkin'];
}
$templateSkin = readJsonFile($communityPaths['defaultSkin']);   # Global Var used in helpers ( getMaxColumns() )

################################################################################
#                                                                              #
# Set up any default settings (when not explicitely set by the settings module #
#                                                                              #
################################################################################

$communitySettings = parse_plugin_cfg("$plugin");
$communitySettings['appFeed']       = "true"; # set default for deprecated setting
$communitySettings['maxPerPage']    = getPost("maxPerPage",$communitySettings['maxPerPage']);  # Global POST.  Used damn near everywhere
$communitySettings['iconSize']      = 96;
$communitySettings['maxColumn']     = 5; # Pointless on 6.3  Gets overridden on 6.4 anyways

if ( $communitySettings['favourite'] != "None" ) {
  $officialRepo = str_replace("*","'",$communitySettings['favourite']);
  $separateOfficial = true;
}
if ( is_dir("/var/lib/docker/containers") ) {
  $communitySettings['dockerRunning'] = "true";
} else {
  $communitySettings['dockerSearch'] = "no";
  unset($communitySettings['dockerRunning']);
}

if ( $communitySettings['dockerRunning'] ) {
  $info = $DockerTemplates->getAllInfo();
  $DockerClient = new DockerClient();
  $dockerRunning = $DockerClient->getDockerContainers();
} else {
  $info = array();
  $dockerRunning = array();
}

exec("mkdir -p ".$communityPaths['tempFiles']);
exec("mkdir -p ".$communityPaths['persistentDataStore']);

if ( !is_dir($communityPaths['templates-community']) ) {
  exec("mkdir -p ".$communityPaths['templates-community']);
  @unlink($infoFile);
}

# Make sure the link is in place
/* if (is_dir("/usr/local/emhttp/state/plugins/$plugin")) exec("rm -rf /usr/local/emhttp/state/plugins/$plugin");
if (!is_link("/usr/local/emhttp/state/plugins/$plugin")) symlink($communityPaths['templates-community'], "/usr/local/emhttp/state/plugins/$plugin");
 */
#################################################################
#                                                               #
# Functions used to download the templates from various sources #
#                                                               #
#################################################################
function DownloadCommunityTemplates() {
  global $communityPaths, $infoFile, $plugin, $communitySettings, $statistics;

  $betaComment = "<font color='purple'>The author of this template has designated it to be a beta.  You may experience issues with this application</font>";
  $moderation = readJsonFile($communityPaths['moderation']);
  if ( ! is_array($moderation) ) { $moderation = array(); }

  $DockerTemplates = new DockerTemplates();

  if (! $download = download_url($communityPaths['community-templates-url']) ) { return false; }
  $Repos  = json_decode($download, true);
  if ( ! is_array($Repos) )                                                    { return false; }
  $statistics['repository'] = count($Repos);
  
  $appCount = 0;
  $myTemplates = array();

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
      file_put_contents($communityPaths['updateErrors'],"Failed to download <font color='purple'>".$downloadRepo['name']."</font> ".$downloadRepo['url']."<br>",FILE_APPEND);
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
        if ( ! $o ) {
          file_put_contents($communityPaths['updateErrors'],"Failed to parse <font color='purple'>$file</font> (errors in XML file?)<br>",FILE_APPEND);
        }
        if ( ! $o['Repository'] ) {
          if ( ! $o['Plugin'] ) {
            $statistics['invalidXML']++;
            continue;
          } else {
            $statistics['totalApplications']++;
          }
        }
        
        $o['Forum'] = $Repo['forum'];
        $o['RepoName'] = $Repo['name'];
        $o['ID'] = $i;
        $o['Displayable'] = true;
        $o['Support'] = $o['Support'] ? $o['Support'] : $o['Forum'];
        $o['DonateText'] = $o['DonateText'] ? $o['DonateText'] : $Repo['donatetext'];
        $o['DonateLink'] = $o['DonateLink'] ? $o['DonateLink'] : $Repo['donatelink'];
        $o['DonateImg'] = $o['DonateImg'] ? $o['DonateImg'] : $Repo['donateimg'];
        $o['WebPageURL'] = $Repo['web'];
        $o['Logo'] = $Repo['logo'];
        $o['Profile'] = $Repo['profile'];
        fixSecurity($o,$o);
        $o = fixTemplates($o);

        # Overwrite any template values with the moderated values
        if ( is_array($moderation[$o['Repository']]) ) {
          $o = array_merge($o, $moderation[$o['Repository']]);
        }
        $o['Compatible'] = versionCheck($o);

        if ( $o['Beta'] == "true" ) {
          if ( $o['ModeratorComment'] ) {
            $o['ModeratorComment'] .= "<br><br>$betaComment";
          } else {
            $o['ModeratorComment'] = $betaComment;
          }
        }
        if ( $o['Blacklist'] ) {
          $statistics['blacklist']++;
          $blacklist[] = "{$o['Repo']} - <b>{$o['Name']}</b> - {$o['ModeratorComment']}";
          unset($o);
          continue;
        } else {
          $statistics['totalApplications']++;
        }
        $o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
        $o['Category'] = str_replace("Status:Stable","",$o['Category']);
        $myTemplates[$o['ID']] = $o;
        if ( is_array($o['Branch']) ) {
          if ( ! $o['Branch'][0] ) {
            $tmp = $o['Branch'];
            unset($o['Branch']);
            $o['Branch'][] = $tmp;
          }
          foreach($o['Branch'] as $branch) {
            $i = ++$i;
            $subBranch = $o;
            $masterRepository = explode(":",$subBranch['Repository']);
            $o['BranchDefault'] = $masterRepository[1];
            $subBranch['Repository'] = $masterRepository[0].":".$branch['Tag']; #This takes place before any xml elements are overwritten by additional entries in the branch, so you can actually change the repo the app draws from
            $subBranch['BranchName'] = $branch['Tag'];
            $subBranch['BranchDescription'] = $branch['TagDescription'] ? $branch['TagDescription'] : $branch['Tag'];
            $subBranch['Path'] = $communityPaths['templates-community']."/".$i.".xml";
            $subBranch['Displayable'] = false;
            $subBranch['ID'] = $i;
            $replaceKeys = array_diff(array_keys($branch),array("Tag","TagDescription"));
            foreach ($replaceKeys as $key) {
              $subBranch[$key] = $branch[$key];
            }
            unset($subBranch['Branch']);
            $myTemplates[$i] = $subBranch;
            $o['BranchID'][] = $i;
            file_put_contents($subBranch['Path'],makeXML($subBranch));
          }
          unset($o['Branch']);
          $o['Path'] = $communityPaths['templates-community']."/".$o['ID'].".xml";
          file_put_contents($o['Path'],makeXML($o));
          $myTemplates[$o['ID']] = $o;
        }
        $i = ++$i;
      }
    }
  }
  writeJsonFile($communityPaths['blacklisted_txt'],$blacklist);
  writeJsonFile($communityPaths['statistics'],$statistics);
  writeJsonFile($communityPaths['community-templates-info'],$myTemplates);
  file_put_contents($communityPaths['LegacyMode'],"active");
  return true;
}

#  DownloadApplicationFeed MUST BE CALLED prior to DownloadCommunityTemplates in order for private repositories to be merged correctly.

function DownloadApplicationFeed() {
  global $communityPaths, $infoFile, $plugin, $communitySettings, $statistics;

  $betaComment = "<font color='purple'>The author of this template has designated it to be a beta.  You may experience issues with this application</font>";
  $moderation = readJsonFile($communityPaths['moderation']);
  if ( ! is_array($moderation) ) {
    $moderation = array();
  }
  $statistics['moderation'] = count($moderation);

  $Repositories = readJsonFile($communityPaths['Repositories']);
  if ( ! $Repositories ) {
    $Repositories = array();
  }
  $statistics['repository'] = count($Repositories);
  $downloadURL = randomFile();

  if ($download = download_url($communityPaths['application-feed'],$downloadURL) ){
    return false;
  }
  $ApplicationFeed  = readJsonFile($downloadURL);
  if ( ! is_array($ApplicationFeed) ) { return false; }

  unlink($downloadURL);
  $i = 0;
  $statistics['totalApplications'] = count($ApplicationFeed['applist']);
  
  $myTemplates = array();

  foreach ($ApplicationFeed['applist'] as $file) {
    if ( ! $file['Repository'] ) {
      if ( ! $file['Plugin'] ) {
        $statistics['invalidXML']++;
        continue;
      }
    }
    unset($o);
    # Move the appropriate stuff over into a CA data file
    $o = $file;
    $o['ID']            = $i;
    $o['Displayable']   = true;
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
      $statistics['plugin']++;
    } else {
      $statistics['docker']++;
    }
    $RepoIndex = searchArray($Repositories,"name",$o['RepoName']);
    if ( $RepoIndex != false ) {
      $o['DonateText'] = $Repositories[$RepoIndex]['donatetext'];
      $o['DonateImg']  = $Repositories[$RepoIndex]['donateimg'];
      $o['DonateLink'] = $Repositories[$RepoIndex]['donatelink'];
      $o['WebPageURL'] = $Repositories[$RepoIndex]['web'];
      $o['Logo']       = $Repositories[$RepoIndex]['logo'];
      $o['Profile']    = $Repositories[$RepoIndex]['profile'];
    }
    $o['DonateText'] = $file['DonateText'] ? $file['DonateText'] : $o['DonateText'];
    $o['DonateLink'] = $file['DonateLink'] ? $file['DonateLink'] : $o['DonateLink'];

    if ( ($file['DonateImg']) || ($file['DonateImage']) ) {  #because Sparklyballs can't read the tag documentation
      $o['DonateImg'] = $file['DonateImage'] ? $file['DonateImage'] : $file['DonateImg'];
    }
    
    fixSecurity($o,$o); # Apply various fixes to the templates for CA use
    $o = fixTemplates($o);
    
# Overwrite any template values with the moderated values

    if ( is_array($moderation[$o['Repository']]) ) {
      $o = array_merge($o, $moderation[$o['Repository']]);
      $file = array_merge($file, $moderation[$o['Repository']]);
    }

    if ($o['Blacklist']) {
      $statistics['blacklist']++;
      $blacklist[] = "{$o['Repo']} - <b>{$o['Name']}</b> - {$o['ModeratorComment']}";
      unset($o);
      continue;
    }
    
    $o['Compatible'] = versionCheck($o);

    if ( $o['Beta'] == "true" ) {
      $o['ModeratorComment'] .= $o['ModeratorComment'] ? "<br><br>$betaComment" : $betaComment;
    }

    # Update the settings for the template

    $file['Compatible'] = $o['Compatible'];
    $file['Beta'] = $o['Beta'];
    $file['MinVer'] = $o['MinVer'];
    $file['MaxVer'] = $o['MaxVer'];
    $file['Category'] = $o['Category'];
    $o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
    $o['Category'] = str_replace("Status:Stable","",$o['Category']);
    $myTemplates[$i] = $o;
 
    if ( is_array($file['Branch']) ) {
      if ( ! $file['Branch'][0] ) {
        $tmp = $file['Branch'];
        unset($file['Branch']);
        $file['Branch'][] = $tmp;
      }
      foreach($file['Branch'] as $branch) {
        $i = ++$i;
        $subBranch = $file;
        $masterRepository = explode(":",$subBranch['Repository']);
        $o['BranchDefault'] = $masterRepository[1];
        $subBranch['Repository'] = $masterRepository[0].":".$branch['Tag']; #This takes place before any xml elements are overwritten by additional entries in the branch, so you can actually change the repo the app draws from
        $subBranch['BranchName'] = $branch['Tag'];
        $subBranch['BranchDescription'] = $branch['TagDescription'] ? $branch['TagDescription'] : $branch['Tag'];
        $subBranch['Path'] = $communityPaths['templates-community']."/".$i.".xml";
        $subBranch['Displayable'] = false;
        $subBranch['ID'] = $i;
        $replaceKeys = array_diff(array_keys($branch),array("Tag","TagDescription"));
        foreach ($replaceKeys as $key) {
          $subBranch[$key] = $branch[$key];
        }
        unset($subBranch['Branch']);
        $myTemplates[$i] = $subBranch;
        $o['BranchID'][] = $i;
        file_put_contents($subBranch['Path'],makeXML($subBranch));
      }
    }
    unset($file['Branch']);
    $myTemplates[$o['ID']] = $o;
    $i = ++$i;
    $templateXML = makeXML($file);
    file_put_contents($o['Path'],$templateXML);
  }
  writeJsonFile($communityPaths['blacklisted_txt'],$blacklist);
  writeJsonFile($communityPaths['statistics'],$statistics);
  writeJsonFile($communityPaths['community-templates-info'],$myTemplates);
  @unlink($communityPaths['LegacyMode']);
  return true;
}

function getConvertedTemplates() {
  global $communityPaths, $infoFile, $plugin, $communitySettings, $statistics;

# Start by removing any pre-existing private (converted templates)
  $templates = readJsonFile($communityPaths['community-templates-info']);
  $statistics = readJsonFile($communityPaths['statistics']);
  unset($statistics['private']);

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
  $Repos = dirContents($communityPaths['convertedTemplates']);

  foreach ($Repos as $Repo) {
    if ( ! is_dir($communityPaths['convertedTemplates'].$Repo) ) {
      continue;
    }

    unset($privateTemplates);
    $repoPath = $communityPaths['convertedTemplates'].$Repo."/";

    $privateTemplates = dirContents($repoPath);
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
        $o['Displayable']  = true;
        $o['Date']         = ( $o['Date'] ) ? strtotime( $o['Date'] ) : 0;
        $o['SortAuthor']   = $o['Author'];
        $o['Private']      = "true";
        $o['Forum']        = "";
        $o['Compatible']   = versionCheck($o);
        $statistics['private']++;
        
        fixSecurity($o,$o);
        $myTemplates[$i]  = $o;
        $i = ++$i;
      }
    }
  }
  writeJsonFile($communityPaths['community-templates-info'],$myTemplates);
  writeJsonFile($communityPaths['statistics'],$statistics);
  return true;
}

############################################################
#                                                          #
# Routines that actually displays the template containers. #
#                                                          #
############################################################
function display_apps($viewMode,$pageNumber=1) {
  global $communityPaths, $separateOfficial, $officialRepo, $communitySettings;
  $file = readJsonFile($communityPaths['community-templates-displayed']);
  $officialApplications = $file['official'];
  $communityApplications = $file['community'];

  $totalApplications = count($officialApplications) + count($communityApplications);

  if ( $communitySettings['dockerRunning'] ) {
    $runningDockers=str_replace('/','',shell_exec('docker ps'));
    $imagesDocker=str_replace('/','',shell_exec('docker images'));
  }

  $navigate = array();

  if ( $separateOfficial ) {
    if ( count($officialApplications) ) {
      $navigate[] = "doesn't matter what's here -> first element gets deleted anyways";
      $display = "<center><b>";

      $logos = readJsonFile($communityPaths['logos']);
      $display .= $logos[$officialRepo] ? "<img src='".$logos[$officialRepo]."' style='width:48px'>&nbsp;&nbsp;" : "";
      $display .= "<font size='4' color='purple' id='OFFICIAL'>$officialRepo</font></b></center><br>";
      $display .= my_display_apps($viewMode,$officialApplications,$runningDockers,$imagesDocker,1,true);
    }
  }

  if ( count($communityApplications) ) {
    if ( $separateOfficial ) {
      $navigate[] = "<a href='#COMMUNITY'>Community Supported Applications</a>";
      $display .= "<center><b><font size='4' color='purple' id='COMMUNITY'>Community Supported Applications</font></b></center><br>";
    }
    $display .= my_display_apps($viewMode,$communityApplications,$runningDockers,$imagesDocker,$pageNumber);
  }
  unset($navigate[0]);
 
  if ( count($navigate) ) {
    $bookmark = "Jump To: ".implode("&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;",$navigate);
  }
  $display .= ( $totalApplications == 0 ) ? "<center><font size='3'>No Matching Content Found</font></center>" : "";
 
  $totalApps = "$totalApplications";

  $display .= "<script>$('#Total').html('$totalApps');</script>";
  $display .= changeUpdateTime();
  echo $bookmark;
  echo $display;
}

function my_display_apps($viewMode,$file,$runningDockers,$imagesDocker,$pageNumber=1,$officialFlag=false) {
  global $communityPaths, $info, $communitySettings, $plugin, $unRaid64;
  
  $templateFormatArray = array(1 => $communitySettings['windowWidth']);      # this array is only used on header, sol, eol, footer

  $pinnedApps = getPinnedApps();
  $iconSize = $communitySettings['iconSize'];
  $tabMode = $communitySettings['newWindow'];

  usort($file,"mySort");

  $communitySettings['viewMode'] = $viewMode;

  $skin = readJsonFile($communityPaths['defaultSkin']);
  if ( ! $officialFlag ) {
    $ct = "<br>".getPageNavigation($pageNumber,count($file),false)."<br>";
  }
  $ct .= vsprintf($skin[$viewMode]['header'],$templateFormatArray);
  $displayTemplate = $skin[$viewMode]['template'];
  if ( $unRaid64 ) {
    $communitySettings['maxColumn'] = $communitySettings['maxIconColumns'];
  }
  if ( $viewMode == 'detail' ) {
    $communitySettings['maxColumn'] = $unRaid64 ? $communitySettings['maxDetailColumns'] : 2;
    $communitySettings['viewMode'] = "icon";
  }
  
  $columnNumber = 0;
  $appCount = 0;
  $startingApp = $officialFlag ? 1 : ($pageNumber -1) * $communitySettings['maxPerPage'] + 1;
  $startingAppCounter = 0;
  
  
  foreach ($file as $template) {
    $startingAppCounter++;
    if ( $startingAppCounter < $startingApp ) {
      continue;
    }
    if ( $columnNumber == 0 ) {
      $ct .= vsprintf($skin[$viewMode]['sol'],$templateFormatArray);
    }
    $name = $template['SortName'];
    $appName = str_replace(" ","",$template['SortName']);
    $t = "";
    $ID = $template['ID'];
    $selected = $info[$name]['template'] && stripos($info[$name]['icon'], $template['SortAuthor']) !== false;
    $selected = $template['Uninstall'] ? true : $selected;
    $RepoName = ( $template['Private'] == "true" ) ? $template['RepoName']."<font color=red> (Private)</font>" : $template['RepoName'];
    if ( ! $template['DonateText'] ) {
      $template['DonateText'] = "Donate To Author";
    }
    $template['display_DonateLink'] = $template['DonateLink'] ? "<font size='0'><a class='ca_tooltip' href='".$template['DonateLink']."' target='_blank' title='".$template['DonateText']."'>Donate To Author</a></font>" : "";
    $template['display_Project'] = $template['Project'] ? "<a class='ca_tooltip' target='_blank' title='Click to go the the Project Home Page' href='".$template['Project']."'><font color=red>Project Home Page</font></a>" : "";
    $template['display_Support'] = $template['Support'] ? "<a class='ca_tooltip' href='".$template['Support']."' target='_blank' title='Click to go to the support thread'><font color=red>Support Thread</font></a>" : "";
    $template['display_webPage'] = $template['WebPageURL'] ? "<a class='ca_tooltip' title='Click to go to {$template['SortAuthor']}&#39;s web page' href='".$template['WebPageURL']."' target='_blank'><font color='red'>Web Page</font></a></font>" : "";

    if ( $template['display_Support'] && $template['display_Project'] ) { $template['display_Project'] = "&nbsp;&nbsp;&nbsp".$template['display_Project'];}
    if ( $template['display_webPage'] && ( $template['display_Project'] || $template['display_Support'] ) ) { $template['display_webPage'] = "&nbsp;&nbsp;&nbsp;".$template['display_webPage']; }
    if ( $template['UpdateAvailable'] ) {
      $template['display_UpdateAvailable'] = $template['Plugin'] ? "<br><center><font color='red'><b>Update Available.  Click <a onclick='installPLGupdate(&quot;".basename($template['MyPath'])."&quot;,&quot;".$template['Name']."&quot;);' style='cursor:pointer'>Here</a> to Install</b></center></font>" : "<br><center><font color='red'><b>Update Available.  Click <a href='Docker'>Here</a> to install</b></font></center>";
    }
    $template['display_ModeratorComment'] .= $template['ModeratorComment'] ? "</b></strong><font color='red'><b>Moderator Comments:</b></font> ".$template['ModeratorComment'] : "";
    $tempLogo = $template['Logo'] ? "<img src='".$template['Logo']."' height=20px>" : "";
    $template['display_Announcement'] = $template['Forum'] ? "<a class='ca_tooltip' href='".$template['Forum']."' target='_blank' title='Click to go to the repository Announcement thread' >$RepoName $tempLogo</a>" : "$RepoName $tempLogo";
    $template['display_Stars'] = $template['stars'] ? "<img src='/plugins/$plugin/images/red-star.png' style='height:15px;width:15px'> <strong>".$template['stars']."</strong>" : "";
    $template['display_Downloads'] = $template['downloads'] ? "<center>".$template['downloads']."</center>" : "<center>Not Available</center>";

    if ( $pinnedApps[$template['Repository']] ) {
      $pinned = "greenButton.png";
      $pinnedTitle = "Click to unpin this application";
    } else {
      $pinned = "redButton.png";
      $pinnedTitle = "Click to pin this application";
    }
    $template['display_pinButton'] = "<img class='ca_tooltip' src='/plugins/$plugin/images/$pinned' style='height:15px;width:15px;cursor:pointer' title='$pinnedTitle' onclick='pinApp(this,&quot;".$template['Repository']."&quot;);'>";
    if ( $template['Uninstall'] ) {
      $template['display_Uninstall'] = "<img class='ca_tooltip' src='/plugins/dynamix.docker.manager/images/remove.png' title='Uninstall Application' style='width:20px;height:20px;cursor:pointer' ";
      if ( $template['Plugin'] ) {
        $template['display_Uninstall'] .= "onclick='uninstallApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
      } else {
        $template['display_Uninstall'] .= "onclick='uninstallDocker(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>";
      }
    }
    $template['display_removable'] = $template['Removable'] ? "<img class='ca_tooltip' src='/plugins/dynamix.docker.manager/images/remove.png' title='Remove Application From List' style='width:20px;height:20px;cursor:pointer' onclick='removeApp(&quot;".$template['MyPath']."&quot;,&quot;".$template['Name']."&quot;);'>" : "";
    if ( $template['Date'] > strtotime($communitySettings['timeNew'] ) ) {
      $template['display_newIcon'] = "<img class='ca_tooltip' src='/plugins/$plugin/images/star.png' style='width:15px;height:15px;' title='New / Updated - ".date("F d Y",$template['Date'])."'></img>";
    }
    $template['display_changes'] = $template['Changes'] ? " <a style='cursor:pointer'><img class='ca_infoPopup' data-appnumber='$ID' src='/plugins/$plugin/images/information.png' title='Click for the changelog / more information'></a>" : "";
    $template['display_humanDate'] = date("F j, Y",$template['Date']);

    if ( $template['Plugin'] ) {
      $pluginName = basename($template['PluginURL']);
      if ( file_exists("/var/log/plugins/$pluginName") ) {
        $pluginSettings = isset($template['CAlink']) ? $template['CAlink'] : getPluginLaunch($pluginName);
        $tmpVar = $pluginSettings ? "" : " disabled ";
        $template['display_pluginSettings'] = "<input class='ca_tooltip' title='Click to go to the plugin settings' type='submit' $tmpVar style='margin:0px' value='Settings' formtarget='$tabMode' formaction='$pluginSettings' formmethod='post'>";
        $template['display_pluginSettingsIcon'] = $pluginSettings ? "<a class='ca_tooltip' title='Click to go to the plugin settings' href='$pluginSettings'><img src='/plugins/community.applications/images/WebPage.png' height='25px'></a>" : "";
      } else {
        $buttonTitle = $template['MyPath'] ? "Reinstall Plugin" : "Install Plugin";
        $template['display_pluginInstall'] = "<input class='ca_tooltip' type='button' value='$buttonTitle' style='margin:0px' title='Click to install this plugin' onclick=installPlugin('".$template['PluginURL']."');>";
        $template['display_pluginInstallIcon'] = "<a style='cursor:pointer' class='ca_tooltip' title='Click to install this plugin' onclick=installPlugin('".$template['PluginURL']."');><img src='/plugins/community.applications/images/install.png' height='25px'></a>";
      }
    } else {
      if ( $communitySettings['dockerRunning'] ) {
        if ( $selected ) {
          $template['display_dockerDefault']     = "<input class='ca_tooltip' type='submit' value='Default' style='margin:1px' title='Click to reinstall the application using default values' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."'>";
          $template['display_dockerEdit']        = "<input class='ca_tooltip' type='submit' value='Edit' style='margin:1px' title='Click to edit the application values' formtarget='$tabMode' formmethod='post' formaction='UpdateContainer?xmlTemplate=edit:".addslashes($info[$name]['template'])."'>";
          $template['display_dockerDefault']     = $template['BranchID'] ? "<input class='ca_tooltip' type='button' style='margin:0px' title='Click to reinstall the application using default values' value='Add' onclick='displayTags(&quot;$ID&quot;);'>" : $template['display_dockerDefault'];
          $template['display_dockerDefaultIcon'] = "<a class='ca_tooltip' title='Click to reinstall the application using default values' href='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."' target='$tabMode'><img src='/plugins/community.applications/images/install.png' height='25px'></a>";
          $template['display_dockerEditIcon']    = "<a class='ca_tooltip' title='Click to edit the application values' href='UpdateContainer?xmlTemplate=edit:".addslashes($info[$name]['template'])."' target='$tabMode'><img src='/plugins/community.applications/images/edit.png' height='25px'></a>";
          if ( $info[$name]['url'] && $info[$name]['running'] ) {
            $template['dockerWebIcon'] = "<a class='ca_tooltip' href='{$info[$name]['url']}' target='_blank' title='Click To Go To The App&#39;s UI'><img src='/plugins/community.applications/images/WebPage.png' height='25px'></a>&nbsp;&nbsp;";
          }
        } else {
          if ( $template['MyPath'] ) {
            $template['display_dockerReinstall'] = "<input class='ca_tooltip' type='submit' style='margin:0px' title='Click to reinstall the application' value='Reinstall' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=user:".addslashes($template['MyPath'])."'>";
            $template['display_dockerReinstallIcon'] = "<a class='ca_tooltip' title='Click to reinstall' href='UpdateContainer?xmlTemplate=user:".addslashes($template['MyPath'])."' target='$tabMode'><img src='/plugins/community.applications/images/install.png' height='25px'></a>";
            } else {
            $template['display_dockerInstall']   = "<input class='ca_tooltip' type='submit' style='margin:0px' title='Click to install the application' value='Add' formtarget='$tabMode' formmethod='post' formaction='AddContainer?xmlTemplate=default:".addslashes($template['Path'])."'>";
            $template['display_dockerInstall']   = $template['BranchID'] ? "<input class='ca_tooltip' type='button' style='margin:0px' title='Click to install the application' value='Add' onclick='displayTags(&quot;$ID&quot;);'>" : $template['display_dockerInstall'];
            $template['display_dockerInstallIcon'] = "<a class='ca_tooltip' title='Click to install' href='UpdateContainer?xmlTemplate=default:".addslashes($template['Path'])."' target='$tabMode'><img src='/plugins/community.applications/images/install.png' height='25px'></a>";
            $template['display_dockerInstallIcon'] = $template['BranchID'] ? "<a style='cursor:pointer' class='ca_tooltip' title='Click to install the application' onclick='displayTags(&quot;$ID&quot;);'><img src='/plugins/community.applications/images/install.png' height='25px'></a>" : $template['display_dockerInstallIcon'];
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
    $template['display_author'] = "<a class='ca_tooltip' style='cursor:pointer' onclick='authorSearch(this.innerHTML);' title='Search for more applications from {$template['SortAuthor']}'>".$template['Author']."</a>";
    $displayIcon = $template['Icon'];
    $displayIcon = $displayIcon ? $displayIcon : "/plugins/$plugin/images/question.png";
    $template['display_iconSmall'] = "<a onclick='showDesc(".$template['ID'].",&#39;".$name."&#39;);' style='cursor:pointer'><img class='ca_appPopup' data-appNumber='$ID' title='Click to display full description' src='".$displayIcon."' style='width:48px;height:48px;' onError='this.src=\"/plugins/$plugin/images/question.png\";'></a>";
    $template['display_iconSelectable'] = "<img src='$displayIcon' onError='this.src=\"/plugins/$plugin/images/question.png\";' style='width:".$iconSize."px;height=".$iconSize."px;'>";
    $template['display_popupDesc'] = ( $communitySettings['maxColumn'] > 2 ) ? "Click for a full description\n".$template['PopUpDescription'] : "Click for a full description";
    $template['display_dateUpdated'] = $template['Date'] ? "</b></strong><center><strong>Date Updated: </strong>".$template['display_humanDate']."</center>" : "";
    $template['display_iconClickable'] = "<a class='ca_appPopup' data-appNumber='$ID' style='cursor:pointer' title='".$template['display_popupDesc']."'>".$template['display_iconSelectable']."</a>";

    if ( $communitySettings['dockerSearch'] == "yes" && ! $template['Plugin'] ) {
      $template['display_dockerName'] = "<a class='ca_tooltip' data-appNumber='$ID' style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search dockerHub for similar containers'>".$template['Name']."</a>";
    } else {
      $template['display_dockerName'] = $template['Name'];
    }
    $template['Category'] = ($template['Category'] == "UNCATEGORIZED") ? "Uncategorized" : $template['Category'];

    if ( ( $template['Beta'] == "true" ) ) {
      $template['display_dockerName'] .= "<span class='ca_tooltip' title='Beta Container &#13;See support forum for potential issues'><font size='1' color='red'><strong>(beta)</strong></font></span>";
    }

    $t .= vsprintf($displayTemplate,toNumericArray($template));

    $columnNumber=++$columnNumber;

    if ( $communitySettings['viewMode'] == "icon" ) {
      if ( $columnNumber == $communitySettings['maxColumn'] ) {
        $columnNumber = 0;
        $t .= vsprintf($skin[$viewMode]['eol'],$templateFormatArray);
      }
    } else {
      $columnNumber = 0;
      $t .= vsprintf($skin[$viewMode]['eol'],$templateFormatArray);
    }
 
    $ct .= $t;
    $count++;
    if ( ! $officialFlag ) {
      if ( $count == $communitySettings['maxPerPage'] ) {
        break;
      }
    }
  }
  $ct .= vsprintf($skin[$viewMode]['footer'],$templateFormatArray);
  $ct .= caGetMode();
  if ( ! $officialFlag ) {
    $ct .= "<br>".getPageNavigation($pageNumber,count($file),false)."<br><br><br>";
  }

  return $ct;
}

function getPageNavigation($pageNumber,$totalApps,$dockerSearch) {
  global $communitySettings;
  
  if ( $communitySettings['maxPerPage'] < 0 ) { return; }
  
  $my_function = $dockerSearch ? "dockerSearch" : "changePage";
  if ( $dockerSearch ) {
    $communitySettings['maxPerPage'] = 25;
  }
  $totalPages = ceil($totalApps / $communitySettings['maxPerPage']);

  if ($totalPages == 1) {
    return;
  }
  $startApp = ($pageNumber - 1) * $communitySettings['maxPerPage'] + 1;
  $endApp = $pageNumber * $communitySettings['maxPerPage'];
  if ( $endApp > $totalApps ) {
    $endApp = $totalApps;
  }
  $o = "<center><font color='purple'><b>";
  if ( ! $dockerSearch ) {
    $o .= "Displaying $startApp - $endApp (of $totalApps)<br>";
  }
  $o .= "Select Page:&nbsp;&nbsp&nbsp;";
  
  $previousPage = $pageNumber - 1;
  $o .= ( $pageNumber == 1 ) ? "<font size='3' color='grey'><i class='fa fa-arrow-circle-left' aria-hidden='true'></i></font>" : "<font size='3' color='green'><i class='fa fa-arrow-circle-left' aria-hidden='true' style='cursor:pointer' onclick='{$my_function}(&quot;$previousPage&quot;)' title='Go To Page $previousPage'></i></font>";
  $o .= "&nbsp;&nbsp;&nbsp;";
  $startingPage = $pageNumber - 5;
  if ($startingPage < 3 ) {
    $startingPage = 1;
  } else {
    $o .= "<b><a style='cursor:pointer' onclick='{$my_function}(&quot;1&quot;);' title='Go To Page 1'>1</a></b>&nbsp;&nbsp;&nbsp;...&nbsp;&nbsp;&nbsp;";
  }
  $endingPage = $pageNumber + 5;
  if ( $endingPage > $totalPages ) {
    $endingPage = $totalPages;
  }
  for ($i = $startingPage; $i <= $endingPage; $i++) {
    if ( $i == $pageNumber ) {
      $o .= "$i";
    } else {
      $o .= "<b><a style='cursor:pointer' onclick='{$my_function}(&quot;$i&quot;);' title='Go To Page $i'>$i</a></b>";
    }
    $o .= "&nbsp;&nbsp;&nbsp";
  }
  if ( $endingPage != $totalPages) {
    if ( ($totalPages - $pageNumber ) > 6){
      $o .= "...&nbsp;&nbsp;&nbsp;";
    }
    if ( ($totalPages - $pageNumber ) >5 ) {
      $o .= "<b><a style='cursor:pointer' title='Go To Page $totalPages' onclick='{$my_function}(&quot;$totalPages&quot;);'>$totalPages</a></b>&nbsp;&nbsp;&nbsp;";
    }
  }
  $nextPage = $pageNumber + 1;
  $o .= ( $pageNumber < $totalPages ) ? "<font size='3' color='green'><i class='fa fa-arrow-circle-right' aria-hidden='true' style='cursor:pointer' title='Go To Page $nextPage' onclick='{$my_function}(&quot;$nextPage&quot;);'></i></font>" : "<font size='3' color='grey'><i class='fa fa-arrow-circle-right' aria-hidden='true'></i></font>";
  $o .= "</font></b></center><span id='currentPageNumber' hidden>$pageNumber</span>";

  return $o;
}

#############################
#                           #
# Selects an app of the day #
#                           #
#############################
function appOfDay($file) {
  global $communityPaths, $info;
  
  $oldAppDay = @filemtime($communityPaths['appOfTheDay']);
  $oldAppDay = $oldAppDay ? $oldAppDay : 1;
  $oldAppDay = intval($oldAppDay / 86400);
  $currentDay = intval(time() / 86400);
  if ( $oldAppDay == $currentDay ) {
    $app = readJsonFile($communityPaths['appOfTheDay']);
  }
  if ( count($app) < 9 ) { unset($app); }  # For update from old version where only 2 possible apps of day
  if ( ! $app ) {
    for ( $ii=0; $ii<10; $ii++ ) {
      $flag = false;
      if ( $app[$ii] ) {
        $flag = checkRandomApp($app[$ii]);
      }
      if ( ! $flag ) {
        while (true ) {
          $randomApp = mt_rand(0,count($file) -1);
          $flag = checkRandomApp($randomApp);
          if ( $flag ) {
            break;
          }
        }
      }
      $app[$ii] = $randomApp;
    }
  }
  writeJsonFile($communityPaths['appOfTheDay'],$app);
  return $app;
}

#####################################################
# Checks selected app for eligibility as app of day #
#####################################################
function checkRandomApp($randomApp) {
  global $file;

  if ( ! $file[$randomApp]['Displayable'] )    return false;
  if ( ! $file[$randomApp]['Compatible'] )     return false;
  if ( $file[$randomApp]['Blacklist'] )        return false;
  if ( $file[$randomApp]['ModeratorComment'] ) return false;
  if ( $file[$randomApp]['Deprecated'] )       return false;
  return true;
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
  return getPageNavigation($pageNumber,$num_pages * 25, true);
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
        $result['Icon'] = $template['IconWeb'];
      }
    }
    $result['display_stars'] = $result['Stars'] ? "<img src='/plugins/$plugin/images/red-star.png' style='height:20px;width:20px'> <strong>".$result['Stars']."</strong>" : "";
    $result['display_official'] =  $result['Official'] ? "<strong><font color=red>Official</font> ".$result['Name']." container.</strong><br><br>": "";
    $result['display_official_short'] = $result['Official'] ? "<font color='red'><strong>Official</strong></font>" : "";

    if ( $viewMode == "icon" ) {
      $t .= "<td>";
      $t .= "<center>".$result['display_official_short']."</center>";

      $t .= "<center>Author: </strong><font size='3'><a class='ca_tooltip' style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For Containers From {$result['Author']}'>{$result['Author']}</a></font></center>";
      $t .= "<center>".$result['display_stars']."</center>";

      $description = "Click to go to the dockerHub website for this container";
      if ( $result['Description'] ) {
        $description = $result['Description']."<br><br>$description";
      }
      $description =str_replace("'","&#39;",$description);
      $description = str_replace('"',"&#34;",$description);
      
      $t .= "<figure><center><a class='ca_tooltip' href='".$result['DockerHub']."' title='$description' target='_blank'>";
      $t .= "<img style='width:".$iconSize."px;height:".$iconSize."px;' src='".$result['Icon']."' onError='this.src=\"/plugins/$plugin/images/question.png\";'></a>";
      $t .= "<figcaption><strong><center><font size='3'><a class='ca_tooltip' style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For Similar Containers'>".$result['Name']."</a></font></center></strong></figcaption></figure>";
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
      $t .= "<tr><td><a class='ca_tooltip' href='".$result['DockerHub']."' target='_blank' title='Click to go to the dockerHub website for this container'>";
      $t .= "<img src='".$result['Icon']."' onError='this.src=\"/plugins/$plugin/images/question.png\";' style='width:".$iconSize."px;height:".$iconSize."px;'>";
      $t .= "</a></td>";
      $t .= "<td><input type='button' value='Add' onclick='dockerConvert(&#39;".$result['ID']."&#39;)';></td>";
      $t .= "<td><a class='ca_tooltip' style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search Similar Containers'>".$result['Name']."</a></td>";
      $t .= "<td><a class='ca_tooltip' style='cursor:pointer' onclick='mySearch(this.innerHTML);' title='Search For More Containers From {$result['Author']}'>{$result['Author']}</a></td>";
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
  $filter      = getPost("filter",false);
  $category    = "/".getPost("category",false)."/i";
  $newApp      = getPost("newApp",false);
  $sortOrder   = getSortOrder(getPostArray("sortOrder"));
  $windowWidth = getPost("windowWidth",false);
  getMaxColumns($windowWidth);

  $newAppTime = strtotime($communitySettings['timeNew']);

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
          echo "<table><td><td colspan='5'><br><center>The following errors occurred:<br><br>";
          echo "<strong>".file_get_contents($communityPaths['updateErrors'])."</strong></center></td></tr></table>";
          echo "<script>$('#templateSortButtons,#total1').hide();$('#sortButtons').hide();</script>";
          echo changeUpdateTime();
          echo caGetMode();
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
    if ( $communitySettings['appOfTheDay'] == "yes" ) {
      $displayApplications = array();
      if ( count($file) > 200) {
        $appsOfDay = appOfDay($file);
        for ($i=0;$i<$communitySettings['maxDetailColumns'];$i++) {
          $displayApplications['community'][] = $file[$appsOfDay[$i]];
        }
        writeJsonFile($communityPaths['community-templates-displayed'],$displayApplications);
        echo "<script>$('#templateSortButtons,#sortButtons').hide();enableIcon('#sortIcon',false);</script>";
        echo "<br><center><font size='4' color='purple'><b>Random Apps Of The Day</b></font><br><br>";
        echo my_display_apps("detail",$displayApplications['community'],$runningDockers,$imagesDocker);
        break;
      }
    } else {
      break;
    }
  }

  $display             = array();
  $official            = array();

  foreach ($file as $template) {
    if ( $template['Blacklist'] ) {
      continue;
    }
    if ( ($communitySettings['hideDeprecated'] == "true") && ($template['Deprecated']) ) {
      continue;                          # ie: only show deprecated apps within previous apps section
    }
    if ( ! $template['Displayable'] ) {
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
    if ( ($newApp == "true") && ($template['Date'] < $newAppTime) )  { continue; }
    if ( $category && ! preg_match($category,$template['Category'])) { continue; }

    if ($filter) {
      if (preg_match("#$filter#i", $template['Name']) || preg_match("#$filter#i", $template['Author']) || preg_match("#$filter#i", $template['Description']) || preg_match("#$filter#i", $template['Repository'])) {
        $template['Description'] = highlight($filter, $template['Description']);
        $template['Author'] = highlight($filter, $template['Author']);
        $template['Name'] = highlight($filter, $template['Name']);
      } else continue;
    }

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

  $displayApplications['official']  = $official;
  $displayApplications['community'] = $display;

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
  $repositoriesLogo = $Repositories;
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
    echo "ok";
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
  echo "ok";
  break;

####################################################################################################
#                                                                                                  #
# force_update_button - forces the system temporarily to override the appFeed and forces an update #
#                                                                                                  #
####################################################################################################
case 'force_update_button':
  if ( ! is_file($communityPaths['LegacyMode']) ) {
    file_put_contents($communityPaths['appFeedOverride'],"dunno");
  }
  @unlink($infoFile);
  echo "ok";
  break;

####################################################################################
#                                                                                  #
# display_content - displays the templates according to view mode, sort order, etc #
#                                                                                  #
####################################################################################
case 'display_content':
  $sortOrder = getSortOrder(getPostArray('sortOrder'));
  $windowWidth = getPost("windowWidth",false);
  $pageNumber = getPost("pageNumber","1");
  
  getMaxColumns($windowWidth);
  
  if ( file_exists($communityPaths['community-templates-displayed']) ) {
    display_apps($sortOrder['viewMode'],$pageNumber);
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

  $moderation = ( is_file($communityPaths['moderation']) ) ? readJsonFile($communityPaths['moderation']) : array();
  
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
            if ($template['Blacklist'] ) {
              continue;
            }
            $displayed[] = $template;
            break;
          }
        }
      }
    }
    $all_files = dirContents("/boot/config/plugins/dockerMan/templates-user");

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
            if ( $o['Blacklist'] ) {
              continue;
            }
            $displayed[] = $o;
          }
        }
      }
    }
  } else {
# now get the old not installed docker apps

    $all_files = dirContents("/boot/config/plugins/dockerMan/templates-user");

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
          if ( $moderation[$o['Repository']]['Blacklist'] ) {
            continue;
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
        if ( $template['Blacklist'] ) {
          continue;
        }
        $template['MyPath'] = "/var/log/plugins/$filename";
        $template['Uninstall'] = true;
        $template['UpdateAvailable'] = checkPluginUpdate($filename);

        $displayed[] = $template;
      }
    }
  } else {
    $all_plugs = dirContents("/boot/config/plugins-removed/");

    foreach ($all_plugs as $oldplug) {
      foreach ($file as $template) {
        if ( $oldplug == pathinfo($template['Repository'],PATHINFO_BASENAME) ) {
          if ( ! file_exists("/boot/config/plugins/$oldplug") ) {
            if ( $template['Blacklist'] ) {
              continue;
            }
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

###################################################################################
#                                                                                 #
# Checks for an update still available (to update display) after update installed #
#                                                                                 #
###################################################################################
case 'updatePLGstatus':
  $filename = getPost("filename","");
  $displayed = readJsonFile($communityPaths['community-templates-displayed']);
  $superCategories = array_keys($displayed);
  foreach ($superCategories as $category) {
    foreach ($displayed[$category] as $template) {
      if ( strpos($template['PluginURL'],$filename) ) {
        $template['UpdateAvailable'] = checkPluginUpdate($filename);
      }
      $newDisplayed[$category][] = $template;
    }
  }
  writeJsonFile($communityPaths['community-templates-displayed'],$newDisplayed);
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

################################################
#                                              #
# Displays the possible branch tags for an app #
#                                              #
################################################
case 'displayTags':
  $leadTemplate = getPost("leadTemplate","oops");
  $file = readJsonFile($communityPaths['community-templates-info']);
  $template = $file[$leadTemplate];
  $childTemplates = $file[$leadTemplate]['BranchID'];
  if ( ! is_array($childTemplates) ) {
    echo "Something really went wrong here";
  } else {
    $defaultTag = $template['BranchDefault'] ? $template['BranchDefault'] : "latest";
    echo "<table>";
    echo "<tr><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td><td><a href='AddContainer?xmlTemplate=default:".$template['Path']."' target='".$communitySettings['newWindow']."'>Default</a></td><td>Install Using The Template's Default Tag (<font color='purple'>:$defaultTag</font>)</td></tr>";
    foreach ($childTemplates as $child) {
      echo "<tr><td>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</td><td><a href='AddContainer?xmlTemplate=default:".$file[$child]['Path']."' target='".$communitySettings['newWindow']."'>".$file[$child]['BranchName']."</a></td><td>".$file[$child]['BranchDescription']."</td></tr>";
    }
    echo "</table>";
  }
  break;
  
################################################
#                                              #
# Specialized search for additional CA Modules #
#                                              #
################################################
case 'populateModules':
  $file = readJsonFile($communityPaths['community-templates-info']);
  foreach ($file as $template) {
    if ($template['CA']) {
      if ( ! $template['Compatible'] ) {
        continue;
      }
      $filename = basename($template['PluginURL']);
      if ( is_file("/var/log/plugins/$filename") ) {
        $template['MyPath'] = "/var/log/plugins/$filename";
        $template['Uninstall'] = true;
      }
      $displayed['community'][] = $template;
    }
  }
  writeJsonFile($communityPaths['community-templates-displayed'],$displayed);  
  echo "done";
  break;
  
###########################################
#                                         #
# Displays The Statistics For The Appfeed #
#                                         #
###########################################
case 'statistics':
  $statistics = readJsonFile($communityPaths['statistics']);
  if ( ! $statistics ) { $statistics = array(); }

  $moderation = readJsonFile($communityPaths['moderation']);
  $statistics['totalModeration'] = count($moderation);

  $templates = readJsonFile($communityPaths['community-templates-info']);
  foreach ($templates as $template) {
    if ( $template['Deprecated'] ) {
      $statistics['totalDeprecated']++;
      $deprecated .= "<tr><td>{$template['Repo']}</td><td><b>{$template['Name']}</b></td><td>".str_replace("<br>","",trim($template['ModeratorComment']))."</td></tr>";
    }
    if ( ! $template['Compatible'] ) {
      $statistics['totalIncompatible']++;
      $incompatible .= "<td>{$template['Repo']}</td><td><b>{$template['Name']}</b></td><td><center>{$template['MinVer']}</td><td><center>{$template['MaxVer']}</td></tr>";
    }
  }
  if ( $incompatible ) {
    $incompatible = "<table style='width:auto;border:1px;border-color:red;'><thead><td>Repository</td><td>Application</td><td>Minimum OS</td><td>Maximum OS</td></thead>$incompatible</table>";
    file_put_contents($communityPaths['totalIncompatible_txt'],$incompatible);
  } else {
    @unlink($communityPaths['totalIcompatible_txt']);
  }
  if ( $deprecated ) {
    $deprecated = "<table style='width:auto'><thead><td style='width:25%'>Repository</td><td>Application</td><td>Comment</td></thead>$deprecated</table>";
    file_put_contents($communityPaths['totalDeprecated_txt'],$deprecated);
  } else {
    @unlink($communityPaths['totalDeprecated_txt']);
  }
  foreach ($statistics as &$stat) {
    if ( ! $stat ) { $stat = "1"; }
  }
  if ( $statistics['fixedTemplates'] ) {
    writeJsonFile($communityPaths['fixedTemplates_txt'],$statistics['fixedTemplates']);
  } else {
    @unlink($communityPaths['fixedTemplates_txt']);
  }
  if ( is_file($communityPaths['lastUpdated-old']) ) {
    $appFeedTime = readJsonFile($communityPaths['lastUpdated-old']);
  } else {
    $appFeedTime['last_updated_timestamp'] = filemtime($communityPaths['community-templates-info']);
  }
  $updateTime = date("F d Y H:i",$appFeedTime['last_updated_timestamp']);
  $updateTime = ( is_file($communityPaths['LegacyMode']) ) ? "N/A - Legacy Mode Active" : $updateTime;
  $defaultArray = Array('caFixed' => 0,'totalApplications' => 0, 'repository' => 0, 'docker' => 0, 'plugin' => 0, 'invalidXML' => 0, 'blacklist' => 0, 'totalIncompatible' =>0, 'totalDeprecated' => 0, 'totalModeration' => 0, 'private' => 0);
  $statistics = array_merge($defaultArray,$statistics);  
  foreach ($statistics as &$stat) {
    if ( ! $stat ) {
      $stat = "0";
    }
  }
  
  $color = "<font color='coral'>";
  echo "<div style='overflow:scroll; max-height:550px; height:550px; overflow-x:hidden; overflow-y:auto;'><center><img src='/plugins/community.applications/images/CA.png'><br><font size='6' color='white'>Community Applications</font><br><br>";
  echo "<center><font size='3'>Application Feed Statistics</font></center><br><br>";
  echo "<table>";
  echo "<tr>";
  echo "<td><b>{$color}Application List Current As Of</b></td><td>$color$updateTime</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}Total Number Of Templates</b></td><td>$color{$statistics['totalApplications']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}Total Number Of Repositories</b></td><td>$color{$statistics['repository']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}Total Number Of Docker Applications</b></td><td>$color{$statistics['docker']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}Total Number Of Plugins</b></td><td>$color{$statistics['plugin']}</td>";
  echo "</tr><tr>";
  echo "<td>{$color}<a href='/Main/Browse?dir=/boot/config/plugins/community.applications/private' target='_blank'><b>Total Number Of Private Docker Applications</b></a></td><td>$color{$statistics['private']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}Total Number Of Invalid Templates</b></td><td>$color{$statistics['invalidXML']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}<a onclick='showModeration(&quot;showFixed.php&quot;,&quot;Apps with template errors automatically fixed&quot;);' style='cursor:pointer'>Total Number Of Template Errors Fixed Automatically</a></b></td><td>$color{$statistics['caFixed']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}<a onclick='showModeration(&quot;showBlacklist.php&quot;,&quot;Total Blacklisted Apps Still In Appfeed&quot;);' style='cursor:pointer'>Total Number Of Blacklisted Apps Found In Appfeed</a></b></td><td>$color{$statistics['blacklist']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}<a onclick='showModeration(&quot;showIncompatible.php&quot;,&quot;Total Incompatible Apps Found&quot;);' style='cursor:pointer'>Total Number Of Incompatible Applications</a></b></td><td>$color{$statistics['totalIncompatible']}</td>";
  echo "</tr><tr>";
  echo "<td title='You can only install a deprecated application if you have previously installed it'><b>{$color}<a onclick='showModeration(&quot;showDeprecated.php&quot;,&quot;Total Deprecated Applications&quot;);' style='cursor:pointer'>Total Number Of Deprecated Applications</a></b></td><td>$color{$statistics['totalDeprecated']}</td>";
  echo "</tr><tr>";
  echo "<td><b>{$color}<a onclick='showModeration(&quot;showModeration.php&quot;,&quot;All Moderation Entries&quot;);' style='cursor:pointer'>Total Number Of Moderation Entries</a></b></td><td>$color{$statistics['totalModeration']}</td>";
  echo "</tr>";
  echo "</table>";
  echo "<center><a href='https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=7M7CBCVU732XG' target='_blank'><img height='25px' src='https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif'></a></center>";
  echo "<center>Ensuring only safe applications are present is a full time job</center><br>";
  break;

#####################################################################################
#                                                                                   #
# Updates The maxPerPage setting (maxPerPage is already grabbed globally from POST) #
#                                                                                   #
#####################################################################################
case 'changeSettings':
  file_put_contents($communityPaths['pluginSettings'],create_ini_file($communitySettings,false));
  echo "settings updated";
  break;
  
##########################################
#                                        #
# Updates the viewMode for next instance #
#                                        #
##########################################
case 'changeViewModeSettings':
  $communitySettings['viewMode'] = getPost("view",$communitySettings['viewMode']);
  file_put_contents($communityPaths['pluginSettings'],create_ini_file($communitySettings,false));
  echo "ok";
  break;
}
?>