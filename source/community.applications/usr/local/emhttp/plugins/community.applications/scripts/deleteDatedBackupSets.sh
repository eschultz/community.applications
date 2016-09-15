#!/bin/bash

echo "/usr/local/emhttp/plugins/community.applications/scripts/deleteDatedBackupSets.php & > /dev/null " | at -M NOW >/dev/null 2>&1

