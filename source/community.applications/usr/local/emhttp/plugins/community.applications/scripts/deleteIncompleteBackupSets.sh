#!/bin/bash

echo "/usr/local/emhttp/plugins/community.applications/scripts/deleteIncompleteBackupSets1.sh '$1' & > /dev/null " | at -M NOW >/dev/null 2>&1

