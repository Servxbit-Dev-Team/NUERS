#!/bin/sh
set -eu
[ "${APP_ENV:-}" = "staging" ] || [ "${APP_ENV:-}" = "testing" ] || { echo 'Staging container requires isolated staging/testing environment' >&2; exit 1; }
case "${DB_DATABASE:-}" in nuers_vapt_*) ;; *) echo 'Unexpected staging database' >&2; exit 1;; esac
if [ "${APP_ENV}" = "staging" ]; then
    mkdir -p /run/clamav
    chown clamav:clamav /run/clamav
    freshclam --stdout
    clamd
fi
php-fpm -D
exec nginx -g 'daemon off;'
