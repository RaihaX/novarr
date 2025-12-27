#!/bin/sh
#=============================================================================
# Novarr Docker Initialization Script
#=============================================================================
# This script handles first-time setup and initialization tasks for the
# Docker container. It can be used as an entrypoint wrapper or run manually.
#=============================================================================

set -e

echo "=================================================="
echo "  Novarr - Docker Initialization"
echo "=================================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo "${RED}[ERROR]${NC} $1"
}

# Check if .env exists
check_env_file() {
    if [ ! -f ".env" ]; then
        log_warn ".env file not found"
        if [ -f ".env.example" ]; then
            log_info "Copying .env.example to .env"
            cp .env.example .env
            log_warn "Please update .env with your settings!"
        else
            log_error ".env.example not found. Cannot proceed."
            exit 1
        fi
    else
        log_info ".env file exists"
    fi
}

# Check if APP_KEY is set
check_app_key() {
    if grep -q "^APP_KEY=$" .env || grep -q "^APP_KEY=\"\"$" .env; then
        log_warn "APP_KEY is not set. Generating..."
        php artisan key:generate --force
        log_info "APP_KEY generated successfully"
    else
        log_info "APP_KEY is already set"
    fi
}

# Wait for database to be ready
wait_for_database() {
    log_info "Waiting for database connection..."

    MAX_ATTEMPTS=30
    ATTEMPT=1

    while [ $ATTEMPT -le $MAX_ATTEMPTS ]; do
        if php artisan db:monitor --max-attempts=1 2>/dev/null || php -r "
            try {
                new PDO(
                    'mysql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '3306'),
                    getenv('DB_USERNAME'),
                    getenv('DB_PASSWORD')
                );
                exit(0);
            } catch (Exception \$e) {
                exit(1);
            }
        " 2>/dev/null; then
            log_info "Database is ready!"
            return 0
        fi

        log_warn "Database not ready yet (attempt $ATTEMPT/$MAX_ATTEMPTS)"
        ATTEMPT=$((ATTEMPT + 1))
        sleep 2
    done

    log_error "Could not connect to database after $MAX_ATTEMPTS attempts"
    return 1
}

# Run migrations if needed
run_migrations() {
    log_info "Checking for pending migrations..."

    # Check if migrations table exists and if there are pending migrations
    PENDING=$(php artisan migrate:status 2>/dev/null | grep -c "No" || echo "0")

    if [ "$PENDING" != "0" ]; then
        log_info "Running pending migrations..."
        php artisan migrate --force
        log_info "Migrations completed"
    else
        log_info "No pending migrations"
    fi
}

# Create storage symlink
create_storage_link() {
    if [ ! -L "public/storage" ]; then
        log_info "Creating storage symlink..."
        php artisan storage:link
        log_info "Storage symlink created"
    else
        log_info "Storage symlink already exists"
    fi
}

# Set proper permissions on storage directories
fix_permissions() {
    log_info "Setting storage permissions..."

    # Ensure directories exist
    mkdir -p storage/app/public
    mkdir -p storage/framework/cache
    mkdir -p storage/framework/sessions
    mkdir -p storage/framework/views
    mkdir -p storage/logs
    mkdir -p bootstrap/cache

    # Set permissions (www-data is typically UID 82 in Alpine)
    chmod -R 775 storage bootstrap/cache 2>/dev/null || true

    log_info "Permissions set"
}

# Cache configuration for production
cache_config() {
    if [ "$APP_ENV" = "production" ]; then
        log_info "Caching configuration for production..."
        php artisan config:cache
        php artisan route:cache
        php artisan view:cache
        log_info "Configuration cached"
    else
        log_info "Skipping cache in non-production environment"
    fi
}

# Main initialization sequence
main() {
    cd /var/www/html

    log_info "Starting initialization..."

    # Run all checks and setup
    check_env_file
    check_app_key
    fix_permissions

    # Only run database-dependent tasks if DB_HOST is set
    if [ -n "$DB_HOST" ]; then
        wait_for_database
        run_migrations
    else
        log_warn "DB_HOST not set, skipping database initialization"
    fi

    create_storage_link
    cache_config

    echo "=================================================="
    echo "  Initialization Complete!"
    echo "=================================================="

    # If arguments are passed, execute them
    if [ $# -gt 0 ]; then
        log_info "Executing command: $@"
        exec "$@"
    fi
}

# Run main function with all script arguments
main "$@"
