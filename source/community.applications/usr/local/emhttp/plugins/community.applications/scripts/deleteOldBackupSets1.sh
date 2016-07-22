#!/bin/bash
echo "delete in progress" > /tmp/community.applications/tempFiles/deleteInProgress
rm -rfv "$1" >> /var/lib/docker/unraid/community.applications.datastore/appdata_backup.log
rm /tmp/community.applications/tempFiles/deleteInProgress
