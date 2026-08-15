#!/bin/bash
set -e

if [ "$#" -gt 0 ] && [ "$1" != "app-server" ]; then
    exec "$@"
fi

mkdir -p /tmp/client_temp /tmp/proxy_temp /tmp/fastcgi_temp /tmp/uwsgi_temp /tmp/scgi_temp
mkdir -p \
    /app/storage/app/private \
    /app/storage/app/public \
    /app/storage/framework/cache/data \
    /app/storage/framework/sessions \
    /app/storage/framework/views \
    /app/storage/inertia-devtools \
    /app/storage/logs \
    /app/bootstrap/cache

ensure_runtime_ownership() {
    runtime_path="$1"

    if find "$runtime_path" \( ! -user www-data -o ! -group www-data \) -print -quit | grep -q .; then
        chown -R www-data:www-data "$runtime_path"
    fi
}

ensure_runtime_ownership /app/storage/app/private
ensure_runtime_ownership /app/storage/app/public
ensure_runtime_ownership /app/storage/framework
ensure_runtime_ownership /app/storage/inertia-devtools
ensure_runtime_ownership /app/storage/logs
ensure_runtime_ownership /app/bootstrap/cache

# Start PHP-FPM in foreground mode as background job with PID tracking
php-fpm -F &
FPM_PID=$!

# Start Nginx in foreground mode as background job with PID tracking
nginx -g 'daemon off;' -c /etc/nginx/nginx.conf &
NGINX_PID=$!

cleanup() {
    echo "Termination signal received. Draining connections..."
    kill -QUIT "$NGINX_PID" 2>/dev/null || true
    kill -QUIT "$FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" 2>/dev/null || true
    wait "$FPM_PID" 2>/dev/null || true
    exit 0
}

trap cleanup INT TERM QUIT

# Wait for either process to terminate
set +e
wait -n "$FPM_PID" "$NGINX_PID"
EXIT_STATUS=$?

echo "CRITICAL: A core web runtime process exited with status $EXIT_STATUS. Shutting down companion process." >&2
kill -TERM "$NGINX_PID" 2>/dev/null || true
kill -TERM "$FPM_PID" 2>/dev/null || true
wait "$NGINX_PID" 2>/dev/null || true
wait "$FPM_PID" 2>/dev/null || true
exit 1
