#!/bin/sh
# melytics scheduler — point your host's cron at this file, every minute:
#   /bin/sh /path/to/melytics/cron.sh
# Some shared hosts (Hostinger among them) silently ignore inline
# "cd … && php artisan …" cron commands; a script wrapper always runs.
cd "$(dirname "$0")" && php artisan schedule:run >> storage/logs/cron.log 2>&1
