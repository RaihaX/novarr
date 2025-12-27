#!/bin/zsh

# =============================================================================
# Novarr Migration Export Script
# =============================================================================
# Exports development database and storage for migration to production
# Usage: ./scripts/migrate-from-dev.sh [options]
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
ENV_FILE=".env"
MIGRATE_DIR="./migrate"

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

# Show help
show_help() {
    echo "Usage: ./scripts/migrate-from-dev.sh [options]"
    echo ""
    echo "Options:"
    echo "  -h, --help           Show this help message"
    echo "  -y, --yes            Skip confirmation prompts"
    echo "  --skip-storage       Skip storage directory export"
    echo "  --skip-db            Skip database export"
    echo ""
    echo "This script exports your development database and storage for migration:"
    echo "  1. Reads database credentials from .env"
    echo "  2. Creates mysqldump of the database"
    echo "  3. Archives the storage directory"
    echo "  4. Copies .env for reference"
    echo "  5. Generates migration metadata"
    echo ""
    echo "Output files are created in ./migrate/"
    echo ""
    exit 0
}

# Parse arguments
SKIP_CONFIRM=false
SKIP_STORAGE=false
SKIP_DB=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            ;;
        -y|--yes)
            SKIP_CONFIRM=true
            shift
            ;;
        --skip-storage)
            SKIP_STORAGE=true
            shift
            ;;
        --skip-db)
            SKIP_DB=true
            shift
            ;;
        *)
            print_error "Unknown option: $1"
            echo "Use --help to see available options"
            exit 1
            ;;
    esac
done

# Check prerequisites
check_prerequisites() {
    print_step "Checking prerequisites..."

    # Check for .env file
    if [[ ! -f "$ENV_FILE" ]]; then
        print_error ".env file not found"
        print_info "This script should be run from the Novarr project root directory"
        exit 1
    fi

    # Check for mysqldump
    if ! command -v mysqldump &> /dev/null; then
        print_error "mysqldump command not found"
        print_info "Please install MySQL client tools"
        exit 1
    fi

    # Check for tar
    if ! command -v tar &> /dev/null; then
        print_error "tar command not found"
        exit 1
    fi

    # Check storage directory exists
    if [[ ! -d "storage" ]]; then
        print_warning "storage directory not found"
        SKIP_STORAGE=true
    fi

    print_success "Prerequisites check passed"
}

# Read database credentials from .env
read_env_credentials() {
    print_step "Reading database credentials from .env..."

    DB_HOST=$(grep "^DB_HOST=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_PORT=$(grep "^DB_PORT=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_DATABASE=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_USERNAME=$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
    DB_PASSWORD=$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d '=' -f2 | tr -d '"' | tr -d "'")

    # Set defaults if not found
    DB_HOST=${DB_HOST:-127.0.0.1}
    DB_PORT=${DB_PORT:-3306}
    DB_DATABASE=${DB_DATABASE:-novarr}
    DB_USERNAME=${DB_USERNAME:-root}

    if [[ -z "$DB_PASSWORD" ]]; then
        print_warning "DB_PASSWORD not found in .env"
        print_info "You may be prompted for the password"
    fi

    print_success "Database: $DB_DATABASE on $DB_HOST:$DB_PORT"
}

# Test database connection
test_db_connection() {
    print_step "Testing database connection..."

    if mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" "$DB_DATABASE" &> /dev/null; then
        print_success "Database connection successful"
    else
        print_error "Failed to connect to database"
        print_info "Please verify your database credentials in .env"
        exit 1
    fi
}

# Show export summary
show_export_summary() {
    print_header "Migration Export Summary"

    echo "The following will be exported:"
    echo ""

    if [[ "$SKIP_DB" != true ]]; then
        echo -e "  ${CYAN}Database:${NC} $DB_DATABASE → $MIGRATE_DIR/dump.sql"
    else
        echo -e "  ${YELLOW}Database:${NC} Skipped"
    fi

    if [[ "$SKIP_STORAGE" != true ]]; then
        local storage_size=$(du -sh storage 2>/dev/null | cut -f1 || echo "unknown")
        echo -e "  ${CYAN}Storage:${NC}  storage/ ($storage_size) → $MIGRATE_DIR/storage.tar.gz"
    else
        echo -e "  ${YELLOW}Storage:${NC}  Skipped"
    fi

    echo -e "  ${CYAN}Env:${NC}      .env → $MIGRATE_DIR/.env.backup"
    echo ""

    if [[ "$SKIP_CONFIRM" != true ]]; then
        echo -n "Proceed with export? [y/N] "
        read -r confirm
        if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
            print_info "Export cancelled"
            exit 0
        fi
    fi
}

# Create migrate directory
create_migrate_dir() {
    print_step "Creating migration directory..."

    if [[ -d "$MIGRATE_DIR" ]]; then
        print_warning "migrate/ directory already exists"
        if [[ "$SKIP_CONFIRM" != true ]]; then
            echo -n "Overwrite existing migration files? [y/N] "
            read -r confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                print_info "Export cancelled"
                exit 0
            fi
        fi
        rm -rf "$MIGRATE_DIR"/*
    fi

    mkdir -p "$MIGRATE_DIR"
    print_success "Migration directory created: $MIGRATE_DIR"
}

# Export database
export_database() {
    if [[ "$SKIP_DB" == true ]]; then
        print_info "Skipping database export"
        return
    fi

    print_step "Exporting database..."
    echo -n "  Progress: "

    local dump_file="$MIGRATE_DIR/dump.sql"

    if mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        --add-drop-table \
        "$DB_DATABASE" > "$dump_file" 2>/dev/null; then

        local dump_size=$(du -sh "$dump_file" | cut -f1)
        echo ""
        print_success "Database exported: $dump_file ($dump_size)"
    else
        echo ""
        print_error "Failed to export database"
        exit 1
    fi
}

# Archive storage directory
archive_storage() {
    if [[ "$SKIP_STORAGE" == true ]]; then
        print_info "Skipping storage export"
        return
    fi

    print_step "Archiving storage directory..."
    echo -n "  Progress: "

    local archive_file="$MIGRATE_DIR/storage.tar.gz"

    if tar -czf "$archive_file" storage/ 2>/dev/null; then
        local archive_size=$(du -sh "$archive_file" | cut -f1)
        echo ""
        print_success "Storage archived: $archive_file ($archive_size)"
    else
        echo ""
        print_error "Failed to archive storage"
        exit 1
    fi
}

# Copy .env file
copy_env() {
    print_step "Copying .env file..."

    cp "$ENV_FILE" "$MIGRATE_DIR/.env.backup"
    print_success "Environment file copied: $MIGRATE_DIR/.env.backup"
}

# Generate migration metadata
generate_metadata() {
    print_step "Generating migration metadata..."

    local metadata_file="$MIGRATE_DIR/migration-info.txt"
    local db_size=""
    local storage_size=""

    if [[ -f "$MIGRATE_DIR/dump.sql" ]]; then
        db_size=$(du -sh "$MIGRATE_DIR/dump.sql" | cut -f1)
    fi

    if [[ -f "$MIGRATE_DIR/storage.tar.gz" ]]; then
        storage_size=$(du -sh "$MIGRATE_DIR/storage.tar.gz" | cut -f1)
    fi

    cat > "$metadata_file" << EOF
# Novarr Migration Export Metadata
# Generated: $(date '+%Y-%m-%d %H:%M:%S')
# ============================================

EXPORT_DATE=$(date '+%Y-%m-%d')
EXPORT_TIME=$(date '+%H:%M:%S')
EXPORT_TIMESTAMP=$(date +%s)

# Source Database
DB_DATABASE=$DB_DATABASE
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT

# Export Files
DUMP_FILE=dump.sql
DUMP_SIZE=$db_size
STORAGE_ARCHIVE=storage.tar.gz
STORAGE_SIZE=$storage_size

# Import Instructions
# -------------------
# 1. Copy this migrate/ directory to your production server
# 2. Run: ./docker-deploy.sh --migrate
#    OR manually import:
#    - Database: docker exec -i novarr-mysql mysql -u root -p novarr < dump.sql
#    - Storage:  tar xzf storage.tar.gz -C .
EOF

    print_success "Metadata generated: $metadata_file"
}

# Show final summary
show_final_summary() {
    print_header "Export Complete"

    echo "Migration files created in $MIGRATE_DIR/"
    echo ""
    ls -lh "$MIGRATE_DIR/"
    echo ""

    echo -e "${CYAN}Next Steps:${NC}"
    echo ""
    echo "1. Transfer the migrate/ directory to your production server:"
    echo -e "   ${YELLOW}scp -r ./migrate/ user@your-server:/mnt/user/appdata/novarr/${NC}"
    echo ""
    echo "2. On the production server, run:"
    echo -e "   ${YELLOW}./docker-deploy.sh --migrate${NC}"
    echo ""
    echo "3. Or manually import:"
    echo -e "   ${YELLOW}docker exec -i novarr-mysql mysql -u root -p\$DB_ROOT_PASSWORD novarr < migrate/dump.sql${NC}"
    echo -e "   ${YELLOW}tar xzf migrate/storage.tar.gz -C .${NC}"
    echo ""

    print_info "See DOCKER.md for detailed migration instructions"
}

# Main execution
main() {
    print_header "Novarr Migration Export"

    check_prerequisites
    read_env_credentials

    if [[ "$SKIP_DB" != true ]]; then
        test_db_connection
    fi

    show_export_summary
    create_migrate_dir
    export_database
    archive_storage
    copy_env
    generate_metadata
    show_final_summary
}

# Run main
main
