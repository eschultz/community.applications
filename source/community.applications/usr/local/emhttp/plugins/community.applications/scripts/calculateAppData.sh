#!/bin/bash

/usr/local/emhttp/plugins/community.applications/scripts/calculateAppData1.php & > /dev/null | at NOW -M >/dev/null 2>&1

