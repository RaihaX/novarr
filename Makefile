# =============================================================================
# Novarr Docker Management Makefile
# =============================================================================
# Drives the clone-and-run stack in docker-compose.yml (Octane app, one-shot
# migration, scheduler, MySQL, Redis, FlareSolverr). The one-click stacks
# (docker-compose.oneclick.yml / docker-compose.tailscale.yml) need none of it.
#
# Usage: make [command]
# Run 'make help' to see all available commands
# =============================================================================

.PHONY: help build build-no-cache deploy restart restart-app stop down down-volumes \
        up dev logs logs-app logs-mysql logs-redis logs-scheduler \
        shell tinker db-shell redis-cli \
        migrate migrate-force migrate-fresh migrate-fresh-seed migrate-status migrate-rollback seed \
        cache-clear cache-rebuild optimize optimize-clear \
        ps status health smoke-test smoke-test-verbose backup backup-list restore clean prune \
        artisan \
        queue-work queue-restart queue-failed queue-retry queue-clear queue-health \
        maintenance-on maintenance-off storage-link storage-permissions key-generate \
        release-android release-android-dry

# POSIX shell: the servers this runs on (Debian, Unraid) have no zsh.
SHELL := /bin/sh

# Docker Compose command detection
COMPOSE_CMD := $(shell docker compose version > /dev/null 2>&1 && echo "docker compose" || echo "docker-compose")


# Colors
CYAN := \033[0;36m
GREEN := \033[0;32m
YELLOW := \033[1;33m
NC := \033[0m

# =============================================================================
# Help
# =============================================================================

help: ## Show this help message
	@echo ""
	@echo "$(CYAN)Novarr Docker Management$(NC)"
	@echo "$(CYAN)========================$(NC)"
	@echo ""
	@echo "$(GREEN)Build & Deployment Commands:$(NC)"
	@grep -E '^(build|build-no-cache|deploy|restart|restart-app|stop|down|down-volumes):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Development Commands:$(NC)"
	@grep -E '^(up|dev|logs|logs-app|logs-mysql|logs-redis|logs-scheduler):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Shell Commands:$(NC)"
	@grep -E '^(shell|tinker|db-shell|redis-cli):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Database Commands:$(NC)"
	@grep -E '^(migrate|migrate-force|migrate-fresh|migrate-fresh-seed|migrate-status|migrate-rollback|seed):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Cache Commands:$(NC)"
	@grep -E '^(cache-clear|cache-rebuild|optimize|optimize-clear):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Utility Commands:$(NC)"
	@grep -E '^(ps|status|health|smoke-test|smoke-test-verbose|backup|backup-list|restore|clean|prune):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Artisan, Queue & Maintenance:$(NC)"
	@grep -E '^(artisan|queue-[a-z]+|maintenance-on|maintenance-off|storage-link|storage-permissions|key-generate):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Android App:$(NC)"
	@grep -E '^(release-android|release-android-dry):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(YELLOW)Examples:$(NC)"
	@echo "  make deploy                      # Build the image, start, migrate"
	@echo "  make logs                        # View all logs"
	@echo "  make shell                       # Open app shell"
	@echo "  make artisan CMD=\"migrate\"       # Run artisan command"
	@echo "  make release-android-dry         # Show what release-android would build and copy"
	@echo ""
	@echo "Tests, linting, composer and yarn run on the host, not in the"
	@echo "production image (it has no dev dependencies, composer or Node)."
	@echo ""

# =============================================================================
# Build & Deployment Commands
# =============================================================================

build: ## Build the Docker image from this checkout (novarr:local)
	@$(COMPOSE_CMD) build

build-no-cache: ## Build the Docker image without cache
	@$(COMPOSE_CMD) build --no-cache

deploy: ## Build the image and (re)start the stack; the init service runs migrations
	@$(COMPOSE_CMD) up -d --build
	@echo "$(GREEN)Deployed. Open http://localhost/ (NOVARR_PORT changes the port).$(NC)"

restart: ## Restart all services
	@$(COMPOSE_CMD) restart

restart-app: ## Restart only the app service
	@$(COMPOSE_CMD) restart app

stop: ## Stop all services
	@$(COMPOSE_CMD) stop

down: ## Stop and remove all containers
	@$(COMPOSE_CMD) down

down-volumes: ## Stop and remove containers with volumes
	@$(COMPOSE_CMD) down -v

# =============================================================================
# Development Commands
# =============================================================================

up: ## Start all services in background
	@$(COMPOSE_CMD) up -d

dev: ## Start services with the development overlay (code bind-mounted)
	@$(COMPOSE_CMD) -f docker-compose.yml -f docker-compose.dev.yml up -d --build

logs: ## Tail logs from all services
	@$(COMPOSE_CMD) logs -f

logs-app: ## Tail logs from app service
	@$(COMPOSE_CMD) logs -f app

logs-mysql: ## Tail logs from MySQL service
	@$(COMPOSE_CMD) logs -f mysql

logs-redis: ## Tail logs from Redis service
	@$(COMPOSE_CMD) logs -f redis

logs-scheduler: ## Tail logs from scheduler service
	@$(COMPOSE_CMD) logs -f scheduler

# =============================================================================
# Shell Commands
# =============================================================================

shell: ## Open shell in app container
	@$(COMPOSE_CMD) exec app sh

tinker: ## Open Laravel Tinker REPL
	@$(COMPOSE_CMD) exec app php artisan tinker

# Credentials come from the mysql container's own environment, through
# MYSQL_PWD rather than argv.
db-shell: ## Open MySQL shell
	@$(COMPOSE_CMD) exec mysql sh -c 'MYSQL_PWD="$$MYSQL_PASSWORD" exec mysql -u"$$MYSQL_USER" "$$MYSQL_DATABASE"'

redis-cli: ## Open Redis CLI
	@$(COMPOSE_CMD) exec redis redis-cli

# =============================================================================
# Database Commands
# =============================================================================

migrate: ## Run database migrations
	@$(COMPOSE_CMD) exec app php artisan migrate

migrate-force: ## Run database migrations (force, for production)
	@$(COMPOSE_CMD) exec app php artisan migrate --force

migrate-fresh: ## Fresh migration (drops all tables)
	@$(COMPOSE_CMD) exec app php artisan migrate:fresh

migrate-fresh-seed: ## Fresh migration with seeding
	@$(COMPOSE_CMD) exec app php artisan migrate:fresh --seed

migrate-status: ## Show migration status
	@$(COMPOSE_CMD) exec app php artisan migrate:status

migrate-rollback: ## Rollback last migration batch
	@$(COMPOSE_CMD) exec app php artisan migrate:rollback

seed: ## Run database seeders
	@$(COMPOSE_CMD) exec app php artisan db:seed

# =============================================================================
# Cache Commands
# =============================================================================

cache-clear: ## Clear all caches
	@$(COMPOSE_CMD) exec app php artisan cache:clear
	@$(COMPOSE_CMD) exec app php artisan config:clear
	@$(COMPOSE_CMD) exec app php artisan route:clear
	@$(COMPOSE_CMD) exec app php artisan view:clear
	@echo "$(GREEN)All caches cleared$(NC)"

cache-rebuild: ## Rebuild all caches (for production)
	@$(COMPOSE_CMD) exec app php artisan config:cache
	@$(COMPOSE_CMD) exec app php artisan route:cache
	@$(COMPOSE_CMD) exec app php artisan view:cache
	@echo "$(GREEN)All caches rebuilt$(NC)"

optimize: ## Optimize the application
	@$(COMPOSE_CMD) exec app php artisan optimize
	@echo "$(GREEN)Application optimized$(NC)"

optimize-clear: ## Clear optimization cache
	@$(COMPOSE_CMD) exec app php artisan optimize:clear
	@echo "$(GREEN)Optimization cache cleared$(NC)"

# =============================================================================
# Utility Commands
# =============================================================================

ps: ## Show container status
	@$(COMPOSE_CMD) ps

status: ## Show detailed container status
	@$(COMPOSE_CMD) ps -a
	@echo ""
	@echo "$(CYAN)Docker Images:$(NC)"
	@docker images | grep novarr || true

health: ## Check health endpoints
	@echo "$(CYAN)Checking health endpoints...$(NC)"
	@echo ""
	@echo "App Health:"
	@curl -s http://localhost:8000/api/health 2>/dev/null || curl -s http://localhost/api/health 2>/dev/null || echo "  $(YELLOW)Not responding$(NC)"
	@echo ""
	@echo "App Ping:"
	@curl -s http://localhost:8000/api/ping 2>/dev/null || curl -s http://localhost/api/ping 2>/dev/null || echo "  $(YELLOW)Not responding$(NC)"
	@echo ""

smoke-test: ## Run smoke tests against the running stack
	@bash docker/scripts/smoke-test.sh

smoke-test-verbose: ## Run smoke tests with verbose output
	@bash docker/scripts/smoke-test.sh novarr-app-1 --verbose

backup: ## Back up database + storage/app (php artisan backup:run in the app container)
	@echo "$(CYAN)Running backup:run...$(NC)"
	@$(COMPOSE_CMD) exec app php artisan backup:run
	@echo "$(GREEN)Backup zip written to the BACKUP_DISK disk (default: storage/app/private/Novarr/)$(NC)"

backup-list: ## List backups and their health
	@$(COMPOSE_CMD) exec app php artisan backup:list

restore: ## Load a plain .sql dump into MySQL (use BACKUP=file.sql; see readme "Backup and restore")
	@if [ -z "$(BACKUP)" ]; then \
		echo "$(YELLOW)Usage: make restore BACKUP=path/to/dump.sql$(NC)"; \
		echo ""; \
		echo "Unzip a backup first: unzip <backup>.zip 'db-dumps/*' -d /tmp/novarr-restore"; \
		echo ""; \
		echo "Available backups:"; \
		ls -la storage/app/private/Novarr/*.zip 2>/dev/null || echo "  No backups found"; \
	else \
		echo "$(CYAN)Restoring from $(BACKUP)...$(NC)"; \
		$(COMPOSE_CMD) exec -T mysql sh -c 'MYSQL_PWD="$$MYSQL_PASSWORD" exec mysql -u"$$MYSQL_USER" "$$MYSQL_DATABASE"' < "$(BACKUP)" && \
		echo "$(GREEN)Database restored$(NC)"; \
	fi

clean: ## Remove unused Docker images
	@echo "$(CYAN)Removing unused images...$(NC)"
	@docker image prune -f
	@echo "$(GREEN)Cleanup complete$(NC)"

prune: ## Remove all unused Docker resources (careful!)
	@echo "$(YELLOW)This will remove all unused containers, networks, images, and volumes$(NC)"
	@printf "Are you sure? (y/n) "; read confirm; [ "$$confirm" = "y" ] && docker system prune -af --volumes || echo "Cancelled"

# =============================================================================
# Artisan Commands
# =============================================================================

artisan: ## Run artisan command (use CMD="command")
	@if [ -z "$(CMD)" ]; then \
		echo "$(YELLOW)Usage: make artisan CMD=\"your:command\"$(NC)"; \
		echo ""; \
		echo "Examples:"; \
		echo "  make artisan CMD=\"migrate:status\""; \
		echo "  make artisan CMD=\"novel:toc\""; \
		echo "  make artisan CMD=\"queue:failed\""; \
	else \
		$(COMPOSE_CMD) exec app php artisan $(CMD); \
	fi

# =============================================================================
# Queue Commands
# =============================================================================

queue-work: ## Start queue worker
	@$(COMPOSE_CMD) exec app php artisan queue:work

queue-restart: ## Restart queue workers
	@$(COMPOSE_CMD) exec app php artisan queue:restart
	@echo "$(GREEN)Queue workers will restart after current job$(NC)"

queue-failed: ## List failed queue jobs
	@$(COMPOSE_CMD) exec app php artisan queue:failed

queue-retry: ## Retry all failed jobs
	@$(COMPOSE_CMD) exec app php artisan queue:retry all

queue-clear: ## Clear all queued jobs
	@$(COMPOSE_CMD) exec app php artisan queue:clear

queue-health: ## Check queue health status
	@$(COMPOSE_CMD) exec app php artisan queue:health-check

# =============================================================================
# Maintenance Mode
# =============================================================================

maintenance-on: ## Enable maintenance mode
	@$(COMPOSE_CMD) exec app php artisan down --retry=60 --refresh=5
	@echo "$(YELLOW)Maintenance mode enabled$(NC)"

maintenance-off: ## Disable maintenance mode
	@$(COMPOSE_CMD) exec app php artisan up
	@echo "$(GREEN)Maintenance mode disabled$(NC)"

# =============================================================================
# Storage Commands
# =============================================================================

storage-link: ## Create storage symlink
	@$(COMPOSE_CMD) exec app php artisan storage:link
	@echo "$(GREEN)Storage link created$(NC)"

storage-permissions: ## Fix storage permissions
	@chmod -R 775 storage bootstrap/cache
	@echo "$(GREEN)Storage permissions fixed$(NC)"

# =============================================================================
# Key Generation
# =============================================================================

key-generate: ## Print a new APP_KEY (put it in .env; the image has no .env to write)
	@$(COMPOSE_CMD) exec app php artisan key:generate --show

# =============================================================================
# Android App
# =============================================================================
# Builds the signed release APK on this machine (macOS with Homebrew's JDK 17
# and Android command-line tools by default) and copies it, with the
# "<apk>.json" sidecar the server and the app's update check read, to the
# server. The JSON goes last so the server never advertises a build whose APK
# isn't there yet. Override any of these on the command line, e.g.
#   make release-android NOVARR_DEPLOY_HOST=root@myserver
# DRY_RUN=1 (or release-android-dry) prints the build and copy commands
# instead of running them.

NOVARR_ANDROID_SIGNING ?= /Volumes/SecondaryStorage/Projects/.secrets/novarr-android-signing.properties
NOVARR_DEPLOY_HOST ?= root@192.168.1.34
NOVARR_ANDROID_APK_DEST ?= /var/www/novarr/storage/app/apps/novarr.apk
NOVARR_JAVA_HOME ?= /opt/homebrew/opt/openjdk@17
NOVARR_ANDROID_HOME ?= /opt/homebrew/share/android-commandlinetools
ANDROID_GRADLE_FILE := android/app/build.gradle.kts
ANDROID_RELEASE_APK := android/app/build/outputs/apk/release/app-release.apk
ANDROID_RELEASE_JSON := android/app/build/outputs/apk/release/novarr.apk.json
DRY_RUN ?=

release-android: ## Build the signed Android APK and publish it to the server (DRY_RUN=1: print only)
	@set -e; \
	run() { if [ -n "$(DRY_RUN)" ]; then echo "  would run: $$*"; else "$$@"; fi; }; \
	version=$$(sed -n 's/^[[:space:]]*versionName[[:space:]]*=[[:space:]]*"\([^"]*\)".*/\1/p' $(ANDROID_GRADLE_FILE) | head -n 1); \
	build=$$(sed -n 's/^[[:space:]]*versionCode[[:space:]]*=[[:space:]]*\([0-9][0-9]*\).*/\1/p' $(ANDROID_GRADLE_FILE) | head -n 1); \
	if [ -z "$$version" ] || [ -z "$$build" ]; then echo "$(YELLOW)Couldn't read versionName/versionCode from $(ANDROID_GRADLE_FILE)$(NC)"; exit 1; fi; \
	echo "$(CYAN)Novarr for Android $$version (build $$build)$(NC)"; \
	if [ -z "$(DRY_RUN)" ] && [ ! -f "$(NOVARR_ANDROID_SIGNING)" ]; then echo "$(YELLOW)Signing properties not found: $(NOVARR_ANDROID_SIGNING)$(NC)"; exit 1; fi; \
	run env JAVA_HOME="$(NOVARR_JAVA_HOME)" ANDROID_HOME="$(NOVARR_ANDROID_HOME)" ./android/gradlew -p android :app:assembleRelease -Pnovarr.release="$(NOVARR_ANDROID_SIGNING)"; \
	if [ -z "$(DRY_RUN)" ]; then \
		[ -f "$(ANDROID_RELEASE_APK)" ] || { echo "$(YELLOW)No signed APK at $(ANDROID_RELEASE_APK): is the signing file complete?$(NC)"; exit 1; }; \
		printf '{"version": "%s", "build": %s}\n' "$$version" "$$build" > "$(ANDROID_RELEASE_JSON)"; \
	else \
		echo "  would write $(ANDROID_RELEASE_JSON): {\"version\": \"$$version\", \"build\": $$build}"; \
	fi; \
	run scp "$(ANDROID_RELEASE_APK)" "$(NOVARR_DEPLOY_HOST):$(NOVARR_ANDROID_APK_DEST)"; \
	run scp "$(ANDROID_RELEASE_JSON)" "$(NOVARR_DEPLOY_HOST):$(NOVARR_ANDROID_APK_DEST).json"; \
	if [ -n "$(DRY_RUN)" ]; then \
		echo "$(YELLOW)Dry run: nothing was built or copied.$(NC)"; \
	else \
		echo "$(GREEN)Published $$version (build $$build) to $(NOVARR_DEPLOY_HOST):$(NOVARR_ANDROID_APK_DEST)$(NC)"; \
		ls -l "$(ANDROID_RELEASE_APK)"; \
	fi

release-android-dry: ## Print what release-android would build and copy, without doing it
	@$(MAKE) --no-print-directory release-android DRY_RUN=1
