# Voyager Admin Panel Configuration

This document details the Voyager admin panel configuration for Novarr.

## Installation

### Fresh Installation

After deploying Novarr, run the following commands inside the app container:

```bash
# Enter the app container
docker-compose exec app sh

# Install Voyager (creates tables and seeds default data)
php artisan voyager:install

# Create an admin user
php artisan voyager:admin admin@example.com --create
# Follow the prompts to set name and password

# Publish Voyager assets
php artisan vendor:publish --provider="TCG\Voyager\VoyagerServiceProvider" --force

# Create storage symlink
php artisan storage:link

# Seed the menu items
php artisan db:seed --class=MenuItemsTableSeeder
```

### Upgrading Voyager

When updating Voyager to a new version:

```bash
composer update tcg/voyager
php artisan voyager:install --with-dummy
php artisan vendor:publish --provider="TCG\Voyager\VoyagerServiceProvider" --force
```

## Menu Structure

The admin menu is organized as follows:

```
Admin Menu
├── Dashboard (icon: voyager-boat, route: voyager.dashboard)
├── Novels (icon: voyager-book, route: voyager.novels.index)
└── Tools (icon: voyager-tools)
    ├── Commands (icon: voyager-terminal, route: voyager.commands.index)
    ├── Logs (icon: voyager-file-text, route: voyager.logs.index)
    ├── Users (icon: voyager-person, route: voyager.users.index)
    ├── Roles (icon: voyager-lock, route: voyager.roles.index)
    ├── Media (icon: voyager-images, route: voyager.media.index)
    ├── Menu Builder (icon: voyager-list, route: voyager.menus.index)
    ├── Database (icon: voyager-data, route: voyager.database.index)
    ├── BREAD (icon: voyager-bread, route: voyager.bread.index)
    └── Settings (icon: voyager-settings, route: voyager.settings.index)
```

To update the menu structure, run:
```bash
php artisan db:seed --class=MenuItemsTableSeeder
```

Or manually update menu items via Voyager Menu Builder at `/admin/menus`.

## BREAD Configuration

### Novel Model BREAD

The Novel model BREAD is automatically available once Voyager is installed. To configure it:

1. Navigate to `/admin/bread`
2. Click "Add BREAD to this table" for the `novels` table
3. Configure the following fields:

| Field | Type | Browse | Read | Edit | Add | Details |
|-------|------|--------|------|------|-----|---------|
| id | Hidden | Yes | Yes | No | No | |
| name | Text | Yes | Yes | Yes | Yes | Required |
| slug | Text | Yes | Yes | Yes | Yes | Slugify from name |
| author | Text | Yes | Yes | Yes | Yes | |
| description | Rich Text Box | No | Yes | Yes | Yes | |
| cover | Image | Yes | Yes | Yes | Yes | |
| translator_url | Text | No | Yes | Yes | Yes | |
| status | Checkbox | Yes | Yes | Yes | Yes | 0=Active, 1=Completed |
| group_id | Relationship | Yes | Yes | Yes | Yes | belongsTo Group |
| language_id | Relationship | Yes | Yes | Yes | Yes | belongsTo Language |
| external_url | Text | No | Yes | Yes | Yes | |
| no_of_chapters | Number | Yes | Yes | Yes | Yes | |
| created_at | Timestamp | Yes | Yes | No | No | |
| updated_at | Timestamp | No | Yes | No | No | |

**Relationships:**
- Group: belongsTo, model: `App\Group`, foreign key: `group_id`
- Language: belongsTo, model: `App\Language`, foreign key: `language_id`
- Chapters: hasMany, model: `App\NovelChapter` (for count display)

### NovelChapter Model BREAD

Configure the `novel_chapters` table:

| Field | Type | Browse | Read | Edit | Add | Details |
|-------|------|--------|------|------|-----|---------|
| id | Hidden | Yes | Yes | No | No | |
| novel_id | Relationship | Yes | Yes | Yes | Yes | belongsTo Novel |
| chapter | Number | Yes | Yes | Yes | Yes | Required, decimal |
| label | Text | Yes | Yes | Yes | Yes | Required |
| description | Rich Text Box | No | Yes | Yes | Yes | Chapter content |
| url | Text | No | Yes | Yes | Yes | Source URL |
| book | Number | Yes | Yes | Yes | Yes | Default: 0 |
| unique_id | Text | No | Yes | Yes | Yes | |
| status | Checkbox | Yes | Yes | Yes | Yes | 0=Pending, 1=Downloaded |
| created_at | Timestamp | Yes | Yes | No | No | |

**Default Order:** novel_id ASC, book ASC, chapter ASC

### Group Model BREAD

| Field | Type | Browse | Read | Edit | Add |
|-------|------|--------|------|------|-----|
| id | Hidden | Yes | Yes | No | No |
| name | Text | Yes | Yes | Yes | Yes |
| slug | Text | Yes | Yes | Yes | Yes |
| url | Text | No | Yes | Yes | Yes |
| rss | Text | No | Yes | Yes | Yes |
| created_at | Timestamp | Yes | Yes | No | No |

### Language Model BREAD

| Field | Type | Browse | Read | Edit | Add |
|-------|------|--------|------|------|-----|
| id | Hidden | Yes | Yes | No | No |
| name | Text | Yes | Yes | Yes | Yes |
| created_at | Timestamp | Yes | Yes | No | No |

## Command Execution

### Available Commands

The command execution interface is available at `/admin/commands`. It provides a web UI for running:

| Command | Parameters | Description |
|---------|------------|-------------|
| `novel:toc` | novel_id (optional) | Scrape table of contents |
| `novel:chapter` | novel_id (optional) | Download chapter content |
| `novel:epub` | novel_id (optional) | Generate ePub files |
| `novel:metadata` | none | Update all novel metadata |
| `novel:normalize_labels` | novel_id (optional), dry_run | Normalize chapter labels |
| `novel:calculate_chapter` | none | Calculate chapter counts |
| `novel:info` | none | Show novel information |
| `novel:chapter_cleanser` | novel_id (optional) | Remove unwanted tags from chapters |
| `novel:chaptercleaner` | novel_id (optional) | Clean chapters with insufficient content |
| `novel:create` | name, url | Create a new novel from URL |
| `queue:health-check` | none | Check queue system health |

### Execution Modes

1. **Synchronous**: Command runs immediately and output is displayed
2. **Asynchronous (Background)**: Command is queued and runs in worker container

### Command Categories

Commands are organized into three categories:

- **Scraping**: Commands for fetching data from external sources (TOC, Chapter download)
- **Generation**: Commands for creating content (ePub, Novel creation)
- **Maintenance**: Commands for system maintenance and data cleanup

## Log Viewer

### Accessing Logs

Navigate to `/admin/logs` to view application logs.

### Features

- **List View**: Shows all log files in `storage/logs/` with file size and modification date
- **Log Viewing**: View log content with syntax highlighting and color-coded log levels
- **Filtering**: Filter logs by level (emergency, alert, critical, error, warning, notice, info, debug)
- **Search**: Search within log files for specific content
- **Real-time Tail**: Auto-refresh to see new log entries (every 5 seconds)
- **Download**: Download log files for offline analysis
- **Delete**: Remove old log files (with confirmation)

### Log Level Colors

| Level | Color | Description |
|-------|-------|-------------|
| Emergency | Red | System is unusable |
| Alert | Red | Immediate action required |
| Critical | Red | Critical conditions |
| Error | Red | Error conditions |
| Warning | Yellow | Warning conditions |
| Notice | Blue | Normal but significant |
| Info | Cyan | Informational messages |
| Debug | Gray | Debug-level messages |

### Log Rotation

Laravel's default log configuration rotates logs daily. Older logs can be safely deleted through the log viewer interface.

## Custom Styling

### Inline Scrolling

Custom CSS (`public/css/voyager-custom.css`) provides container-level scrolling for:

- Table containers (max-height: 60vh)
- Log content viewer (max-height: 75vh)
- Command output (max-height: 50vh)
- Chapter lists (max-height: 65vh)
- Chapter content (max-height: 70vh)

This prevents full-page scrolling and keeps content organized within panels.

### Custom CSS Classes

| Class | Purpose |
|-------|---------|
| `.voyager-table-scroll` | Enable inline scrolling for tables |
| `.log-content-container` | Styled log content area |
| `.command-output` | Styled command output area |
| `.chapters-list` | Chapter list container |
| `.chapter-content` | Chapter reading container |
| `.command-cards` | Grid layout for command cards |
| `.novel-stats` | Statistics display badges |

## User Roles & Permissions

### Default Roles

1. **Admin**: Full access to all features
2. **User**: Limited access (can be customized)

### Custom Permissions

Add custom permissions for command execution:

1. Navigate to `/admin/roles`
2. Edit role permissions
3. Enable/disable access to:
   - `browse_admin` - Access admin panel
   - `browse_novels` - View novels
   - `edit_novels` - Edit novels
   - `add_novels` - Add novels
   - `delete_novels` - Delete novels
   - Similar for other models

## Settings Configuration

Configure Voyager settings at `/admin/settings`:

### Site Settings

| Key | Value | Description |
|-----|-------|-------------|
| site.title | Novarr | Site title |
| admin.title | Novarr Admin | Admin panel title |
| admin.description | Novel Manager | Admin description |
| admin.loader | | Loading spinner URL |
| admin.icon_image | | Admin icon URL |
| admin.google_analytics_client_id | | GA tracking ID |

### Storage Settings

Images are stored in `storage/app/public` and accessed via the storage symlink:

```
public/storage -> storage/app/public
```

Ensure the symlink exists:
```bash
php artisan storage:link
```

## Customization

### Custom Views

Override Voyager views by placing them in:
```
resources/views/voyager/
```

Available custom views:
- `voyager/commands/index.blade.php` - Command list view
- `voyager/commands/form.blade.php` - Command execution form
- `voyager/logs/index.blade.php` - Log file list
- `voyager/logs/show.blade.php` - Log content viewer
- `voyager/novels/browse.blade.php` - Novel list with progress
- `voyager/novels/read.blade.php` - Novel detail with chapters
- `voyager/novel-chapters/browse.blade.php` - Chapter list
- `voyager/novel-chapters/read.blade.php` - Chapter content viewer

### Custom CSS/JS

Add custom styles in `public/css/voyager-custom.css` (already configured in `config/voyager.php`).

### Extending Controllers

Create custom controllers that extend Voyager's:

```php
namespace App\Http\Controllers\Voyager;

use TCG\Voyager\Http\Controllers\VoyagerBaseController;

class CustomNovelController extends VoyagerBaseController
{
    // Override methods as needed
}
```

## Troubleshooting

### Assets Not Loading

```bash
# Clear browser cache and republish assets
php artisan vendor:publish --provider="TCG\Voyager\VoyagerServiceProvider" --force
php artisan config:clear
php artisan cache:clear
```

### 404 on Admin Routes

Ensure the routes are properly registered:
```bash
php artisan route:list | grep admin
```

### Database Migration Errors

```bash
# Reset Voyager tables
php artisan migrate:refresh --path=vendor/tcg/voyager/migrations

# Or reinstall
php artisan voyager:install
```

### Image Upload Issues

1. Check storage permissions:
   ```bash
   chmod -R 775 storage
   ```

2. Verify GD extension is installed:
   ```bash
   php -m | grep gd
   ```

3. Check disk configuration in `config/filesystems.php`

### Session/CSRF Issues

If getting 419 errors:
1. Ensure `SESSION_DRIVER` is set correctly
2. Clear session cache:
   ```bash
   php artisan cache:clear
   php artisan config:clear
   ```

### Log Viewer Not Working

1. Ensure `storage/logs` directory exists and is readable
2. Check file permissions:
   ```bash
   chmod -R 755 storage/logs
   ```
3. Verify the `admin.user` middleware is properly configured

## Docker-Specific Notes

### Volume Mounts

Ensure these volumes are mounted for persistence:
- `./storage:/var/www/html/storage` - Uploaded files and logs
- `./public/vendor:/var/www/html/public/vendor` - Voyager assets

### Building with Assets

The Dockerfile includes:
```dockerfile
RUN php artisan vendor:publish --provider="TCG\Voyager\VoyagerServiceProvider" --force || true
```

### Nginx Configuration

Ensure Nginx serves static Voyager assets from `/vendor/tcg/voyager/`:
```nginx
location /vendor/ {
    alias /var/www/html/public/vendor/;
    try_files $uri $uri/ =404;
}
```

### Log Volume for Persistence

Add to `docker-compose.yml` to persist logs:
```yaml
volumes:
  - ./storage/logs:/var/www/html/storage/logs
```
