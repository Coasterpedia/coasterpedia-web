#!/bin/sh

# exec, so the service itself gets the stop signal (it handles SIGTERM) instead
# of this shell, which ignores it until Docker kills the container 10s later.
if [ "${RUNNER_TYPE:-job}" = "Chron" ]; then
   exec /usr/local/bin/php /var/www/html/w/mediawiki-services-jobrunner/redisJobChronService --config-file=/var/www/html/w/mediawiki-services-jobrunner/jobrunner-conf.json
else
   exec /usr/local/bin/php /var/www/html/w/mediawiki-services-jobrunner/redisJobRunnerService --config-file=/var/www/html/w/mediawiki-services-jobrunner/jobrunner-conf.json
fi
