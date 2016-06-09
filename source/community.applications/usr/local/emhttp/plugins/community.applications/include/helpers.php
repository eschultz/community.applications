<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

require_once("/usr/local/emhttp/plugins/dynamix/include/Wrappers.php");

###########################################################################
#                                                                         #
# Helper function to determine if a plugin has an update available or not #
#                                                                         #
###########################################################################

function checkPluginUpdate($filename) {
  $filename = basename($filename);
  $installedVersion = exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin version /var/log/plugins/$filename");
  if ( is_file("/tmp/plugins/$filename") ) {
    $upgradeVersion = exec("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin version /tmp/plugins/$filename");
  } else {
    $upgradeVersion = "0";
  }
  if ( $installedVersion < $upgradeVersion ) {
    return true;
  } else {
    return false;
  }
}
###################################################
#                                                 #
# Converts a file size to a human readable string #
#                                                 #
###################################################

function human_filesize($bytes, $decimals = 2) {
  $size = array(' B',' KB',' MB',' GB',' TB',' PB',' EB',' ZB',' YB');
  $factor = floor((strlen($bytes) - 1) / 3);
  return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
}

###################################################################################
#                                                                                 #
# returns a random file name (/tmp/community.applications/tempFiles/34234234.tmp) #
#                                                                                 #
###################################################################################
function randomFile() {
  global $communityPaths;
  while (true) {
    $filename = $communityPaths['tempFiles']."/".mt_rand().".tmp";
    if ( ! is_file($filename) ) {
      break;
    }
  }
  return $filename;
}

##################################################################
#                                                                #
# 2 Functions to avoid typing the same lines over and over again #
#                                                                #
##################################################################

function readJsonFile($filename) {
  return json_decode(@file_get_contents($filename),true);
}

function writeJsonFile($filename,$jsonArray) {
  file_put_contents($filename,json_encode($jsonArray, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

###############################################
#                                             #
# Helper function to download a URL to a file #
#                                             #
###############################################

function download_url($url, $path = "", $bg = false){
  exec("curl --max-time 60 --silent --insecure --location --fail ".($path ? " -o '$path' " : "")." $url ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code );
  return ($exit_code === 0 ) ? implode("\n", $out) : false;
}

########################################################
#                                                      #
# Helper function to get the plugin's launch entitity. #
#                                                      #
########################################################
 
function getPluginLaunch($pluginName) {
  return plugin("launch","/var/log/plugins/$pluginName");
}

#################################################################
#                                                               #
# Helper function to determine if $haystack begins with $needle #
#                                                               #
#################################################################

function startsWith($haystack, $needle) {
  return $needle === "" || strripos($haystack, $needle, -strlen($haystack)) !== FALSE;
}
#######################################################################################
#                                                                                     #
# Helper function to further remove formatting from descriptions (suitable for popUps #
#                                                                                     #
#######################################################################################

function fixPopUpDescription($PopUpDescription) {
  $PopUpDescription = str_replace("'","&#39;",$PopUpDescription);
  $PopUpDescription = str_replace('"','&quot;',$PopUpDescription);
  $PopUpDescription = strip_tags($PopUpDescription);
  $PopUpDescription = trim($PopUpDescription);
  return ($PopUpDescription);
}

###################################################################
#                                                                 #
# Helper function to remove any formatting, etc from descriptions #
#                                                                 #
###################################################################

function fixDescription($Description) {
  $Description = preg_replace("#\[br\s*\]#i", "{}", $Description);
  $Description = preg_replace("#\[b[\\\]*\s*\]#i", "||", $Description);
  $Description = preg_replace('#\[([^\]]*)\]#', '<$1>', $Description);
  $Description = preg_replace("#<span.*#si", "", $Description);
  $Description = preg_replace("#<[^>]*>#i", '', $Description);
  $Description = preg_replace("#"."{}"."#i", '<br>', $Description);
  $Description = preg_replace("#"."\|\|"."#i", '<b>', $Description);
  $Description = str_replace("&lt;","<",$Description);
  $Description = str_replace("&gt;",">",$Description);
  $Description = strip_tags($Description);
  $Description = trim($Description);
  return $Description;
}

########################################################################
#                                                                      #
# Security function to remove any <script> tags from elements that are #
# displayed as is                                                      #
#                                                                      #
########################################################################

function fixSecurity(&$template) {
  foreach ($template as &$element) {
    if ( is_array($element) ) {
      fixSecurity ($element);
    } else {
      $element = preg_replace('#<script(.*?)>(.*?)</script>#is','',$element);
    }
  }  
}

#######################
#                     #
# Custom sort routine #
#                     #
#######################

function mySort($a, $b) {
  global $sortKey, $sortDir;

  if ( $sortKey != "Downloads" ) {
    $c = strtolower($a[$sortKey]);
    $d = strtolower($b[$sortKey]);
  } else {
    $c = $a[$sortKey];
    $d = $b[$sortKey];
  }

  $return1 = ($sortDir == "Down") ? -1 : 1;
  $return2 = ($sortDir == "Down") ? 1 : -1;

  if ($c > $d) { return $return1; }
  else if ($c < $d) { return $return2; }
  else { return 0; }
}


###############################################
#                                             #
# Search array for a particular key and value #
# returns the index number of the array       #
# return value === false if not found         #
#                                             #
###############################################

function searchArray($array,$key,$value) {
  if ( function_exists("array_column") && function_exists("array_search") ) {   # faster to use built in if it works
    $result = array_search($value, array_column($array, $key));   
  } else {
    $result = false;
    for ($i = 0; $i <= max(array_keys($array)); $i++) {
      if ( $array[$i][$key] == $value ) {
        $result = $i;
        break;
      }
    }
  }
  
  return $result;
}

##############################################################
#                                                            #
# Searches an array of docker mappings (host:container path) #
# for a container mapping of /config and returns the host    #
# path                                                       #
#                                                            #
##############################################################

function findAppdata($volumes) {
  $path = false;
  if ( is_array($volumes) ) {
    foreach ($volumes as $volume) {
      $temp = explode(":",$volume);
      $testPath = strtolower($temp[1]);
    
      if (startsWith($testPath,"/config") ) {
        $path = $temp[0];
        break;
      }
    }
  }
  return $path;
}

#############################
#                           #
# Highlights search results #
#                           #
#############################

function highlight($text, $search) {
  return preg_replace('#'. preg_quote($text,'#') .'#si', '<span style="background-color:#FFFF66; color:#FF0000;font-weight:bold;">\\0</span>', $search);
}

########################################################
#                                                      #
# Fix common problems (maintainer errors) in templates #
#                                                      #
########################################################

function fixTemplates($template) {
  if ( is_array($template['Support']) ) {
    unset($template['Support']);
  }
  
  if ( is_array($template['Beta']) ) {
    $template['Beta'] = "false";
  } else {
    $template['Beta'] = strtolower(stripslashes($template['Beta']));
  }
  $template['Date'] = ( $template['Date'] ) ? strtotime( $template['Date'] ) : 0;

  if ( ! $template['MinVer'] ) {
    if ( $template['Plugin'] ) {
      $template['MinVer'] = "6.1";
    } else {
      $template['MinVer'] = "6.0";
    }
  }
  if ( ! is_string($template['Description']) ) {
    $template['Description'] = "";
  }
  if ( $template['Category'] == "" ) {
    $template['Category'] = "Uncategorized";
  }
  if ( !is_string($template['Overview']) ) {
    unset($template['Overview']);
  }
  if ( ! is_string($template['Category']) ) {
    $template['Category'] = "";
  }
  
  if ( strlen($template['Overview']) > 0 ) {
    $template['Description'] = $template['Overview'];
    $template['Description'] = preg_replace('#\[([^\]]*)\]#', '<$1>', $template['Description']);
    $template['Description'] = fixDescription($template['Description']);
    $template['Overview'] = $template['Description'];
  } else {
    $template['Description'] = fixDescription($template['Description']);
  }
  if ( ( stripos($template['RepoName'],' beta') > 0 )  ) {
    $template['Beta'] = "true";
  }

  $template['Support'] = validURL($template['Support']);
  $template['Project'] = validURL($template['Project']);

  
  # support v6.2 redefining deprecating the <Beta> tag and moving it to a category
  if ( stripos($template['Category'],":Beta") ) {
    $template['Beta'] = "true";
  } else {
    if ( $template['Beta'] === "true" ) {
      $template['Category'] .= " Status:Beta";
    }
  }
  $template['PopUpDescription'] = fixPopUpDescription($template['Description']);

  return $template;
}


###############################################################
#                                                             #
# Function used to create XML's from appFeeds                 #
# NOTE: single purpose, brute force creation of XML templates #
#                                                             #
###############################################################

function makeXML($template) {
  # handle the case where there is only a single <Config> entry
  
  if ( $template['Config']['@attributes'] ) {
    $template['Config'][0]['@attributes'] = $template['Config']['@attributes'];
    unset($template['Config']['@attributes']);
  }

  # hack to fix differing schema in the appfeed vs what Array2XML class wants

  if ( $template['Config'] ) {
    foreach ($template['Config'] as $tempArray) {
      if ( $tempArray['value'] ) {
        $tempArray2[] = array('@attributes'=>$tempArray['@attributes'],'@value'=>$tempArray['value']);
      } else {
        $tempArray2[] = array('@attributes'=>$tempArray['@attributes']);
      }
    }
    $template['Config'] = $tempArray2;
  }
  $Array2XML = new Array2XML();
  $xml = $Array2XML->createXML("Container",$template);
  return $xml->saveXML();
}
  
###################################################################################
#                                                                                 #
# Changes the HTML code to reflect the last time the application list was updated #
#                                                                                 #
###################################################################################

function changeUpdateTime() {
  global $communityPaths;

  if ( is_file($communityPaths['lastUpdated-old']) ) {
    $appFeedTime = readJsonFile($communityPaths['lastUpdated-old']);
  } else {
    $appFeedTime['last_updated_timestamp'] = filemtime($communityPaths['community-templates-info']);
  }

  $updateTime = date("F d Y H:i",$appFeedTime['last_updated_timestamp']);

  return "<script>$('#updateTime').html('$updateTime');</script>";
}

#################################################################
#                                                               #
# checks the Min/Max version of an app against unRaid's version #
# Returns: TRUE if it's valid to run, FALSE if not              #
#                                                               #
#################################################################

function versionCheck($template) {
  global $unRaidVersion;

  if ( $template['MinVer'] ) {
    if ( version_compare($template['MinVer'],$unRaidVersion) > 0 ) { return false; }
  }

  if ( $template['MaxVer'] ) {
    if ( version_compare($template['MaxVer'],$unRaidVersion) < 0 ) { return false; }
  }

  return true;
}


###############################################
#                                             #
# Function to read a template XML to an array #
#                                             #
###############################################

function readXmlFile($xmlfile) {
  $doc = new DOMDocument();
  $doc->load($xmlfile);
  if ( ! $doc ) { return false; }
  
  $o['Path']        = $xmlfile;
  $o['Repository']  = stripslashes($doc->getElementsByTagName( "Repository" )->item(0)->nodeValue);
  $o['Author']      = preg_replace("#/.*#", "", $o['Repository']);
  $o['Name']        = stripslashes($doc->getElementsByTagName( "Name" )->item(0)->nodeValue);
  $o['DockerHubName'] = strtolower($o['Name']);
  $o['Beta']        = strtolower(stripslashes($doc->getElementsByTagName( "Beta" )->item(0)->nodeValue));
  $o['Changes']     = $doc->getElementsByTagName( "Changes" )->item(0)->nodeValue;
  $o['Date']        = $doc->getElementsByTagName( "Date" ) ->item(0)->nodeValue;
  $o['Project']     = $doc->getElementsByTagName( "Project" ) ->item(0)->nodeValue;
  $o['SortAuthor']  = $o['Author'];
  $o['SortName']    = $o['Name'];
  $o['MinVer']      = $doc->getElementsByTagName( "MinVer" ) ->item(0)->nodeValue;
  $o['MaxVer']      = $doc->getElementsByTagName( "MaxVer" ) ->item(0)->nodeValue;
  $o['Overview']    = $doc->getElementsByTagName("Overview")->item(0)->nodeValue;
  if ( strlen($o['Overview']) > 0 ) {
    $o['Description'] = stripslashes($doc->getElementsByTagName( "Overview" )->item(0)->nodeValue);
    $o['Description'] = preg_replace('#\[([^\]]*)\]#', '<$1>', $o['Description']);
  } else {
    $o['Description'] = $doc->getElementsByTagName( "Description" )->item(0)->nodeValue;
    $o['Description'] = fixDescription($o['Description']);
  }
  $o['Plugin']      = $doc->getElementsByTagName( "Plugin" ) ->item(0)->nodeValue;
  $o['PluginURL']   = $doc->getElementsByTagName( "PluginURL" ) ->item(0)->nodeValue;
  $o['PluginAuthor']= $doc->getElementsByTagName( "PluginAuthor" ) ->item(0)->nodeValue;

# support both spellings
  $o['Licence']     = $doc->getElementsByTagName( "License" ) ->item(0)->nodeValue;
  $o['Licence']     = $doc->getElementsByTagName( "Licence" ) ->item(0)->nodeValue;
  $o['Category']    = $doc->getElementsByTagName ("Category" )->item(0)->nodeValue;

  if ( $o['Plugin'] ) {
    $o['Author']     = $o['PluginAuthor'];
    $o['Repository'] = $o['PluginURL'];
    $o['Category']   .= " Plugins: ";
    $o['SortAuthor'] = $o['Author'];
    $o['SortName']   = $o['Name'];
  }
  $o['Description'] = preg_replace('#\[([^\]]*)\]#', '<$1>', $o['Description']);
  $o['Overview']    = $doc->getElementsByTagName("Overview")->item(0)->nodeValue;

  $o['Announcement'] = $Repo['forum'];
  $o['Support']     = ($doc->getElementsByTagName( "Support" )->length ) ? $doc->getElementsByTagName( "Support" )->item(0)->nodeValue : $Repo['forum'];
  $o['Support']     = $o['Support'];
  $o['IconWeb']     = stripslashes($doc->getElementsByTagName( "Icon" )->item(0)->nodeValue);

  return $o;
}


###################################################################
#                                                                 #
# Function To Merge Moderation into templates array               #
# (Because moderation can be updated when templates are not )     #
# If appfeed is updated, this is done when creating the templates #
#                                                                 #
###################################################################

function moderateTemplates() {
  global $communityPaths;
  
  $templates = readJsonFile($communityPaths['community-templates-info']);
  $moderation = readJsonFile($communityPaths['moderation']);
  
  foreach ($templates as $template) {
    if ( is_array($moderation[$template['Repository']]) ) {
      $o[] = array_merge($template,$moderation[$template['Repository']]);
    } else {
      $o[] = $template;
    }
  }
  writeJsonFile($communityPaths['community-templates-info'],$o);
}

############################################
#                                          #
# Function to write a string to the syslog #
#                                          #
############################################

function logger($string) {
  shell_exec("logger '$string'");
}

###########################################
#                                         #
# Function to send a dynamix notification #
#                                         #
###########################################

function notify($event,$subject,$description,$message="",$type="normal") {
  $command = '/usr/local/emhttp/plugins/dynamix/scripts/notify -e "'.$event.'" -s "'.$subject.'" -d "'.$description.'" -m "'.$message.'" -i "'.$type.'"';
  shell_exec($command);
}

#######################################################
#                                                     #
# Function to convert a Linux text file to dos format #
#                                                     #
#######################################################

function toDOS($input,$output,$append = false) {
  if ( $append == false ) {
    shell_exec('/usr/bin/todos < "'.$input.'" > "'.$output.'"');
  } else {
    shell_exec('/usr/bin/todos < "'.$input.'" >> "'.$output.'"');
  }
}

#######################################################
#                                                     #
# Function to check for a valid URL                   #
#                                                     #
#######################################################

function validURL($URL) {
  if ( function_exists("filter_var") ) {  # function only works on unRaid 6.1.8+
    return filter_var($URL, FILTER_VALIDATE_URL);
  } else {
    return $URL;
  }
}
?>
