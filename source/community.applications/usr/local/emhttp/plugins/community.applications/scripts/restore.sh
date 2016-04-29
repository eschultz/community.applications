#!/bin/bash

echo "/usr/local/emhttp/plugins/community.applications/scripts/restore.php & > /dev/null " | at -M NOW >/dev/null 2>&1

