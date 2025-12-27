#!/bin/zsh

# =============================================================================
# Novarr Docker Deploy Script
# =============================================================================
# Initial deployment script for first-time setup
# Usage: ./docker-deploy.sh [options]
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
COMPOSE_FILE="docker-compose.yml"
ENV_FILE=".env"
ENV_EXAMPLE=".env.example"
TAG_FILE=".docker-tag"
IMAGE_NAME="novarr"
HEALTH_TIMEOUT=300  # 5 minutes
HEALTH_INTERVAL=5   # 5 seconds
NOVARR_TAG="latest"

# Functions
print_header() {
    echo ""
    echo -e "${CYAN}========================================${NC}"
    echo -e "${CYAN}  $1${NC}"
    echo -e "${CYAN}========================================${NC}"
    echo ""
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

print_step() {
    echo -e "${CYAN}→ $1${NC}"
}

# Check if a command exists
command_exists() {
    command -v "$1" &> /dev/null
}

# Check Docker availability
check_docker() {
    print_step "Checking Docker..."

    if ! command_exists docker; then
        print_error "Docker is not installed"
        exit 1
    fi

    if ! docker info &> /dev/null; then
        print_error "Docker daemon is not running"
        exit 1
    fi

    print_success "Docker is available"

    # Check Docker Compose
    if docker compose version &> /dev/null; then
        COMPOSE_CMD="docker compose"
        print_success "Docker Compose (plugin) is available"
    elif command_exists docker-compose; then
        COMPOSE_CMD="docker-compose"
        print_success "Docker Compose (standalone) is available"
    else
        print_error "Docker Compose is not installed"
        exit 1
    fi
}

# Setup environment file
setup_env() {
    print_step "Setting up environment..."

    if [[ -f "$ENV_FILE" ]]; then
        print_success ".env file already exists"

        # Backup existing .env
        cp "$ENV_FILE" "${ENV_FILE}.backup.$(date +%Y%m%d-%H%M%S)"
        print_info "Backup created: ${ENV_FILE}.backup.$(date +%Y%m%d-%H%M%S)"
    else
        if [[ -f "$ENV_EXAMPLE" ]]; then
            cp "$ENV_EXAMPLE" "$ENV_FILE"
            print_success "Created .env from .env.example"
        else
            print_error ".env.example not found"
            exit 1
        fi
    fi
}

# Generate APP_KEY if not set
generate_app_key() {
    print_step "Checking APP_KEY..."

    if grep -q "^APP_KEY=$" "$ENV_FILE" || grep -q "^APP_KEY=\"\"$" "$ENV_FILE" || ! grep -q "^APP_KEY=" "$ENV_FILE"; then
        print_info "Generating new APP_KEY..."

        # Generate a base64 key
        NEW_KEY="base64:$(openssl rand -base64 32)"

        if grep -q "^APP_KEY=" "$ENV_FILE"; then
            # Replace existing empty APP_KEY
            sed -i.bak "s|^APP_KEY=.*|APP_KEY=${NEW_KEY}|" "$ENV_FILE"
            rm -f "${ENV_FILE}.bak"
        else
            # Add APP_KEY if it doesn't exist
            echo "APP_KEY=${NEW_KEY}" >> "$ENV_FILE"
        fi

        print_success "APP_KEY generated"
    else
        print_success "APP_KEY already set"
    fi
}

# Check required environment variables
check_required_env() {
    print_step "Validating environment variables..."

    local missing=()

    # Check DB_PASSWORD
    if ! grep -q "^DB_PASSWORD=." "$ENV_FILE" || grep -q "^DB_PASSWORD=$" "$ENV_FILE"; then
        missing+=("DB_PASSWORD")
    fi

    # Check DB_ROOT_PASSWORD (for docker-compose)
    if ! grep -q "^DB_ROOT_PASSWORD=." "$ENV_FILE" || grep -q "^DB_ROOT_PASSWORD=$" "$ENV_FILE"; then
        # Try to set a default if not present
        if ! grep -q "^DB_ROOT_PASSWORD=" "$ENV_FILE"; then
            echo "DB_ROOT_PASSWORD=secret" >> "$ENV_FILE"
            print_warning "Added default DB_ROOT_PASSWORD (please change in production)"
        else
            missing+=("DB_ROOT_PASSWORD")
        fi
    fi

    if [[ ${#missing[@]} -gt 0 ]]; then
        print_error "Missing required environment variables:"
        for var in "${missing[@]}"; do
            echo "  - $var"
        done
        echo ""
        print_info "Please set these variables in your .env file and run again."
        exit 1
    fi

    print_success "All required environment variables are set"
}

# Check if a port is in use using available tools
check_port_in_use() {
    local port=$1

    # Try lsof first (most common)
    if command -v lsof &> /dev/null; then
        lsof -i :$port &> /dev/null && return 0
        return 1
    fi

    # Fallback to ss (Linux)
    if command -v ss &> /dev/null; then
        ss -tuln | grep -q ":${port} " && return 0
        return 1
    fi

    # Fallback to netstat
    if command -v netstat &> /dev/null; then
        netstat -tuln 2>/dev/null | grep -q ":${port} " && return 0
        return 1
    fi

    # Fallback to nc (netcat)
    if command -v nc &> /dev/null; then
        nc -z localhost $port &> /dev/null && return 0
        return 1
    fi

    # No tool available, return unknown (treat as available)
    return 1
}

# Check port availability
check_ports() {
    print_step "Checking port availability..."

    # Check if any port checking tool is available
    if ! command -v lsof &> /dev/null && \
       ! command -v ss &> /dev/null && \
       ! command -v netstat &> /dev/null && \
       ! command -v nc &> /dev/null; then
        print_warning "No port checking tool available (lsof, ss, netstat, nc)"
        print_info "Skipping port availability check, proceeding anyway..."
        return 0
    fi

    local ports=(80 443 8000 3306 6379)
    local busy_ports=()

    for port in "${ports[@]}"; do
        if check_port_in_use $port; then
            busy_ports+=($port)
        fi
    done

    if [[ ${#busy_ports[@]} -gt 0 ]]; then
        print_warning "The following ports are in use: ${busy_ports[*]}"
        print_info "This may cause conflicts. Proceeding anyway..."
    else
        print_success "All required ports are available"
    fi
}

# Setup storage directories
setup_storage() {
    print_step "Setting up storage directories..."

    # Create required directories
    mkdir -p storage/app/public
    mkdir -p storage/framework/cache/data
    mkdir -p storage/framework/sessions
    mkdir -p storage/framework/views
    mkdir -p storage/logs
    mkdir -p bootstrap/cache

    # Set permissions
    chmod -R 775 storage
    chmod -R 775 bootstrap/cache

    print_success "Storage directories configured"
}

# Build or pull images
prepare_images() {
    print_step "Preparing Docker images..."

    if [[ -f "docker-build.sh" ]]; then
        print_info "Building application image..."
        chmod +x docker-build.sh
        ./docker-build.sh

        # Read the tag file if it was created
        if [[ -f "$TAG_FILE" ]]; then
            source "$TAG_FILE"
            NOVARR_TAG="${NOVARR_TAG:-latest}"
            print_info "Using image tag: ${IMAGE_NAME}:${NOVARR_TAG}"
        fi
    else
        # Check if local image exists
        if docker image inspect ${IMAGE_NAME}:latest &> /dev/null; then
            print_info "Using existing local image: ${IMAGE_NAME}:latest"
        else
            print_info "Building image with docker-compose..."
            # Build using docker build directly since compose no longer has build context
            docker build -t ${IMAGE_NAME}:latest -t ${IMAGE_NAME}:$(date +"%Y%m%d-%H%M%S") .
        fi
    fi

    # Export tag for compose
    export NOVARR_TAG
    export NOVARR_IMAGE="${IMAGE_NAME}"

    print_success "Images are ready (${IMAGE_NAME}:${NOVARR_TAG})"
}

# Initialize deployment state for blue-green
initialize_deployment_state() {
    print_step "Initializing blue-green deployment state..."

    local state_file=".deployment-state"
    local upstream_conf="docker/nginx/upstream.conf"

    # Create initial deployment state (blue is default)
    cat > "$state_file" << EOF
# Novarr Deployment State - Auto-generated
# Last updated: $(date)
DEPLOYMENT_ACTIVE_COLOR=blue
DEPLOYMENT_ACTIVE_TAG=${NOVARR_TAG}
DEPLOYMENT_TIMESTAMP=$(date +%s)
EOF

    # Create initial upstream config pointing to blue
    cat > "$upstream_conf" << EOF
# =============================================================================
# Novarr Nginx Upstream Configuration for Blue-Green Deployments
# =============================================================================
# This file is auto-generated by docker-deploy.sh
# DO NOT EDIT MANUALLY - changes will be overwritten
#
# Last updated: $(date '+%Y-%m-%d %H:%M:%S')
# Current active environment: blue
# =============================================================================

# Blue environment (primary)
upstream app_blue {
    server novarr-app-blue:8000 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Green environment (standby/new deployment)
upstream app_green {
    server novarr-app-green:8000 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Active upstream - points to current live environment
upstream roadrunner {
    server novarr-app-blue:8000 max_fails=3 fail_timeout=30s;
    keepalive 32;
}
EOF

    print_success "Deployment state initialized (active: blue)"
}

# Start services
start_services() {
    print_step "Starting services..."

    # Initialize blue-green state
    initialize_deployment_state

    # Set blue tag for initial deployment
    export NOVARR_BLUE_TAG="${NOVARR_TAG}"
    export NOVARR_GREEN_TAG="${NOVARR_TAG}"

    # Start core services first (mysql, redis)
    NOVARR_TAG="${NOVARR_TAG}" NOVARR_IMAGE="${IMAGE_NAME}" $COMPOSE_CMD up -d mysql redis

    print_info "Waiting for database to be ready..."
    sleep 10

    # Start blue app container using blue-green compose
    NOVARR_TAG="${NOVARR_TAG}" NOVARR_IMAGE="${IMAGE_NAME}" \
    NOVARR_BLUE_TAG="${NOVARR_TAG}" NOVARR_GREEN_TAG="${NOVARR_TAG}" \
    $COMPOSE_CMD -f docker-compose.yml -f docker-compose.blue-green.yml up -d app-blue

    # Start nginx (depends on app being up)
    print_info "Starting nginx..."
    NOVARR_TAG="${NOVARR_TAG}" NOVARR_IMAGE="${IMAGE_NAME}" $COMPOSE_CMD up -d nginx

    # Start scheduler using blue-green compose
    NOVARR_TAG="${NOVARR_TAG}" NOVARR_IMAGE="${IMAGE_NAME}" \
    NOVARR_BLUE_TAG="${NOVARR_TAG}" \
    $COMPOSE_CMD -f docker-compose.yml -f docker-compose.blue-green.yml --profile blue up -d scheduler-blue

    print_success "Services started (blue-green mode)"
}

# Wait for service health
wait_for_health() {
    local service=$1
    local check_cmd=$2
    local elapsed=0

    print_info "Waiting for $service to be healthy..."

    while [[ $elapsed -lt $HEALTH_TIMEOUT ]]; do
        if eval $check_cmd &> /dev/null; then
            print_success "$service is healthy"
            return 0
        fi

        sleep $HEALTH_INTERVAL
        elapsed=$((elapsed + HEALTH_INTERVAL))
        echo -n "."
    done

    echo ""
    print_error "$service failed to become healthy within ${HEALTH_TIMEOUT}s"
    return 1
}

# Wait for all services to be healthy
wait_for_services() {
    print_step "Waiting for services to be healthy..."
    echo ""

    # Wait for MySQL
    wait_for_health "MySQL" "$COMPOSE_CMD exec -T mysql mysqladmin ping -h localhost -u root --password=\$(grep DB_ROOT_PASSWORD .env | cut -d '=' -f2)"

    # Wait for Redis
    wait_for_health "Redis" "$COMPOSE_CMD exec -T redis redis-cli ping"

    # Wait for App (blue container in blue-green mode)
    local app_health_attempts=0
    local app_max_attempts=60
    local app_container="novarr-app-blue"

    print_info "Waiting for application ($app_container) to be ready..."

    while [[ $app_health_attempts -lt $app_max_attempts ]]; do
        # Check Docker health status
        local health_status=$(docker inspect --format='{{.State.Health.Status}}' "$app_container" 2>/dev/null || echo "unknown")

        if [[ "$health_status" == "healthy" ]]; then
            print_success "Application is healthy (Docker healthcheck)"
            break
        fi

        # Also check HTTP endpoint
        if curl -s -f http://localhost:8000/api/ping &> /dev/null || curl -s -f http://localhost/api/ping &> /dev/null; then
            print_success "Application is healthy (HTTP check)"
            break
        fi

        # Try internal health check
        if docker exec "$app_container" curl -sf http://localhost:8000/api/health &> /dev/null; then
            print_success "Application is healthy (internal check)"
            break
        fi

        sleep 5
        app_health_attempts=$((app_health_attempts + 1))
        echo -n "."
    done

    if [[ $app_health_attempts -ge $app_max_attempts ]]; then
        print_warning "Application health check timed out, but proceeding..."
    fi

    echo ""
}

# Run database migrations
run_migrations() {
    print_step "Running database migrations..."

    local app_container="novarr-app-blue"

    # Wait a bit for MySQL to be fully ready
    sleep 5

    if docker exec "$app_container" php artisan migrate --force; then
        print_success "Migrations completed"
    else
        print_error "Migration failed"
        print_info "Showing recent logs:"
        docker logs --tail=50 "$app_container"
        exit 1
    fi
}

# Import migration data from development environment
import_migration_data() {
    local migrate_dir="./migrate"
    local dump_file="$migrate_dir/dump.sql"
    local storage_archive="$migrate_dir/storage.tar.gz"

    # Check if migration files exist
    if [[ ! -d "$migrate_dir" ]]; then
        return 0
    fi

    if [[ ! -f "$dump_file" ]] && [[ ! -f "$storage_archive" ]]; then
        return 0
    fi

    print_header "Migration Import"
    print_info "Migration files detected in $migrate_dir/"

    # Read database credentials
    local db_root_password=$(grep "^DB_ROOT_PASSWORD=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    local db_database=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    db_database=${db_database:-novarr}

    # Import database dump
    if [[ -f "$dump_file" ]]; then
        print_step "Importing database from dump.sql..."

        # Wait extra time for MySQL to be fully ready for import
        print_info "Waiting for MySQL to be fully ready..."
        sleep 10

        local dump_size=$(du -sh "$dump_file" | cut -f1)
        print_info "Importing $dump_size database dump..."

        if docker exec -i novarr-mysql mysql -u root -p"$db_root_password" "$db_database" < "$dump_file"; then
            print_success "Database imported successfully"
        else
            print_error "Failed to import database"
            print_warning "Continuing with deployment (you may need to import manually)"
        fi
    fi

    # Extract storage archive
    if [[ -f "$storage_archive" ]]; then
        print_step "Extracting storage archive..."

        local archive_size=$(du -sh "$storage_archive" | cut -f1)
        print_info "Extracting $archive_size storage archive..."

        if tar xzf "$storage_archive" -C .; then
            print_success "Storage extracted successfully"

            # Fix permissions
            print_step "Fixing storage permissions..."
            chmod -R 775 storage
            docker exec novarr-app-blue chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
            print_success "Storage permissions fixed"
        else
            print_error "Failed to extract storage archive"
            print_warning "Continuing with deployment (you may need to extract manually)"
        fi
    fi

    # Move imported files to backup directory
    local backup_dir="$migrate_dir/imported-$(date +%Y%m%d-%H%M%S)"
    print_step "Moving migration files to backup..."
    mkdir -p "$backup_dir"
    mv "$dump_file" "$backup_dir/" 2>/dev/null || true
    mv "$storage_archive" "$backup_dir/" 2>/dev/null || true
    mv "$migrate_dir/.env.backup" "$backup_dir/" 2>/dev/null || true
    mv "$migrate_dir/migration-info.txt" "$backup_dir/" 2>/dev/null || true
    print_success "Migration files backed up to $backup_dir/"

    echo ""
}

# Seed Voyager menus
seed_menus() {
    print_step "Seeding Voyager menu items..."

    local app_container="novarr-app-blue"

    # Check if Tools menu already exists
    local tools_exists=$(docker exec "$app_container" php artisan tinker --execute="echo TCG\Voyager\Models\MenuItem::where('title','Tools')->exists() ? 'true' : 'false';" 2>/dev/null | tail -1)

    if [[ "$tools_exists" == "true" ]]; then
        print_info "Tools menu already exists, skipping seeder"
        return 0
    fi

    if docker exec "$app_container" php artisan db:seed --class=MenuItemsTableSeeder --force; then
        print_success "Menu items seeded successfully"
    else
        print_warning "Menu seeding failed (non-critical, Voyager may not be installed yet)"
    fi
}

# Create storage link
create_storage_link() {
    print_step "Creating storage symlink..."

    local app_container="novarr-app-blue"
    docker exec "$app_container" php artisan storage:link --force 2>/dev/null || true
    print_success "Storage link created"
}

# Cache configuration for production
cache_config() {
    print_step "Caching configuration..."

    local app_container="novarr-app-blue"

    # Check if APP_ENV is production
    local app_env=$(grep "^APP_ENV=" "$ENV_FILE" | cut -d '=' -f2)

    if [[ "$app_env" == "production" ]]; then
        docker exec "$app_container" php artisan config:cache
        docker exec "$app_container" php artisan route:cache
        docker exec "$app_container" php artisan view:cache
        print_success "Configuration cached for production"
    else
        print_info "Skipping cache in non-production environment"
    fi
}

# Show service status
show_status() {
    print_header "Service Status"
    $COMPOSE_CMD ps
}

# Show deployment summary
show_summary() {
    print_header "Deployment Summary"

    local app_url=$(grep "^APP_URL=" "$ENV_FILE" | cut -d '=' -f2 || echo "http://localhost")

    echo -e "Application URL:  ${GREEN}${app_url}${NC}"
    echo -e "Health Check:     ${CYAN}${app_url}/api/health${NC}"
    echo -e "Deployment Mode:  ${CYAN}Blue-Green${NC}"
    echo -e "Active Color:     ${GREEN}BLUE${NC}"
    echo ""
    echo -e "Services:"
    echo -e "  - App (Blue):    Running on port 8000 (internal)"
    echo -e "  - Nginx:         Running on port 80/443"
    echo -e "  - MySQL:         Running on port 3306"
    echo -e "  - Redis:         Running on port 6379"
    echo -e "  - Scheduler:     Running (blue)"
    echo ""
    echo -e "Useful commands:"
    echo -e "  ${CYAN}make logs${NC}              - View all logs"
    echo -e "  ${CYAN}make shell${NC}             - Open shell in app container"
    echo -e "  ${CYAN}make ps${NC}                - Show service status"
    echo -e "  ${CYAN}make health${NC}            - Check health endpoints"
    echo -e "  ${CYAN}make smoke-test${NC}        - Run smoke tests"
    echo -e "  ${CYAN}make deployment-status${NC} - Show blue-green status"
    echo -e "  ${CYAN}make update${NC}            - Zero-downtime update"
    echo -e "  ${CYAN}make rollback${NC}          - Rollback to previous"
    echo ""
}

# Test health endpoint
test_health() {
    print_step "Testing health endpoint..."

    local health_urls=("http://localhost:8000/api/health" "http://localhost/api/health" "http://localhost:8000/api/ping" "http://localhost/api/ping")

    for url in "${health_urls[@]}"; do
        if curl -s -f "$url" &> /dev/null; then
            local response=$(curl -s "$url")
            print_success "Health check passed: $url"
            echo -e "  Response: ${CYAN}${response}${NC}"
            return 0
        fi
    done

    print_warning "Could not reach health endpoint, but services may still be starting..."
}

# Main execution
main() {
    print_header "Novarr Docker Deployment"

    local start_time=$(date +%s)

    print_header "Pre-deployment Checks"

    check_docker
    setup_env
    generate_app_key
    check_required_env
    check_ports
    setup_storage

    print_header "Preparing Images"

    prepare_images

    print_header "Starting Services"

    start_services
    wait_for_services

    # Import migration data if available (before migrations)
    import_migration_data

    print_header "Post-deployment Tasks"

    run_migrations
    seed_menus
    create_storage_link
    cache_config

    print_header "Verification"

    show_status
    test_health

    local end_time=$(date +%s)
    local duration=$((end_time - start_time))

    show_summary

    echo -e "${GREEN}════════════════════════════════════════${NC}"
    echo -e "${GREEN}  Deployment completed in ${duration}s${NC}"
    echo -e "${GREEN}════════════════════════════════════════${NC}"
    echo ""
}

# Migration mode flag
MIGRATE_MODE=false

# Handle script arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        -h|--help)
            echo "Usage: ./docker-deploy.sh [options]"
            echo ""
            echo "Options:"
            echo "  -h, --help     Show this help message"
            echo "  --migrate      Enable migration mode (import from ./migrate/)"
            echo ""
            echo "This script performs initial deployment of the Novarr application:"
            echo "  1. Validates environment and Docker setup"
            echo "  2. Creates/validates .env file"
            echo "  3. Builds or pulls Docker images"
            echo "  4. Starts all services"
            echo "  5. Imports migration data (if --migrate or ./migrate/ exists)"
            echo "  6. Runs database migrations"
            echo "  7. Seeds Voyager menu items (Tools > Commands)"
            echo "  8. Configures storage and caching"
            echo ""
            echo "Migration Mode:"
            echo "  When --migrate is specified or ./migrate/ directory exists with"
            echo "  dump.sql or storage.tar.gz, the script will import the data"
            echo "  before running migrations. Use this when migrating from development."
            echo ""
            echo "  Export migration data with: ./scripts/migrate-from-dev.sh"
            echo ""
            exit 0
            ;;
        --migrate)
            MIGRATE_MODE=true
            shift
            ;;
        *)
            print_error "Unknown option: $1"
            echo "Use --help to see available options"
            exit 1
            ;;
    esac
done

# Show migration mode banner if enabled
if [[ "$MIGRATE_MODE" == true ]]; then
    echo ""
    echo -e "${YELLOW}╔══════════════════════════════════════════════╗${NC}"
    echo -e "${YELLOW}║         MIGRATION MODE ENABLED               ║${NC}"
    echo -e "${YELLOW}║  Will import data from ./migrate/ directory  ║${NC}"
    echo -e "${YELLOW}╚══════════════════════════════════════════════╝${NC}"
    echo ""
fi

main
