<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

##################################################################################################################################################################################################
#                                                                                                                                                                                                #
# Static Variables.  Note that most paths are stored within /var/lib/docker/unraid, which means that any files are actually stored within the docker.img file and are persistent between reboots #
#                                                                                                                                                                                                #
##################################################################################################################################################################################################

$plugin = "community.applications";

$communityPaths['persistentDataStore']           = "/var/lib/docker/unraid/community.applications.datastore";          /* anything in this folder is NOT deleted upon an update of templates */
$communityPaths['templates-community']           = "/var/lib/docker/unraid/templates-community-apps";                  /* templates and temporary files stored here.  Deleted every update of applications */
$communityPaths['tempFiles']                     = "/tmp/community.applications/tempFiles";                            /* path to temporary files */
$communityPaths['community-templates-url']       = "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Repositories.json";
$communityPaths['community-templates-info']      = $communityPaths['tempFiles']."/templates.json";                     /* json file containing all of the templates */
$communityPaths['community-templates-displayed'] = $communityPaths['tempFiles']."/displayed.json";                     /* json file containing all of the templates currently displayed */
$communityPaths['application-feed']              = "http://tools.linuxserver.io/unraid-docker-templates.json";         /* path to the application feed */
$communityPaths['application-feed-last-updated'] = "http://tools.linuxserver.io/unraid-docker-templates.json?last_updated=1";
$communityPaths['lastUpdated']                   = $communityPaths['tempFiles']."/lastUpdated.json";
$communityPaths['lastUpdated-old']               = $communityPaths['tempFiles']."/lastUpdated-old.json";
$communityPaths['appFeedOverride']               = $communityPaths['tempFiles']."/WhatWouldChodeDo";                   /* flag to override the app feed temporarily */
$communityPaths['addConverted']                  = $communityPaths['tempFiles']."/TrippingTheRift";                    /* flag to indicate a rescan needed since a dockerHub container was added */
$communityPaths['convertedTemplates']            = "/boot/config/plugins/".$plugin."/private/";                        /* path to private repositories on flash drive */
$communityPaths['dockerSearchResults']           = $communityPaths['tempFiles']."/docker_search.json";                 /* The displayed docker search results */
$communityPaths['dockerfilePage']                = $communityPaths['tempFiles']."/dockerfilePage";                     /* the downloaded webpage to scrape the dockerfile from */
$communityPaths['moderationURL']                 = "https://raw.githubusercontent.com/Squidly271/Community-Applications-Moderators/master/Moderation.json";
$communityPaths['moderation']                    = $communityPaths['persistentDataStore']."/moderation.json";          /* json file that has all of the moderation */
$communityPaths['special-repos']                 = "/boot/config/plugins/community.applications/private.repositories.json";
$communityPaths['unRaidVersion']                 = "/etc/unraid-version";
$communityPaths['repositoriesURL']               = "https://raw.githubusercontent.com/Squidly271/repo.update/master/Repositories.json";
$communityPaths['logos']                         = $communityPaths['tempFiles']."/logos.json";
$communityPaths['accept_docker_warning']         = "/boot/config/plugins/community.applications/noDocker-accepted";
$communityPaths['deleteAppdataScript']           = "/usr/local/emhttp/plugins/community.applications/scripts/deleteAppData.sh";
$communityPaths['unRaidVars']                    = "/var/local/emhttp/var.ini";
$communityPaths['appdataSize']                   = $communityPaths['tempFiles']."/appdata/";
$communityPaths['calculateAppdataScript']        = "/usr/local/emhttp/plugins/community.applications/scripts/calculateAppData.sh";
$communityPaths['calculateAppdataProgress']      = $communityPaths['tempFiles']."/appdata/inprogress";
$communityPaths['cAdvisor']                      = $communityPaths['tempFiles']."/cAdvisorURL";                         /* URL of cadvisor (if installed) */
$communityPaths['updateErrors']                  = $communityPaths['tempFiles']."/updateErrors.txt";
$communityPaths['dockerUpdateStatus']            = "/var/lib/docker/unraid-update-status.json";
$communityPaths['autoUpdateSettings']            = "/boot/config/plugins/community.applications/AutoUpdate.json";
$communityPaths['backupOptions']                 = "/boot/config/plugins/community.applications/BackupOptions.json";
$communityPaths['backupProgress']                = $communityPaths['tempFiles']."/backupInProgress";
$communityPaths['restoreProgress']               = $communityPaths['tempFiles']."/restoreInProgress";
$communityPaths['backupLog']                     = $communityPaths['persistentDataStore']."/appdata_backup.log";
$communityPaths['backupScript']                  = "/usr/local/emhttp/plugins/community.applications/scripts/backup.php";
$communityPaths['addCronScript']                 = "/usr/local/emhttp/plugins/community.applications/scripts/addCron.php";
$communityPaths['unRaidDockerSettings']          = "/boot/config/docker.cfg";

$infoFile                                        = $communityPaths['community-templates-info'];
$docker_repos                                    = $communityPaths['template-repos'];

?>
