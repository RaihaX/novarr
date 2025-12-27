#!/bin/sh
set -e

# =============================================================================
# Novarr Supervisor Entrypoint Script
# =============================================================================
# This script initializes the application and starts supervisor to manage
# both the queue workers and scheduler in a single container.
# =============================================================================

echo "Starting Novarr with Supervisor..."

# Wait for database to be ready
wait_for_database() {
    echo "Waiting for database connection..."
    MAX_TRIES=30
    TRIES=0

    while [ $TRIES -lt $MAX_TRIES ]; do
        if php artisan tinker --execute="try { DB::connection()->getPdo(); echo 'connected'; } catch (\Exception \$e) { exit(1); }" 2>/dev/null | grep -q "connected"; then
            echo "Database connection established."
            return 0
        fi

        TRIES=$((TRIES + 1))
        echo "Database not ready, waiting... (attempt $TRIES/$MAX_TRIES)"
        sleep 2
    done

    echo "ERROR: Could not connect to database after $MAX_TRIES attempts."
    exit 1
}

# Wait for Redis to be ready
wait_for_redis() {
    echo "Waiting for Redis connection..."
    MAX_TRIES=30
    TRIES=0

    while [ $TRIES -lt $MAX_TRIES ]; do
        if php artisan tinker --execute="try { Illuminate\Support\Facades\Redis::ping(); echo 'connected'; } catch (\Exception \$e) { exit(1); }" 2>/dev/null | grep -q "connected"; then
            echo "Redis connection established."
            return 0
        fi

        TRIES=$((TRIES + 1))
        echo "Redis not ready, waiting... (attempt $TRIES/$MAX_TRIES)"
        sleep 2
    done

    echo "WARNING: Could not connect to Redis, continuing anyway..."
}

# Run Laravel optimizations
optimize_laravel() {
    echo "Running Laravel optimizations..."

    # Clear old caches
    php artisan config:clear 2>/dev/null || true
    php artisan route:clear 2>/dev/null || true
    php artisan view:clear 2>/dev/null || true

    # Create storage symlink if not exists
    if [ ! -L "public/storage" ]; then
        echo "Creating storage symlink..."
        php artisan storage:link 2>/dev/null || true
    fi

    # Run migrations
    echo "Running database migrations..."
    php artisan migrate --force 2>/dev/null || true

    # Rebuild caches for production
    if [ "$APP_ENV" = "production" ]; then
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        echo "Production caches rebuilt."
    fi
}

# Handle graceful shutdown
shutdown() {
    echo "Received shutdown signal, stopping supervisor..."
    supervisorctl stop all
    kill -TERM "$SUPERVISOR_PID" 2>/dev/null
    wait "$SUPERVISOR_PID"
    echo "Supervisor stopped."
    exit 0
}

# Trap shutdown signals
trap shutdown SIGTERM SIGINT SIGQUIT

# Main execution
echo "Initializing Novarr..."

# Wait for dependencies
wait_for_database
wait_for_redis

# Run optimizations
optimize_laravel

# Set default worker processes if not set
export SUPERVISOR_WORKER_PROCESSES=${SUPERVISOR_WORKER_PROCESSES:-2}
echo "Starting supervisor with $SUPERVISOR_WORKER_PROCESSES queue workers..."

# Start supervisor in foreground
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf &
SUPERVISOR_PID=$!

# Wait for supervisor
wait "$SUPERVISOR_PID"
