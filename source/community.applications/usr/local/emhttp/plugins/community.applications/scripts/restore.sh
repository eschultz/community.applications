#!/bin/bash

echo "/usr/local/emhttp/plugins/community.applications/scripts/backup.php  restore & > /dev/null " | at -M NOW >/dev/null 2>&1

