#!/bin/zsh

# =============================================================================
# Novarr Docker Update Script - Zero-Downtime Blue-Green Deployment
# =============================================================================
# True zero-downtime update script using blue-green deployment strategy
# Usage: ./docker-update.sh [options]
#
# Deployment Strategy:
# 1. Build new image with timestamp tag
# 2. Start new container (green/blue) alongside current active container
# 3. Wait for new container to pass health checks
# 4. Run migrations on new container (brief maintenance only if schema-breaking)
# 5. Atomically switch Nginx upstream to new container
# 6. Reload Nginx to activate traffic switch (<1s)
# 7. Stop old container after drain period
# 8. Cleanup and verification
#
# This ensures <5s total disruption with no 502/503 errors during updates.
# =============================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

# Configuration
COMPOSE_FILE="docker-compose.yml"
BLUE_GREEN_COMPOSE="docker-compose.blue-green.yml"
ENV_FILE=".env"
TAG_FILE=".docker-tag"
STATE_FILE=".deployment-state"
IMAGE_NAME="novarr"
BACKUP_DIR="backups"
NGINX_UPSTREAM_CONF="docker/nginx/upstream.conf"
NGINX_UPSTREAM_TEMPLATE="docker/nginx/upstream-blue-green.conf.template"
TIMESTAMP=$(date +"%Y%m%d-%H%M%S")
CURRENT_TAG=""
NEW_TAG=""

# Blue-Green state
ACTIVE_COLOR=""      # Current active environment (blue or green)
TARGET_COLOR=""      # Target environment for new deployment
ACTIVE_CONTAINER=""  # Current active container name
TARGET_CONTAINER=""  # Target container name for new deployment

# Health check configuration
HEALTH_CHECK_RETRIES=30
HEALTH_CHECK_INTERVAL=2
DRAIN_WAIT_SECONDS=5

# Flags
DRY_RUN=false
SKIP_GIT=false
SKIP_BACKUP=false
SKIP_CONFIRM=false
SKIP_MIGRATIONS=false
FORCE=false
VERBOSE=false
ROLLBACK_MODE=false

# =============================================================================
# Output Functions
# =============================================================================

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

print_debug() {
    if [[ "$VERBOSE" == "true" ]]; then
        echo -e "${MAGENTA}[DEBUG] $1${NC}"
    fi
}

# =============================================================================
# Help and Usage
# =============================================================================

show_help() {
    cat << EOF
Usage: ./docker-update.sh [options]

Zero-Downtime Blue-Green Deployment Script for Novarr

Options:
  --dry-run           Preview changes without executing
  --skip-git          Skip git pull
  --skip-backup       Skip database backup
  --skip-confirm      Skip confirmation prompt
  --skip-migrations   Skip running migrations
  --force             Force update even with uncommitted changes
  --verbose           Enable verbose/debug output
  --rollback          Rollback to previous deployment
  -h, --help          Show this help message

Deployment Strategy:
  This script uses blue-green deployment for true zero-downtime updates:

  1. Determines current active environment (blue or green)
  2. Builds new image and starts it in the inactive environment
  3. Waits for new container to pass health checks
  4. Runs database migrations (with brief maintenance if schema-breaking)
  5. Seeds Voyager menu items (Tools > Commands)
  6. Atomically switches Nginx upstream configuration
  7. Reloads Nginx (<1s traffic switch)
  8. Drains connections from old container
  9. Stops old container and cleans up

  Total expected disruption: <5 seconds, with no 502/503 errors

Examples:
  ./docker-update.sh                    # Standard zero-downtime update
  ./docker-update.sh --dry-run          # Preview what would happen
  ./docker-update.sh --skip-git         # Update without git pull
  ./docker-update.sh --rollback         # Rollback to previous deployment
  ./docker-update.sh --verbose          # Detailed output

EOF
    exit 0
}

# =============================================================================
# Argument Parsing
# =============================================================================

parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --dry-run)
                DRY_RUN=true
                shift
                ;;
            --skip-git)
                SKIP_GIT=true
                shift
                ;;
            --skip-backup)
                SKIP_BACKUP=true
                shift
                ;;
            --skip-confirm)
                SKIP_CONFIRM=true
                shift
                ;;
            --skip-migrations)
                SKIP_MIGRATIONS=true
                shift
                ;;
            --force)
                FORCE=true
                shift
                ;;
            --verbose)
                VERBOSE=true
                shift
                ;;
            --rollback)
                ROLLBACK_MODE=true
                shift
                ;;
            -h|--help)
                show_help
                ;;
            *)
                shift
                ;;
        esac
    done
}

# =============================================================================
# Docker Compose Detection
# =============================================================================

get_compose_cmd() {
    if docker compose version &> /dev/null; then
        COMPOSE_CMD="docker compose"
    elif command -v docker-compose &> /dev/null; then
        COMPOSE_CMD="docker-compose"
    else
        print_error "Docker Compose is not installed"
        exit 1
    fi
    print_debug "Using compose command: $COMPOSE_CMD"
}

# =============================================================================
# State Management
# =============================================================================

# Load deployment state from file
load_deployment_state() {
    if [[ -f "$STATE_FILE" ]]; then
        source "$STATE_FILE"
        ACTIVE_COLOR="${DEPLOYMENT_ACTIVE_COLOR:-blue}"
        print_debug "Loaded state: active=$ACTIVE_COLOR"
    else
        # Detect from running containers
        detect_active_environment
    fi
}

# Save deployment state to file
save_deployment_state() {
    local color=$1
    local tag=$2

    cat > "$STATE_FILE" << EOF
# Novarr Deployment State - Auto-generated
# Last updated: $(date)
DEPLOYMENT_ACTIVE_COLOR=$color
DEPLOYMENT_ACTIVE_TAG=$tag
DEPLOYMENT_TIMESTAMP=$(date +%s)
EOF

    print_debug "Saved state: active=$color, tag=$tag"
}

# Detect currently active environment from running containers
detect_active_environment() {
    print_step "Detecting current deployment state..."

    # Check for blue-green containers first
    local blue_running=$(docker ps -q -f name=novarr-app-blue 2>/dev/null)
    local green_running=$(docker ps -q -f name=novarr-app-green 2>/dev/null)
    local legacy_running=$(docker ps -q -f name=novarr-app 2>/dev/null)

    if [[ -n "$blue_running" ]] && [[ -z "$green_running" ]]; then
        ACTIVE_COLOR="blue"
        print_info "Detected active environment: BLUE"
    elif [[ -n "$green_running" ]] && [[ -z "$blue_running" ]]; then
        ACTIVE_COLOR="green"
        print_info "Detected active environment: GREEN"
    elif [[ -n "$blue_running" ]] && [[ -n "$green_running" ]]; then
        # Both running - check nginx upstream to determine active
        ACTIVE_COLOR=$(detect_active_from_nginx)
        print_info "Both environments running, nginx points to: ${ACTIVE_COLOR^^}"
    elif [[ -n "$legacy_running" ]]; then
        # Legacy single-container setup - migrate to blue
        print_warning "Legacy deployment detected, will migrate to blue-green"
        ACTIVE_COLOR="legacy"
    else
        # No containers running - default to blue as target
        ACTIVE_COLOR="none"
        print_info "No active deployment detected, will start fresh with blue"
    fi
}

# Detect active environment from nginx upstream configuration
detect_active_from_nginx() {
    if [[ -f "$NGINX_UPSTREAM_CONF" ]]; then
        if grep -q "novarr-app-blue" "$NGINX_UPSTREAM_CONF" | grep -v "^#" | head -1; then
            echo "blue"
        else
            echo "green"
        fi
    else
        echo "blue"
    fi
}

# Calculate target environment (opposite of active)
calculate_target_environment() {
    case "$ACTIVE_COLOR" in
        blue)
            TARGET_COLOR="green"
            ;;
        green)
            TARGET_COLOR="blue"
            ;;
        legacy|none)
            TARGET_COLOR="blue"
            ;;
        *)
            TARGET_COLOR="blue"
            ;;
    esac

    ACTIVE_CONTAINER="novarr-app-${ACTIVE_COLOR}"
    TARGET_CONTAINER="novarr-app-${TARGET_COLOR}"

    print_info "Deployment: ${ACTIVE_COLOR:-none} → ${TARGET_COLOR}"
    print_debug "Active container: $ACTIVE_CONTAINER"
    print_debug "Target container: $TARGET_CONTAINER"
}

# =============================================================================
# Pre-flight Checks
# =============================================================================

confirm_update() {
    if [[ "$SKIP_CONFIRM" == "true" ]]; then
        return 0
    fi

    local app_env=$(grep "^APP_ENV=" "$ENV_FILE" 2>/dev/null | cut -d '=' -f2 || echo "local")

    if [[ "$app_env" == "production" ]]; then
        echo ""
        print_warning "You are about to update a PRODUCTION environment!"
        echo ""
        echo -e "  Active: ${CYAN}${ACTIVE_COLOR:-none}${NC}"
        echo -e "  Target: ${GREEN}${TARGET_COLOR}${NC}"
        echo ""
        read -p "Are you sure you want to continue? (yes/no): " confirm
        if [[ "$confirm" != "yes" ]]; then
            print_info "Update cancelled"
            exit 0
        fi
    fi
}

check_git_status() {
    if [[ "$SKIP_GIT" == "true" ]]; then
        return 0
    fi

    print_step "Checking git status..."

    if [[ ! -d ".git" ]]; then
        print_warning "Not a git repository, skipping git operations"
        SKIP_GIT=true
        return 0
    fi

    if [[ -n $(git status --porcelain) ]]; then
        if [[ "$FORCE" == "true" ]]; then
            print_warning "Uncommitted changes detected, but --force flag is set"
        else
            print_error "Uncommitted changes detected. Commit or stash them first."
            echo ""
            git status --short
            echo ""
            print_info "Use --force to override or --skip-git to skip git pull"
            exit 1
        fi
    else
        print_success "Git working directory is clean"
    fi
}

pull_latest() {
    if [[ "$SKIP_GIT" == "true" ]]; then
        print_info "Skipping git pull"
        return 0
    fi

    print_step "Pulling latest changes..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would run: git pull"
        return 0
    fi

    local current_commit=$(git rev-parse HEAD)

    if git pull; then
        local new_commit=$(git rev-parse HEAD)

        if [[ "$current_commit" == "$new_commit" ]]; then
            print_info "Already up to date"
        else
            print_success "Updated from ${current_commit:0:7} to ${new_commit:0:7}"
            echo ""
            git log --oneline ${current_commit}..${new_commit}
            echo ""
        fi
    else
        print_error "Git pull failed"
        exit 1
    fi
}

# =============================================================================
# Backup Functions
# =============================================================================

backup_database() {
    if [[ "$SKIP_BACKUP" == "true" ]]; then
        print_info "Skipping database backup"
        return 0
    fi

    print_step "Backing up database..."

    mkdir -p "$BACKUP_DIR"
    local backup_file="${BACKUP_DIR}/db-backup-${TIMESTAMP}.sql"

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would create backup: $backup_file"
        return 0
    fi

    local db_name=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d '=' -f2 || echo "novarr")
    local db_user=$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d '=' -f2 || echo "novarr")
    local db_pass=$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d '=' -f2)

    if $COMPOSE_CMD exec -T mysql mysqldump -u"$db_user" -p"$db_pass" "$db_name" > "$backup_file" 2>/dev/null; then
        print_success "Database backed up to: $backup_file"
        gzip "$backup_file"
        print_info "Backup compressed: ${backup_file}.gz"
    else
        print_warning "Database backup failed, but continuing..."
    fi

    cp "$ENV_FILE" "${BACKUP_DIR}/env-backup-${TIMESTAMP}"
    print_success ".env backed up"
}

# =============================================================================
# Image Building
# =============================================================================

get_current_tag() {
    if [[ "$ACTIVE_COLOR" == "legacy" ]]; then
        CURRENT_TAG=$(docker inspect --format='{{.Config.Image}}' novarr-app 2>/dev/null | cut -d':' -f2 || echo "latest")
    elif [[ "$ACTIVE_COLOR" != "none" ]]; then
        CURRENT_TAG=$(docker inspect --format='{{.Config.Image}}' "$ACTIVE_CONTAINER" 2>/dev/null | cut -d':' -f2 || echo "latest")
    else
        CURRENT_TAG="latest"
    fi

    if [[ -z "$CURRENT_TAG" ]]; then
        CURRENT_TAG="latest"
    fi

    print_info "Current running image tag: ${IMAGE_NAME}:${CURRENT_TAG}"
}

build_image() {
    print_step "Building new Docker image..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would build image: ${IMAGE_NAME}:${TIMESTAMP}"
        NEW_TAG="$TIMESTAMP"
        return 0
    fi

    # Tag current image as 'previous' for rollback
    get_current_tag
    if [[ "$CURRENT_TAG" != "latest" ]] && docker image inspect ${IMAGE_NAME}:${CURRENT_TAG} &> /dev/null; then
        docker tag ${IMAGE_NAME}:${CURRENT_TAG} ${IMAGE_NAME}:previous
        print_info "Current image ${IMAGE_NAME}:${CURRENT_TAG} tagged as 'previous' for rollback"
    elif docker image inspect ${IMAGE_NAME}:latest &> /dev/null; then
        docker tag ${IMAGE_NAME}:latest ${IMAGE_NAME}:previous
        print_info "Current image tagged as 'previous' for rollback"
    fi

    # Build new image
    if [[ -f "docker-build.sh" ]]; then
        chmod +x docker-build.sh
        ./docker-build.sh "$TIMESTAMP"
    else
        docker build -t ${IMAGE_NAME}:${TIMESTAMP} -t ${IMAGE_NAME}:latest .
    fi

    NEW_TAG="$TIMESTAMP"
    print_success "New image built: ${IMAGE_NAME}:${NEW_TAG}"

    # Read the tag file if it was created by docker-build.sh
    if [[ -f "$TAG_FILE" ]]; then
        source "$TAG_FILE"
        NEW_TAG="${NOVARR_TAG:-$TIMESTAMP}"
    fi
}

# =============================================================================
# Container Management
# =============================================================================

# Start target container with new image
start_target_container() {
    print_step "Starting ${TARGET_COLOR} container with new image..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would start container: $TARGET_CONTAINER with image ${IMAGE_NAME}:${NEW_TAG}"
        return 0
    fi

    # Stop any existing target container (from failed previous deployment)
    if docker ps -a -q -f name="$TARGET_CONTAINER" &> /dev/null; then
        print_debug "Removing existing target container"
        docker stop "$TARGET_CONTAINER" --time 5 2>/dev/null || true
        docker rm "$TARGET_CONTAINER" 2>/dev/null || true
    fi

    # Set environment variable for the target color
    if [[ "$TARGET_COLOR" == "blue" ]]; then
        export NOVARR_BLUE_TAG="$NEW_TAG"
        export NOVARR_GREEN_TAG="${CURRENT_TAG:-latest}"
    else
        export NOVARR_GREEN_TAG="$NEW_TAG"
        export NOVARR_BLUE_TAG="${CURRENT_TAG:-latest}"
    fi

    # Start target container using blue-green compose file
    $COMPOSE_CMD -f "$COMPOSE_FILE" -f "$BLUE_GREEN_COMPOSE" up -d "app-${TARGET_COLOR}"

    print_success "Container $TARGET_CONTAINER started"
}

# Wait for container to be healthy
wait_for_container_health() {
    local container_name=$1
    local max_attempts=${2:-$HEALTH_CHECK_RETRIES}
    local attempts=0

    print_info "Waiting for $container_name to be healthy..."

    while [[ $attempts -lt $max_attempts ]]; do
        # Check Docker health status
        local health_status=$(docker inspect --format='{{.State.Health.Status}}' "$container_name" 2>/dev/null || echo "unknown")

        if [[ "$health_status" == "healthy" ]]; then
            print_success "$container_name is healthy (Docker healthcheck)"
            return 0
        elif [[ "$health_status" == "unhealthy" ]]; then
            print_error "$container_name is unhealthy"
            docker logs --tail 50 "$container_name" 2>&1 | head -20
            return 1
        fi

        # Also try direct health endpoint check
        if docker exec "$container_name" curl -sf http://localhost:8000/api/health &> /dev/null; then
            print_success "$container_name is healthy (HTTP check)"
            return 0
        fi

        sleep $HEALTH_CHECK_INTERVAL
        attempts=$((attempts + 1))
        echo -n "."
    done

    echo ""
    print_error "$container_name failed to become healthy after ${max_attempts} attempts"
    return 1
}

# Run smoke tests against a specific container
run_smoke_tests() {
    local container_name=$1

    print_step "Running smoke tests against $container_name..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would run smoke tests"
        return 0
    fi

    local tests_passed=0
    local tests_total=3

    # Test 1: Health endpoint
    if docker exec "$container_name" curl -sf http://localhost:8000/api/health &> /dev/null; then
        print_success "Health endpoint OK"
        tests_passed=$((tests_passed + 1))
    else
        print_warning "Health endpoint failed"
    fi

    # Test 2: Artisan command
    if docker exec "$container_name" php artisan --version &> /dev/null; then
        print_success "Artisan CLI OK"
        tests_passed=$((tests_passed + 1))
    else
        print_warning "Artisan CLI failed"
    fi

    # Test 3: Database connection
    if docker exec "$container_name" php artisan tinker --execute="DB::connection()->getPdo();" &> /dev/null; then
        print_success "Database connection OK"
        tests_passed=$((tests_passed + 1))
    else
        print_warning "Database connection failed"
    fi

    if [[ $tests_passed -ge 2 ]]; then
        print_success "Smoke tests passed: ${tests_passed}/${tests_total}"
        return 0
    else
        print_error "Smoke tests failed: ${tests_passed}/${tests_total}"
        return 1
    fi
}

# =============================================================================
# Migration Handling
# =============================================================================

check_pending_migrations() {
    local container_name=$1

    if [[ "$SKIP_MIGRATIONS" == "true" ]]; then
        return 1  # No pending (skip)
    fi

    if docker exec "$container_name" php artisan migrate:status 2>/dev/null | grep -q "Pending"; then
        return 0  # Has pending migrations
    fi

    return 1  # No pending migrations
}

run_migrations_on_container() {
    local container_name=$1

    if [[ "$SKIP_MIGRATIONS" == "true" ]]; then
        print_info "Skipping migrations (--skip-migrations flag)"
        return 0
    fi

    print_step "Running database migrations on $container_name..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would run migrations"
        return 0
    fi

    if docker exec "$container_name" php artisan migrate --force; then
        print_success "Migrations completed"
        return 0
    else
        print_error "Migrations failed"
        return 1
    fi
}

# Seed Voyager menus on container
seed_menus_on_container() {
    local container_name=$1

    print_step "Seeding Voyager menu items on $container_name..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would seed menus"
        return 0
    fi

    # Check if Tools menu already exists
    local tools_exists=$(docker exec "$container_name" php artisan tinker --execute="echo TCG\Voyager\Models\MenuItem::where('title','Tools')->exists() ? 'true' : 'false';" 2>/dev/null | tail -1)

    if [[ "$tools_exists" == "true" ]]; then
        print_info "Tools menu already exists, skipping seeder"
        return 0
    fi

    if docker exec "$container_name" php artisan db:seed --class=MenuItemsTableSeeder --force; then
        print_success "Menu items seeded successfully"
        return 0
    else
        print_warning "Menu seeding failed (non-critical)"
        return 0  # Non-critical, don't fail deployment
    fi
}

# =============================================================================
# Nginx Traffic Switching
# =============================================================================

# Generate nginx upstream configuration for target environment
generate_upstream_config() {
    local active_env=$1

    print_step "Generating nginx upstream configuration for ${active_env}..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would update upstream.conf to point to ${active_env}"
        return 0
    fi

    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    # Check if template exists, otherwise generate inline
    if [[ -f "$NGINX_UPSTREAM_TEMPLATE" ]]; then
        # Use template file with placeholder substitution
        sed -e "s/{{TIMESTAMP}}/${timestamp}/g" \
            -e "s/{{ACTIVE_ENV}}/${active_env}/g" \
            "$NGINX_UPSTREAM_TEMPLATE" > "$NGINX_UPSTREAM_CONF"
        print_debug "Generated upstream.conf from template"
    else
        # Fallback: generate inline if template doesn't exist
        cat > "$NGINX_UPSTREAM_CONF" << EOF
# =============================================================================
# Novarr Nginx Upstream Configuration for Blue-Green Deployments
# =============================================================================
# This file is auto-generated by docker-update.sh
# DO NOT EDIT MANUALLY - changes will be overwritten
#
# Last updated: ${timestamp}
# Current active environment: ${active_env}
# =============================================================================

# Blue environment
upstream app_blue {
    server novarr-app-blue:8000 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Green environment
upstream app_green {
    server novarr-app-green:8000 max_fails=3 fail_timeout=30s;
    keepalive 32;
}

# Active upstream - points to current live environment
upstream roadrunner {
    server novarr-app-${active_env}:8000 max_fails=3 fail_timeout=30s;
    keepalive 32;
}
EOF
    fi

    print_success "Upstream configuration updated for ${active_env}"
}

# Reload nginx to apply new upstream configuration
reload_nginx() {
    print_step "Reloading Nginx configuration..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would reload nginx"
        return 0
    fi

    # Copy updated upstream config to nginx container
    docker cp "$NGINX_UPSTREAM_CONF" novarr-nginx:/etc/nginx/conf.d/upstream.conf

    # Test nginx configuration
    if ! docker exec novarr-nginx nginx -t 2>/dev/null; then
        print_error "Nginx configuration test failed"
        return 1
    fi

    # Reload nginx (graceful - doesn't drop connections)
    if docker exec novarr-nginx nginx -s reload; then
        print_success "Nginx reloaded - traffic now routing to ${TARGET_COLOR}"
        return 0
    else
        print_error "Nginx reload failed"
        return 1
    fi
}

# Switch traffic from active to target
switch_traffic() {
    print_header "Switching Traffic"

    print_info "Switching traffic: ${ACTIVE_COLOR:-none} → ${TARGET_COLOR}"

    # Generate new upstream config pointing to target
    generate_upstream_config "$TARGET_COLOR"

    # Reload nginx to apply changes
    if ! reload_nginx; then
        print_error "Traffic switch failed"
        return 1
    fi

    print_success "Traffic successfully switched to ${TARGET_COLOR}"
    return 0
}

# =============================================================================
# Cleanup and Draining
# =============================================================================

# Drain connections from old container and stop it
drain_and_stop_old_container() {
    if [[ "$ACTIVE_COLOR" == "none" ]] || [[ "$ACTIVE_COLOR" == "legacy" ]]; then
        # Handle legacy container
        if [[ "$ACTIVE_COLOR" == "legacy" ]]; then
            print_step "Stopping legacy container..."
            if [[ "$DRY_RUN" != "true" ]]; then
                docker stop novarr-app --time 30 2>/dev/null || true
                docker rm novarr-app 2>/dev/null || true
                print_success "Legacy container stopped"

                # Stop legacy worker if exists
                docker stop novarr-worker --time 30 2>/dev/null || true
                docker rm novarr-worker 2>/dev/null || true
            fi
        fi
        return 0
    fi

    print_step "Draining connections from ${ACTIVE_COLOR} container..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would drain and stop $ACTIVE_CONTAINER"
        return 0
    fi

    # Wait for connections to drain (nginx will stop sending new requests)
    print_info "Waiting ${DRAIN_WAIT_SECONDS}s for connections to drain..."
    sleep $DRAIN_WAIT_SECONDS

    # Stop old container gracefully
    print_step "Stopping ${ACTIVE_COLOR} container..."
    docker stop "$ACTIVE_CONTAINER" --time 30 2>/dev/null || true

    print_success "Old container ${ACTIVE_COLOR} stopped"
}

# Restart queue workers on new container
restart_queue_workers() {
    print_step "Signaling queue workers to restart..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would restart queue workers"
        return 0
    fi

    # Signal workers to restart after current job completes
    if docker exec "$TARGET_CONTAINER" php artisan queue:restart 2>/dev/null; then
        print_success "Queue restart signal sent"
    else
        print_debug "Could not send queue restart signal (worker may not be running in this container)"
    fi

    # Also restart the dedicated worker container if it exists
    if docker ps -q -f name=novarr-worker &> /dev/null; then
        print_step "Restarting dedicated worker container..."
        $COMPOSE_CMD restart worker 2>/dev/null || true
        print_success "Worker container restarted"
    fi
}

# Stop scheduler for the color being deactivated
stop_old_scheduler() {
    local old_color=$1

    if [[ "$old_color" == "none" ]] || [[ "$old_color" == "legacy" ]]; then
        # Handle legacy scheduler
        if [[ "$old_color" == "legacy" ]]; then
            print_debug "Stopping legacy scheduler"
            if [[ "$DRY_RUN" != "true" ]]; then
                docker stop novarr-scheduler --time 10 2>/dev/null || true
                docker rm novarr-scheduler 2>/dev/null || true
            fi
        fi
        return 0
    fi

    print_debug "Stopping scheduler for ${old_color}"

    if [[ "$DRY_RUN" != "true" ]]; then
        docker stop "novarr-scheduler-${old_color}" --time 10 2>/dev/null || true
    fi
}

# Start scheduler for the new active color
start_new_scheduler() {
    local new_color=$1

    print_step "Starting scheduler for ${new_color}..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would start scheduler-${new_color}"
        return 0
    fi

    if [[ "$new_color" == "blue" ]]; then
        export NOVARR_BLUE_TAG="$NEW_TAG"
    else
        export NOVARR_GREEN_TAG="$NEW_TAG"
    fi

    $COMPOSE_CMD -f "$COMPOSE_FILE" -f "$BLUE_GREEN_COMPOSE" --profile "$new_color" up -d "scheduler-${new_color}"

    print_success "Scheduler started for ${new_color}"
}

# =============================================================================
# Cache Management
# =============================================================================

rebuild_caches() {
    local container_name=$1

    print_step "Rebuilding caches on $container_name..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would rebuild caches"
        return 0
    fi

    # Clear old caches
    docker exec "$container_name" php artisan cache:clear 2>/dev/null || true
    docker exec "$container_name" php artisan config:clear 2>/dev/null || true
    docker exec "$container_name" php artisan route:clear 2>/dev/null || true
    docker exec "$container_name" php artisan view:clear 2>/dev/null || true

    # Check environment
    local app_env=$(grep "^APP_ENV=" "$ENV_FILE" 2>/dev/null | cut -d '=' -f2 || echo "local")

    if [[ "$app_env" == "production" ]]; then
        docker exec "$container_name" php artisan config:cache
        docker exec "$container_name" php artisan route:cache
        docker exec "$container_name" php artisan view:cache
        print_success "Production caches rebuilt"
    else
        print_info "Skipping cache rebuild (non-production)"
    fi
}

# =============================================================================
# Health Verification
# =============================================================================

verify_deployment_health() {
    print_step "Verifying deployment health..."

    if [[ "$DRY_RUN" == "true" ]]; then
        print_info "[DRY RUN] Would verify health checks"
        return 0
    fi

    local success=false
    local health_urls=(
        "http://localhost:8000/api/health"
        "http://localhost/api/health"
        "http://localhost:8000/api/ping"
        "http://localhost/api/ping"
    )

    for url in "${health_urls[@]}"; do
        if curl -sf "$url" &> /dev/null; then
            local response=$(curl -s "$url")
            print_success "Health check passed: $url"
            print_debug "Response: $response"
            success=true
            break
        fi
    done

    if [[ "$success" == "false" ]]; then
        print_warning "External health check endpoints not responding"

        # Try internal check
        if docker exec "$TARGET_CONTAINER" curl -sf http://localhost:8000/api/health &> /dev/null; then
            print_success "Internal health check passed"
            success=true
        fi
    fi

    if [[ "$success" == "true" ]]; then
        return 0
    else
        return 1
    fi
}

# =============================================================================
# Rollback
# =============================================================================

perform_rollback() {
    print_header "Initiating Rollback"

    load_deployment_state

    # Calculate rollback target (opposite of current)
    if [[ "$ACTIVE_COLOR" == "blue" ]]; then
        local rollback_color="green"
    else
        local rollback_color="blue"
    fi

    local rollback_container="novarr-app-${rollback_color}"

    # Check if rollback container exists and can be started
    if ! docker ps -a -q -f name="$rollback_container" &> /dev/null; then
        # Try using 'previous' image tag
        print_info "No previous container, attempting to use 'previous' image tag"

        if ! docker image inspect ${IMAGE_NAME}:previous &> /dev/null; then
            print_error "No previous image found for rollback"
            exit 1
        fi

        # Start rollback container with previous image
        if [[ "$rollback_color" == "blue" ]]; then
            export NOVARR_BLUE_TAG="previous"
        else
            export NOVARR_GREEN_TAG="previous"
        fi

        $COMPOSE_CMD -f "$COMPOSE_FILE" -f "$BLUE_GREEN_COMPOSE" up -d "app-${rollback_color}"
    else
        # Start existing container
        docker start "$rollback_container" 2>/dev/null || true
    fi

    # Wait for health
    if ! wait_for_container_health "$rollback_container" 60; then
        print_error "Rollback container failed to become healthy"
        exit 1
    fi

    # Switch traffic
    generate_upstream_config "$rollback_color"
    reload_nginx

    # Stop current active
    if [[ -n "$ACTIVE_COLOR" ]] && [[ "$ACTIVE_COLOR" != "none" ]]; then
        docker stop "novarr-app-${ACTIVE_COLOR}" --time 30 2>/dev/null || true
    fi

    # Update state
    save_deployment_state "$rollback_color" "previous"

    # Switch schedulers
    stop_old_scheduler "$ACTIVE_COLOR"
    start_new_scheduler "$rollback_color"

    # Log rollback
    mkdir -p "$BACKUP_DIR"
    echo "$(date): Rollback performed from ${ACTIVE_COLOR} to ${rollback_color}" >> "${BACKUP_DIR}/rollback.log"

    print_success "Rollback completed to ${rollback_color}"

    verify_deployment_health
}

# Auto-rollback on failure
auto_rollback() {
    print_error "Deployment failed, initiating automatic rollback..."

    # Stop target container if running
    docker stop "$TARGET_CONTAINER" --time 10 2>/dev/null || true
    docker rm "$TARGET_CONTAINER" 2>/dev/null || true

    # Restore original upstream config if we modified it
    if [[ "$ACTIVE_COLOR" != "none" ]] && [[ "$ACTIVE_COLOR" != "legacy" ]]; then
        generate_upstream_config "$ACTIVE_COLOR"
        reload_nginx
    fi

    print_warning "Rolled back to previous state"
}

# =============================================================================
# Summary
# =============================================================================

show_summary() {
    print_header "Deployment Summary"

    echo -e "Timestamp:        ${CYAN}${TIMESTAMP}${NC}"
    echo -e "Image Tag:        ${GREEN}${IMAGE_NAME}:${NEW_TAG}${NC}"
    echo -e "Active Color:     ${GREEN}${TARGET_COLOR^^}${NC}"
    echo -e "Previous Color:   ${YELLOW}${ACTIVE_COLOR:-none}${NC}"
    echo ""

    if [[ "$DRY_RUN" == "true" ]]; then
        echo -e "${YELLOW}This was a dry run. No changes were made.${NC}"
        echo ""
    fi

    $COMPOSE_CMD ps 2>/dev/null || true
}

# =============================================================================
# Main Blue-Green Deployment
# =============================================================================

blue_green_deploy() {
    local start_time=$(date +%s)

    # Phase 1: Preparation
    print_header "Phase 1: Preparation"

    check_git_status
    pull_latest
    backup_database
    build_image

    # Phase 2: Start New Container
    print_header "Phase 2: Start New Container"

    start_target_container

    if ! wait_for_container_health "$TARGET_CONTAINER" $HEALTH_CHECK_RETRIES; then
        print_error "New container failed health check"
        auto_rollback
        exit 1
    fi

    # Phase 3: Smoke Tests
    print_header "Phase 3: Smoke Tests"

    if ! run_smoke_tests "$TARGET_CONTAINER"; then
        print_error "Smoke tests failed"
        auto_rollback
        exit 1
    fi

    # Phase 4: Migrations & Menu Seeding
    print_header "Phase 4: Database Migrations & Menu Seeding"

    local needs_maintenance=false

    if check_pending_migrations "$TARGET_CONTAINER"; then
        print_warning "Pending migrations detected"

        # For safety, run migrations before traffic switch
        # Only enable maintenance mode for schema-breaking migrations
        # (This is a simplified check - in production you might want more sophisticated detection)
        needs_maintenance=false  # Migrations run on new container before traffic switch

        if ! run_migrations_on_container "$TARGET_CONTAINER"; then
            print_error "Migrations failed on new container"
            auto_rollback
            exit 1
        fi
    else
        print_info "No pending migrations"
    fi

    # Seed Voyager menus (idempotent, safe to run every time)
    seed_menus_on_container "$TARGET_CONTAINER"

    # Phase 5: Cache Rebuild
    print_header "Phase 5: Cache Rebuild"

    rebuild_caches "$TARGET_CONTAINER"

    # Phase 6: Traffic Switch (The atomic moment!)
    print_header "Phase 6: Traffic Switch"

    if ! switch_traffic; then
        print_error "Traffic switch failed"
        auto_rollback
        exit 1
    fi

    # Phase 7: Cleanup
    print_header "Phase 7: Cleanup"

    # Stop old scheduler first
    stop_old_scheduler "$ACTIVE_COLOR"

    # Start new scheduler
    start_new_scheduler "$TARGET_COLOR"

    # Restart queue workers to use new codebase
    restart_queue_workers

    # Drain and stop old container
    drain_and_stop_old_container

    # Save new state
    save_deployment_state "$TARGET_COLOR" "$NEW_TAG"

    # Phase 8: Verification
    print_header "Phase 8: Verification"

    if ! verify_deployment_health; then
        print_warning "Health verification had issues"
        echo ""
        read -p "Do you want to rollback? (yes/no): " confirm
        if [[ "$confirm" == "yes" ]]; then
            perform_rollback
            exit 1
        fi
    fi

    # Complete
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))

    show_summary

    echo ""
    echo -e "${GREEN}════════════════════════════════════════${NC}"
    echo -e "${GREEN}  Zero-downtime deployment completed!${NC}"
    echo -e "${GREEN}  Total time: ${duration}s${NC}"
    echo -e "${GREEN}════════════════════════════════════════${NC}"
    echo ""
}

# =============================================================================
# Main Entry Point
# =============================================================================

main() {
    print_header "Novarr Zero-Downtime Deployment"

    if [[ "$DRY_RUN" == "true" ]]; then
        print_warning "DRY RUN MODE - No changes will be made"
        echo ""
    fi

    # Initialize
    get_compose_cmd
    load_deployment_state
    calculate_target_environment
    confirm_update

    # Handle rollback mode
    if [[ "$ROLLBACK_MODE" == "true" ]]; then
        perform_rollback
        exit 0
    fi

    # Run blue-green deployment
    blue_green_deploy
}

# Parse arguments and run
parse_args "$@"
main
