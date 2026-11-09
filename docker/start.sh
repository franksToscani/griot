#!/bin/sh
# Start of the container (Dockerfile): the caches, built with the variables of the service that
# arrive only now, then supervisord with the web server, the queue worker and the scheduler.
# No migrations here: Render runs them before the deploy (preDeployCommand in render.yaml).
set -eu

cd /app

if [ -z "${APP_KEY:-}" ]; then
    echo "griot-start: APP_KEY is missing. Set it in the hosting service (php artisan key:generate --show)." >&2
    exit 1
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec supervisord -c /etc/supervisor/griot.conf
