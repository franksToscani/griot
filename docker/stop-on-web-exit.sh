#!/bin/sh
# Event listener of supervisord (docker/supervisord.conf): when the web server stops, for any
# reason, stop supervisord too, so that the container exits and Render starts a new one. The
# queue worker and the scheduler are restarted by supervisord itself. Protocol: READY, a header
# line with the length of the payload, the payload, then "RESULT 2\nOK".
set -u

# The pidfile of docker/supervisord.conf; another one only to try the script outside the image.
pidfile="${SUPERVISORD_PIDFILE:-/tmp/supervisord.pid}"

printf 'READY\n'

while read -r header; do
    length=$(printf '%s\n' "$header" | sed -n 's/.*len:\([0-9][0-9]*\).*/\1/p')
    payload=$(dd bs=1 count="${length:-0}" 2>/dev/null)

    case "$payload" in
        *"processname:web "*)
            echo "griot: the web server stopped; stopping the container so that it starts again" >&2
            kill -TERM "$(cat "$pidfile")"
            ;;
    esac

    printf 'RESULT 2\nOK'
    printf 'READY\n'
done
