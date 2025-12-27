# =============================================================================
# Novarr Docker Management Makefile
# =============================================================================
# Usage: make [command]
# Run 'make help' to see all available commands
# =============================================================================

.PHONY: help build build-no-cache build-dev deploy update restart stop down \
        up dev logs logs-app logs-mysql logs-nginx logs-redis logs-scheduler logs-worker \
        shell tinker migrate migrate-fresh migrate-status seed \
        seed-menus voyager-install voyager-admin \
        cache-clear cache-rebuild optimize \
        ps health status backup restore clean prune \
        artisan composer npm test lint \
        db-shell redis-cli nginx-reload \
        queue-work queue-restart queue-failed queue-retry queue-clear queue-health \
        supervisor-status supervisor-restart supervisor-restart-workers \
        supervisor-restart-scheduler supervisor-restart-octane \
        supervisor-logs supervisor-logs-workers supervisor-logs-scheduler supervisor-logs-octane \
        up-supervisor down-supervisor

# Default shell
SHELL := /bin/zsh

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
	@echo "$(GREEN)Build Commands:$(NC)"
	@grep -E '^(build|build-no-cache|build-dev):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Deployment Commands:$(NC)"
	@grep -E '^(deploy|update|restart|stop|down):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Development Commands:$(NC)"
	@grep -E '^(up|dev|logs|logs-app|logs-mysql|logs-nginx|logs-redis):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Shell Commands:$(NC)"
	@grep -E '^(shell|tinker|db-shell|redis-cli):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Database Commands:$(NC)"
	@grep -E '^(migrate|migrate-fresh|migrate-status|seed):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Voyager Commands:$(NC)"
	@grep -E '^(seed-menus|voyager-install|voyager-admin):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Cache Commands:$(NC)"
	@grep -E '^(cache-clear|cache-rebuild|optimize):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Utility Commands:$(NC)"
	@grep -E '^(ps|health|status|backup|restore|clean|prune|migrate-export):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(GREEN)Artisan/Composer/NPM:$(NC)"
	@grep -E '^(artisan|composer|npm|test|lint):.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(CYAN)%-20s$(NC) %s\n", $$1, $$2}'
	@echo ""
	@echo "$(YELLOW)Examples:$(NC)"
	@echo "  make up                          # Start services"
	@echo "  make logs                        # View all logs"
	@echo "  make shell                       # Open app shell"
	@echo "  make artisan CMD=\"migrate\"       # Run artisan command"
	@echo "  make composer CMD=\"require pkg\"  # Run composer command"
	@echo ""

# =============================================================================
# Build Commands
# =============================================================================

build: ## Build Docker images
	@chmod +x docker-build.sh && ./docker-build.sh

build-no-cache: ## Build Docker images without cache
	@chmod +x docker-build.sh && ./docker-build.sh --no-cache

build-dev: ## Build Docker images for development
	@$(COMPOSE_CMD) build

# =============================================================================
# Deployment Commands
# =============================================================================

deploy: ## Initial deployment (first-time setup)
	@chmod +x docker-deploy.sh && ./docker-deploy.sh

update: ## Update deployment (zero-downtime blue-green)
	@chmod +x docker-update.sh && ./docker-update.sh

update-dry-run: ## Preview update without making changes
	@chmod +x docker-update.sh && ./docker-update.sh --dry-run

update-verbose: ## Update with verbose output
	@chmod +x docker-update.sh && ./docker-update.sh --verbose

update-force: ## Force update (skip git checks)
	@chmod +x docker-update.sh && ./docker-update.sh --force --skip-confirm

rollback: ## Rollback to previous deployment
	@chmod +x docker-update.sh && ./docker-update.sh --rollback

deployment-status: ## Show current deployment state
	@echo "$(CYAN)Deployment State:$(NC)"
	@cat .deployment-state 2>/dev/null || echo "  No deployment state found"
	@echo ""
	@echo "$(CYAN)Running App Containers:$(NC)"
	@docker ps --filter "name=novarr-app" --format "table {{.Names}}\t{{.Image}}\t{{.Status}}"
	@echo ""
	@echo "$(CYAN)Nginx Upstream:$(NC)"
	@grep -E "^upstream roadrunner" docker/nginx/upstream.conf 2>/dev/null || echo "  Not configured"

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

dev: ## Start services with development overrides (hot-reloading)
	@$(COMPOSE_CMD) -f docker-compose.yml -f docker-compose.override.yml up -d

up-build: ## Start services and rebuild images
	@$(COMPOSE_CMD) up -d --build

logs: ## Tail logs from all services
	@$(COMPOSE_CMD) logs -f

logs-app: ## Tail logs from app service
	@$(COMPOSE_CMD) logs -f app

logs-mysql: ## Tail logs from MySQL service
	@$(COMPOSE_CMD) logs -f mysql

logs-nginx: ## Tail logs from Nginx service
	@$(COMPOSE_CMD) logs -f nginx

logs-redis: ## Tail logs from Redis service
	@$(COMPOSE_CMD) logs -f redis

logs-scheduler: ## Tail logs from scheduler service
	@$(COMPOSE_CMD) logs -f scheduler

logs-worker: ## Tail logs from worker service
	@$(COMPOSE_CMD) logs -f worker

# =============================================================================
# Shell Commands
# =============================================================================

shell: ## Open shell in app container
	@$(COMPOSE_CMD) exec app sh

bash: ## Open bash in app container
	@$(COMPOSE_CMD) exec app bash

tinker: ## Open Laravel Tinker REPL
	@$(COMPOSE_CMD) exec app php artisan tinker

db-shell: ## Open MySQL shell
	@$(COMPOSE_CMD) exec mysql mysql -u$$(grep DB_USERNAME .env | cut -d '=' -f2) -p$$(grep DB_PASSWORD .env | cut -d '=' -f2) $$(grep DB_DATABASE .env | cut -d '=' -f2)

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
# Voyager Commands
# =============================================================================

seed-menus: ## Seed Voyager menu items (idempotent)
	@$(COMPOSE_CMD) exec app php artisan db:seed --class=MenuItemsTableSeeder --force
	@echo "$(GREEN)Voyager menu items seeded$(NC)"

voyager-install: ## Install Voyager and seed menus
	@$(COMPOSE_CMD) exec app php artisan voyager:install
	@$(MAKE) seed-menus
	@echo "$(GREEN)Voyager installed and menus seeded$(NC)"

voyager-admin: ## Create Voyager admin user (use EMAIL=your@email.com)
	@if [ -z "$(EMAIL)" ]; then \
		echo "$(YELLOW)Usage: make voyager-admin EMAIL=\"your@email.com\"$(NC)"; \
		echo ""; \
		echo "This will create an admin user for Voyager."; \
	else \
		$(COMPOSE_CMD) exec app php artisan voyager:admin $(EMAIL) --create; \
		echo "$(GREEN)Voyager admin user created$(NC)"; \
	fi

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

smoke-test: ## Run smoke tests against current deployment
	@chmod +x docker/scripts/smoke-test.sh && ./docker/scripts/smoke-test.sh

smoke-test-verbose: ## Run smoke tests with verbose output
	@chmod +x docker/scripts/smoke-test.sh && ./docker/scripts/smoke-test.sh --verbose

smoke-test-blue: ## Run smoke tests against blue container
	@chmod +x docker/scripts/smoke-test.sh && ./docker/scripts/smoke-test.sh novarr-app-blue

smoke-test-green: ## Run smoke tests against green container
	@chmod +x docker/scripts/smoke-test.sh && ./docker/scripts/smoke-test.sh novarr-app-green

backup: ## Backup database and storage
	@mkdir -p backups
	@echo "$(CYAN)Backing up database...$(NC)"
	@$(COMPOSE_CMD) exec -T mysql mysqldump -u$$(grep DB_USERNAME .env | cut -d '=' -f2) -p$$(grep DB_PASSWORD .env | cut -d '=' -f2) $$(grep DB_DATABASE .env | cut -d '=' -f2) > backups/db-backup-$$(date +%Y%m%d-%H%M%S).sql
	@echo "$(GREEN)Database backed up to backups/$(NC)"
	@echo "$(CYAN)Backing up .env...$(NC)"
	@cp .env backups/env-backup-$$(date +%Y%m%d-%H%M%S)
	@echo "$(GREEN)Backup complete$(NC)"

restore: ## Restore database from backup (use BACKUP=filename)
	@if [ -z "$(BACKUP)" ]; then \
		echo "$(YELLOW)Usage: make restore BACKUP=backups/db-backup-YYYYMMDD-HHMMSS.sql$(NC)"; \
		echo ""; \
		echo "Available backups:"; \
		ls -la backups/*.sql 2>/dev/null || echo "  No backups found"; \
	else \
		echo "$(CYAN)Restoring from $(BACKUP)...$(NC)"; \
		$(COMPOSE_CMD) exec -T mysql mysql -u$$(grep DB_USERNAME .env | cut -d '=' -f2) -p$$(grep DB_PASSWORD .env | cut -d '=' -f2) $$(grep DB_DATABASE .env | cut -d '=' -f2) < $(BACKUP); \
		echo "$(GREEN)Database restored$(NC)"; \
	fi

clean: ## Remove unused Docker images
	@echo "$(CYAN)Removing unused images...$(NC)"
	@docker image prune -f
	@echo "$(GREEN)Cleanup complete$(NC)"

prune: ## Remove all unused Docker resources (careful!)
	@echo "$(YELLOW)This will remove all unused containers, networks, images, and volumes$(NC)"
	@read -p "Are you sure? (y/n) " confirm && [ "$$confirm" = "y" ] && docker system prune -af --volumes || echo "Cancelled"

migrate-export: ## Export dev database and storage for migration to production
	@chmod +x scripts/migrate-from-dev.sh && ./scripts/migrate-from-dev.sh

# =============================================================================
# Artisan/Composer/NPM Commands
# =============================================================================

artisan: ## Run artisan command (use CMD="command")
	@if [ -z "$(CMD)" ]; then \
		echo "$(YELLOW)Usage: make artisan CMD=\"your:command\"$(NC)"; \
		echo ""; \
		echo "Examples:"; \
		echo "  make artisan CMD=\"migrate\""; \
		echo "  make artisan CMD=\"make:model User\""; \
		echo "  make artisan CMD=\"queue:work\""; \
	else \
		$(COMPOSE_CMD) exec app php artisan $(CMD); \
	fi

composer: ## Run composer command (use CMD="command")
	@if [ -z "$(CMD)" ]; then \
		echo "$(YELLOW)Usage: make composer CMD=\"your command\"$(NC)"; \
		echo ""; \
		echo "Examples:"; \
		echo "  make composer CMD=\"install\""; \
		echo "  make composer CMD=\"require package/name\""; \
		echo "  make composer CMD=\"update\""; \
	else \
		$(COMPOSE_CMD) exec app composer $(CMD); \
	fi

npm: ## Run npm command (use CMD="command")
	@if [ -z "$(CMD)" ]; then \
		echo "$(YELLOW)Usage: make npm CMD=\"your command\"$(NC)"; \
		echo ""; \
		echo "Examples:"; \
		echo "  make npm CMD=\"install\""; \
		echo "  make npm CMD=\"run dev\""; \
		echo "  make npm CMD=\"run build\""; \
	else \
		$(COMPOSE_CMD) exec app npm $(CMD); \
	fi

yarn: ## Run yarn command (use CMD="command")
	@if [ -z "$(CMD)" ]; then \
		echo "$(YELLOW)Usage: make yarn CMD=\"your command\"$(NC)"; \
		echo ""; \
		echo "Examples:"; \
		echo "  make yarn CMD=\"install\""; \
		echo "  make yarn CMD=\"dev\""; \
		echo "  make yarn CMD=\"build\""; \
	else \
		$(COMPOSE_CMD) exec app yarn $(CMD); \
	fi

test: ## Run PHPUnit tests
	@$(COMPOSE_CMD) exec app php artisan test

test-coverage: ## Run tests with coverage
	@$(COMPOSE_CMD) exec app php artisan test --coverage

lint: ## Run PHP linting
	@$(COMPOSE_CMD) exec app ./vendor/bin/pint --test || true

lint-fix: ## Fix PHP linting issues
	@$(COMPOSE_CMD) exec app ./vendor/bin/pint || true

# =============================================================================
# Nginx Commands
# =============================================================================

nginx-reload: ## Reload Nginx configuration
	@$(COMPOSE_CMD) exec nginx nginx -s reload
	@echo "$(GREEN)Nginx configuration reloaded$(NC)"

nginx-test: ## Test Nginx configuration
	@$(COMPOSE_CMD) exec nginx nginx -t

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
# Supervisor Commands (for supervisor variant)
# =============================================================================

supervisor-status: ## Show supervisor process status
	@$(COMPOSE_CMD) exec app-supervisor supervisorctl status

supervisor-restart: ## Restart all supervisor processes
	@$(COMPOSE_CMD) exec app-supervisor supervisorctl restart all
	@echo "$(GREEN)All supervisor processes restarted$(NC)"

supervisor-restart-workers: ## Restart only queue workers
	@$(COMPOSE_CMD) exec app-supervisor supervisorctl restart laravel-worker:*
	@echo "$(GREEN)Queue workers restarted$(NC)"

supervisor-restart-scheduler: ## Restart only scheduler
	@$(COMPOSE_CMD) exec app-supervisor supervisorctl restart laravel-scheduler
	@echo "$(GREEN)Scheduler restarted$(NC)"

supervisor-restart-octane: ## Restart only Octane server
	@$(COMPOSE_CMD) exec app-supervisor supervisorctl restart laravel-octane
	@echo "$(GREEN)Octane server restarted$(NC)"

supervisor-logs: ## Tail supervisor main logs
	@$(COMPOSE_CMD) exec app-supervisor tail -f /var/log/supervisor/supervisord.log

supervisor-logs-workers: ## Tail worker logs
	@$(COMPOSE_CMD) exec app-supervisor tail -f /var/log/supervisor/laravel-worker.log

supervisor-logs-scheduler: ## Tail scheduler logs
	@$(COMPOSE_CMD) exec app-supervisor tail -f /var/log/supervisor/laravel-scheduler.log

supervisor-logs-octane: ## Tail Octane logs
	@$(COMPOSE_CMD) exec app-supervisor tail -f /var/log/supervisor/laravel-octane.log

# Supervisor variant startup
up-supervisor: ## Start services using supervisor variant (single container for app+workers+scheduler)
	@$(COMPOSE_CMD) -f docker-compose.yml -f docker-compose.supervisor.yml up -d mysql redis nginx app-supervisor
	@echo "$(GREEN)Supervisor variant started$(NC)"

down-supervisor: ## Stop supervisor variant services
	@$(COMPOSE_CMD) -f docker-compose.yml -f docker-compose.supervisor.yml down
	@echo "$(GREEN)Supervisor variant stopped$(NC)"

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

key-generate: ## Generate application key
	@$(COMPOSE_CMD) exec app php artisan key:generate
	@echo "$(GREEN)Application key generated$(NC)"
