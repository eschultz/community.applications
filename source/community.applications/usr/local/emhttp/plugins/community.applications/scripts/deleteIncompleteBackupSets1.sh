#!/bin/bash
echo "Deleting Incomplete Backup Sets" > /tmp/community.applications/tempFiles/deleteInProgress
cd "$1"
rm -rfv *-error >> /var/lib/docker/unraid/community.applications.datastore/appdata_backup.log
rm /tmp/community.applications/tempFiles/deleteInProgress
