#!/bin/sh

dir=/var/www/html/w/mediawiki-services-jobrunner
conf=$dir/jobrunner-conf.json

# Log in to Redis as the "coasterpedia" user, as LocalSettings.php does. The JSON
# can't read the environment, so the password goes into a copy on /tmp (tmpfs)
# that only this user can read.
if [ -n "$REDIS_PASSWORD" ]; then
   umask 077
   php -r '$c = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
      $c["redis"]["password"] = [ "coasterpedia", getenv( "REDIS_PASSWORD" ) ];
      file_put_contents( $argv[2], json_encode( $c, JSON_THROW_ON_ERROR ) ) or exit( 1 );' \
      "$conf" /tmp/jobrunner-conf.json || exit 1
   conf=/tmp/jobrunner-conf.json
fi

# exec, so the service itself gets the stop signal (it handles SIGTERM) instead
# of this shell, which ignores it until Docker kills the container 10s later.
if [ "${RUNNER_TYPE:-job}" = "Chron" ]; then
   exec /usr/local/bin/php $dir/redisJobChronService --config-file=$conf
else
   exec /usr/local/bin/php $dir/redisJobRunnerService --config-file=$conf
fi
