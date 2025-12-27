# Novarr Unraid Deployment Guide

A comprehensive guide for deploying Novarr on an Unraid server.

## Table of Contents

- [Introduction](#introduction)
- [Architecture Overview](#architecture-overview)
- [Pre-Installation Checklist](#pre-installation-checklist)
- [Installation Methods](#installation-methods)
- [Initial Setup](#initial-setup)
- [Deployment Steps](#deployment-steps)
- [Migrating Existing Data](#migrating-existing-data)
- [Configuration](#configuration)
- [Updating Novarr](#updating-novarr)
- [Monitoring & Maintenance](#monitoring--maintenance)
- [Troubleshooting](#troubleshooting)
- [Advanced Configuration](#advanced-configuration)
- [Backup & Disaster Recovery](#backup--disaster-recovery)
- [Performance Optimization](#performance-optimization)
- [Security Best Practices](#security-best-practices)

## Introduction

### What is Novarr?

Novarr is a web novel management application that allows you to:
- Scrape and download web novels from various sources
- Organize novels with metadata and cover images
- Generate ePub files for offline reading
- Manage your novel library through a web-based admin panel (Voyager)

### Why Deploy on Unraid?

Unraid provides an excellent platform for self-hosted applications:
- **Easy Container Management**: Docker integration with a user-friendly UI
- **Flexible Storage**: Mix and match drives with parity protection
- **Community Support**: Large library of container templates
- **Always On**: Ideal for applications that need to run scheduled tasks

### Benefits of Containerized Deployment

- **Isolation**: Novarr runs independently of other applications
- **Reproducibility**: Consistent environment across different systems
- **Easy Updates**: Zero-downtime updates with blue-green deployment
- **Resource Control**: Set memory and CPU limits as needed

## Architecture Overview

### Container Architecture on Unraid

```
┌─────────────────────────────────────────────────────────────────────┐
│                         UNRAID SERVER                                │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │                      Docker Engine                             │  │
│  │  ┌─────────────────────────────────────────────────────────┐  │  │
│  │  │              Novarr Docker Network (Bridge)              │  │  │
│  │  │                                                          │  │  │
│  │  │  ┌──────────┐  ┌──────────┐  ┌──────────┐              │  │  │
│  │  │  │  Nginx   │  │App Blue  │  │App Green │              │  │  │
│  │  │  │  :80/443 │  │  :8000   │  │  :8000   │              │  │  │
│  │  │  └────┬─────┘  └────┬─────┘  └────┬─────┘              │  │  │
│  │  │       │             │             │                     │  │  │
│  │  │       └─────────────┴─────────────┘                     │  │  │
│  │  │                     │                                   │  │  │
│  │  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌────────┐ │  │  │
│  │  │  │  MySQL   │  │  Redis   │  │Scheduler │  │ Worker │ │  │  │
│  │  │  │  :3306   │  │  :6379   │  │          │  │        │ │  │  │
│  │  │  └──────────┘  └──────────┘  └──────────┘  └────────┘ │  │  │
│  │  └─────────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────────┘  │
│                                                                      │
│  ┌───────────────────────────────────────────────────────────────┐  │
│  │                    Storage Mounts                              │  │
│  │  /mnt/user/appdata/novarr/ ──► Container Volumes               │  │
│  │    ├── storage/                                                │  │
│  │    ├── .env                                                    │  │
│  │    └── docker-compose.yml                                      │  │
│  └───────────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

### Blue-Green Deployment Flow

```
┌──────────────────────────────────────────────────────────────────┐
│                    Zero-Downtime Update Flow                      │
└──────────────────────────────────────────────────────────────────┘

   CURRENT STATE            UPDATE PROCESS           NEW STATE
   ┌─────────────┐          ┌─────────────┐         ┌─────────────┐
   │  App BLUE   │ ────────►│  App GREEN  │────────►│  App GREEN  │
   │  (Active)   │  Build   │  (Starting) │ Switch  │  (Active)   │
   └─────────────┘   New    └─────────────┘ Traffic └─────────────┘
         │          Image          │                       │
         │                         │                       │
   ┌─────────────┐          ┌─────────────┐         ┌─────────────┐
   │   Nginx     │          │   Nginx     │         │   Nginx     │
   │  ──► BLUE   │          │  ──► GREEN  │         │  ──► GREEN  │
   └─────────────┘          └─────────────┘         └─────────────┘
```

### Network and Storage Considerations

**Network:**
- All containers communicate via Docker's bridge network
- Only Nginx exposes ports to the host (80/443)
- Internal services (MySQL, Redis) are not directly accessible

**Storage:**
- Data is persisted in `/mnt/user/appdata/novarr/`
- MySQL data uses a named Docker volume for performance
- Storage directory contains novels, EPUBs, and uploads

## Pre-Installation Checklist

### Unraid Version Requirements

- **Minimum**: Unraid 6.9.0
- **Recommended**: Unraid 6.12.0 or later

Check your version:
```bash
cat /etc/unraid-version
```

### Required Plugins

Install these from Community Applications:

1. **Docker Compose Manager** (Recommended)
   - Enables native docker-compose support
   - Search: "Docker Compose Manager" in Community Apps

2. **Community Applications** (Required)
   - Already installed if you can access App Store
   - Search: "Community Applications" if not installed

### Port Availability Check

Ensure these ports are not in use:

| Port | Service | Required |
|------|---------|----------|
| 80 | HTTP | Yes (or custom) |
| 443 | HTTPS | Optional |
| 8000 | App Direct | Optional (debugging) |

Check ports:
```bash
# List containers using ports
docker ps --format "table {{.Names}}\t{{.Ports}}"

# Check specific port
lsof -i :80
```

### Disk Space Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| Application | 2 GB | 5 GB |
| Database | 1 GB | 10 GB |
| Novel Storage | 5 GB | 50+ GB |
| **Total** | **10 GB** | **50+ GB** |

Check available space:
```bash
df -h /mnt/user/appdata/
```

## Installation Methods

### Method 1: Docker Compose Manager (Recommended)

This is the easiest method using the Docker Compose Manager plugin.

1. **Install Docker Compose Manager**
   - Go to Apps (Community Applications)
   - Search for "Docker Compose Manager"
   - Install the plugin

2. **Create Project Directory**
   ```bash
   mkdir -p /mnt/user/appdata/novarr
   cd /mnt/user/appdata/novarr
   ```

3. **Clone or Copy Project Files**
   ```bash
   # Option A: Clone repository
   git clone https://github.com/your-repo/novarr.git .

   # Option B: Upload files via Unraid share
   # Copy files to \\unraid\appdata\novarr\
   ```

4. **Configure via UI**
   - Navigate to Docker > Compose
   - Click "Add New Stack"
   - Name: `novarr`
   - Path: `/mnt/user/appdata/novarr`
   - Compose Up

### Method 2: Command Line via SSH

For advanced users who prefer SSH access.

1. **SSH into Unraid**
   ```bash
   ssh root@your-unraid-ip
   ```

2. **Create Directory Structure**
   ```bash
   mkdir -p /mnt/user/appdata/novarr
   cd /mnt/user/appdata/novarr
   ```

3. **Clone Repository**
   ```bash
   git clone https://github.com/your-repo/novarr.git .
   ```

4. **Configure Environment**
   ```bash
   cp .env.example .env
   nano .env  # Edit configuration
   ```

5. **Deploy**
   ```bash
   make deploy
   # Or: ./docker-deploy.sh
   ```

### Method 3: Unraid Templates (Future)

Community template support may be added in future releases.

## Initial Setup

### Creating the Appdata Directory Structure

```bash
# Create main directory
mkdir -p /mnt/user/appdata/novarr

# Create subdirectories
mkdir -p /mnt/user/appdata/novarr/storage/app/public
mkdir -p /mnt/user/appdata/novarr/storage/framework/{cache,sessions,views}
mkdir -p /mnt/user/appdata/novarr/storage/logs
mkdir -p /mnt/user/appdata/novarr/bootstrap/cache

# Set permissions
chmod -R 775 /mnt/user/appdata/novarr/storage
chmod -R 775 /mnt/user/appdata/novarr/bootstrap/cache
```

### Configuring the .env File

Create your environment configuration:

```bash
cd /mnt/user/appdata/novarr
cp .env.example .env
```

**Essential settings for Unraid:**

```env
# Application
APP_NAME=Novarr
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-unraid-ip

# Database (use strong passwords!)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=novarr
DB_USERNAME=novarr_user
DB_PASSWORD=your_secure_password_here
DB_ROOT_PASSWORD=your_root_password_here

# Cache & Queue
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning
```

### Setting Up Reverse Proxy

#### Option A: Nginx Proxy Manager (Recommended)

If using Nginx Proxy Manager on Unraid:

1. Add a new Proxy Host
2. **Domain**: `novarr.yourdomain.com`
3. **Forward Hostname/IP**: `novarr-nginx`
4. **Forward Port**: `80`
5. Enable SSL if desired (Let's Encrypt)

#### Option B: Swag/LSIO

Add to your Swag nginx config:

```nginx
server {
    listen 443 ssl;
    server_name novarr.yourdomain.com;

    location / {
        proxy_pass http://novarr-nginx:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

#### Option C: Direct Access

Access directly via IP and port:
```
http://your-unraid-ip:80
```

## Deployment Steps

### First-Time Deployment

1. **Navigate to Project Directory**
   ```bash
   cd /mnt/user/appdata/novarr
   ```

2. **Run Deployment Script**
   ```bash
   make deploy
   # Or: ./docker-deploy.sh
   ```

3. **Monitor Deployment Progress**
   The script will display progress including:
   - Docker image building
   - Container startup
   - Database migrations
   - Health checks

4. **Access the Application**
   - **Web Interface**: `http://your-unraid-ip/`
   - **Admin Panel**: `http://your-unraid-ip/admin`

5. **Create Admin User**
   ```bash
   make voyager-admin EMAIL="your@email.com"
   ```

### Monitoring Progress in Unraid Dashboard

1. Go to **Docker** tab in Unraid UI
2. You should see containers:
   - `novarr-app-blue` (or green)
   - `novarr-mysql`
   - `novarr-redis`
   - `novarr-nginx`
   - `novarr-scheduler-blue`

Click on any container to view logs or access shell.

## Migrating Existing Data

If you have an existing Novarr installation (local development), follow these steps to migrate your data.

### Export from Development

On your development machine:

```bash
cd /path/to/novarr
./scripts/migrate-from-dev.sh
```

This creates:
- `./migrate/dump.sql` - Database backup
- `./migrate/storage.tar.gz` - Storage files
- `./migrate/.env.backup` - Environment reference

### Transfer to Unraid

```bash
# Using scp
scp -r ./migrate/ root@your-unraid-ip:/mnt/user/appdata/novarr/

# Using rsync (for large files)
rsync -avz --progress ./migrate/ root@your-unraid-ip:/mnt/user/appdata/novarr/migrate/
```

### Import on Unraid

The migration files are automatically detected during deployment:

```bash
cd /mnt/user/appdata/novarr
./docker-deploy.sh --migrate
```

Or for automatic detection:
```bash
./docker-deploy.sh
```

### Unraid-Specific Considerations

**File Permissions:**
Unraid runs as root, but containers run as www-data. After migration:

```bash
# Inside container
docker exec novarr-app-blue chown -R www-data:www-data /var/www/html/storage
```

**Using Unraid File Browser:**
You can also upload migration files via Unraid's web file browser:
1. Go to Shares > appdata
2. Navigate to novarr/migrate/
3. Upload dump.sql and storage.tar.gz

For detailed migration instructions, see [DOCKER.md - Migrating from Development](DOCKER.md#migrating-from-development-to-production).

## Configuration

### Unraid-Specific Environment Variables

```env
# Unraid paths
APP_URL=http://your-unraid-ip

# Or with reverse proxy
APP_URL=https://novarr.yourdomain.com

# Timezone (match Unraid's timezone)
APP_TIMEZONE=America/New_York

# Logging (reduce for production)
LOG_LEVEL=warning
```

### Custom Domain with Reverse Proxy

1. **Update .env**
   ```env
   APP_URL=https://novarr.yourdomain.com
   ```

2. **Configure Nginx Proxy Manager**
   - Add proxy host
   - Enable SSL with Let's Encrypt
   - Forward to `novarr-nginx:80`

3. **Rebuild Config Cache**
   ```bash
   make cache-rebuild
   ```

### SSL Certificate Setup

#### With Nginx Proxy Manager

1. Add new Proxy Host
2. Go to SSL tab
3. Select "Request a new SSL Certificate"
4. Enable "Force SSL"
5. Check "HTTP/2 Support"

#### With Let's Encrypt (Direct)

```bash
# Install certbot on Unraid
docker run -it --rm \
  -v /mnt/user/appdata/novarr/certs:/etc/letsencrypt \
  certbot/certbot certonly --standalone \
  -d novarr.yourdomain.com
```

### Backup Configuration

Add to Unraid's Appdata Backup schedule or create a custom script:

```bash
#!/bin/bash
# /mnt/user/scripts/backup-novarr.sh

BACKUP_DIR="/mnt/user/backups/novarr/$(date +%Y%m%d)"
mkdir -p "$BACKUP_DIR"

cd /mnt/user/appdata/novarr
make backup

cp backups/*.sql "$BACKUP_DIR/"
tar czf "$BACKUP_DIR/storage.tar.gz" storage/
cp .env "$BACKUP_DIR/"

echo "Backup completed: $BACKUP_DIR"
```

## Updating Novarr

### Zero-Downtime Updates

Novarr uses blue-green deployment for seamless updates:

```bash
cd /mnt/user/appdata/novarr
make update
```

This will:
1. Pull latest code changes
2. Build new Docker image
3. Start new container (green if blue is active)
4. Run health checks
5. Switch traffic to new container
6. Stop old container

### Monitoring Update Progress

```bash
# Watch deployment status
make deployment-status

# View logs during update
make logs
```

### Rollback if Needed

If something goes wrong:

```bash
make rollback
```

This switches back to the previous container.

### Update Commands Reference

| Command | Description |
|---------|-------------|
| `make update` | Standard zero-downtime update |
| `make update-dry-run` | Preview changes |
| `make update-force` | Skip confirmations |
| `make rollback` | Revert to previous |
| `make deployment-status` | Show current state |

## Monitoring & Maintenance

### Viewing Logs via Unraid Docker UI

1. Go to **Docker** tab
2. Click on container icon (e.g., `novarr-app-blue`)
3. Select **Logs**

### Using Make Commands

```bash
# All logs
make logs

# Specific service logs
make logs-app
make logs-mysql
make logs-nginx
make logs-scheduler
make logs-worker
```

### Health Check Endpoints

```bash
# Check health
make health

# Or via curl
curl http://your-unraid-ip/api/health
curl http://your-unraid-ip/api/ping
```

### Disk Space Monitoring

```bash
# Check storage usage
du -sh /mnt/user/appdata/novarr/storage/*

# Check container volumes
docker system df
```

### Queue Monitoring

```bash
# Check queue health
make queue-health

# View failed jobs
make queue-failed

# Retry failed jobs
make queue-retry
```

## Troubleshooting

### Common Unraid Issues

#### Permission Errors

**Symptom**: "Permission denied" errors in logs

**Solution**:
```bash
# Fix permissions on host
chmod -R 775 /mnt/user/appdata/novarr/storage

# Fix inside container
docker exec novarr-app-blue chown -R www-data:www-data /var/www/html/storage
```

#### Port Conflicts

**Symptom**: Container won't start, port already in use

**Solution**:
```bash
# Find what's using port 80
docker ps | grep ":80->"
lsof -i :80

# Change port in docker-compose.yml
# nginx:
#   ports:
#     - "8080:80"  # Use 8080 instead
```

#### Container Keeps Restarting

**Symptom**: Container status shows "Restarting"

**Solution**:
```bash
# Check logs
docker logs novarr-app-blue --tail 100

# Check container health
docker inspect novarr-app-blue | grep -A 10 Health
```

#### Appdata Path Issues

**Symptom**: Data not persisting after restart

**Solution**:
Ensure docker-compose.yml uses absolute paths:
```yaml
volumes:
  - /mnt/user/appdata/novarr/storage:/var/www/html/storage
```

### Docker Network Issues on Unraid

**Symptom**: Containers can't communicate

**Solution**:
```bash
# Recreate network
docker network rm novarr_default
docker-compose up -d
```

### Resolving Conflicts with Other Containers

**Check for conflicts**:
```bash
# List all container networks
docker network ls

# Inspect network
docker network inspect novarr_default
```

### Accessing Container Shell for Debugging

```bash
# Interactive shell
docker exec -it novarr-app-blue sh

# Run commands directly
docker exec novarr-app-blue php artisan tinker
```

## Advanced Configuration

### Scaling Queue Workers

For heavy workloads, scale workers:

```bash
# Scale to 3 workers
docker-compose up -d --scale worker=3
```

Or modify docker-compose.yml:
```yaml
worker:
  deploy:
    replicas: 3
```

### Using Supervisor Variant

For resource-constrained systems, use the single-container supervisor variant:

```bash
make up-supervisor
```

This runs Octane, workers, and scheduler in one container.

### Custom Unraid User Scripts

Create automation scripts at `/mnt/user/scripts/`:

**Daily Backup Script**:
```bash
#!/bin/bash
# /mnt/user/scripts/novarr-daily-backup.sh

cd /mnt/user/appdata/novarr
make backup
```

Add to User Scripts plugin for scheduled execution.

**Health Check Script**:
```bash
#!/bin/bash
# /mnt/user/scripts/novarr-health-check.sh

HEALTH=$(curl -s http://localhost/api/health | grep -o '"status":"[^"]*"')

if [[ "$HEALTH" != *"healthy"* ]]; then
    echo "Novarr health check failed!"
    # Add notification command here
fi
```

### Integration with Unraid Notifications

Add to your scripts:
```bash
# Send Unraid notification
/usr/local/emhttp/webGui/scripts/notify \
    -s "Novarr" \
    -d "Deployment completed successfully" \
    -i "normal"
```

## Backup & Disaster Recovery

### Using Unraid's Appdata Backup Plugin

1. Install "Appdata Backup" from Community Applications
2. Configure backup schedule
3. Include `/mnt/user/appdata/novarr/` in backup set

### Manual Backup Procedures

```bash
cd /mnt/user/appdata/novarr

# Full backup
make backup

# Database only
docker exec novarr-mysql mysqldump -u root -p$DB_ROOT_PASSWORD novarr > backup.sql

# Storage only
tar czf storage-backup.tar.gz storage/
```

### Restoring from Backup

```bash
# Restore database
docker exec -i novarr-mysql mysql -u root -p$DB_ROOT_PASSWORD novarr < backup.sql

# Restore storage
tar xzf storage-backup.tar.gz -C .

# Fix permissions
docker exec novarr-app-blue chown -R www-data:www-data /var/www/html/storage
```

### Migrating to a New Unraid Server

1. **Backup on old server**:
   ```bash
   cd /mnt/user/appdata/novarr
   ./scripts/migrate-from-dev.sh
   ```

2. **Copy to new server**:
   ```bash
   rsync -avz /mnt/user/appdata/novarr/ root@new-server:/mnt/user/appdata/novarr/
   ```

3. **Deploy on new server**:
   ```bash
   cd /mnt/user/appdata/novarr
   ./docker-deploy.sh --migrate
   ```

## Performance Optimization

### Recommended Unraid Share Settings

For `/mnt/user/appdata/novarr/`:
- **Use cache**: Yes (Prefer)
- **Share floor**: 0
- **Split level**: Automatic

### Cache Drive Configuration

Store appdata on cache for better performance:
```
/mnt/cache/appdata/novarr/
```

Symlink if needed:
```bash
ln -s /mnt/cache/appdata/novarr /mnt/user/appdata/novarr
```

### Docker Image Storage Location

Store Docker images on cache:
- Settings > Docker > Docker storage location
- Use: `/mnt/cache/docker.img`

### Resource Limits and Allocation

In docker-compose.yml:
```yaml
services:
  app-blue:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          memory: 512M
```

Or via Unraid Docker UI:
- Edit container
- Set CPU and memory limits

## Security Best Practices

### Securing the Admin Panel

1. **Use strong passwords**
2. **Enable 2FA** (if supported)
3. **Restrict access** via reverse proxy authentication

### Using Authelia or Authentik for SSO

Configure your reverse proxy with Authelia:

```nginx
# In Nginx Proxy Manager advanced config
location / {
    auth_request /authelia;
    # ... rest of config
}
```

### Firewall Rules

Only expose necessary ports:
- 80/443 for web access
- Block 3306, 6379, 8000 from external access

### Regular Security Updates

```bash
# Update containers
make update

# Update base images
docker-compose pull
docker-compose up -d
```

---

## Quick Reference

### Essential Commands

| Task | Command |
|------|---------|
| Deploy | `make deploy` |
| Update | `make update` |
| Logs | `make logs` |
| Status | `make ps` |
| Shell | `make shell` |
| Backup | `make backup` |
| Health | `make health` |

### Important Paths

| Path | Purpose |
|------|---------|
| `/mnt/user/appdata/novarr/` | Application root |
| `/mnt/user/appdata/novarr/storage/` | Novels and uploads |
| `/mnt/user/appdata/novarr/.env` | Configuration |
| `/mnt/user/appdata/novarr/migrate/` | Migration files |

### Useful URLs

| URL | Purpose |
|-----|---------|
| `http://ip/` | Web interface |
| `http://ip/admin` | Admin panel |
| `http://ip/api/health` | Health check |
| `http://ip/api/ping` | Liveness probe |

---

For more detailed documentation, see:
- [DOCKER.md](DOCKER.md) - Complete Docker deployment guide
- [VOYAGER.md](VOYAGER.md) - Admin panel documentation
