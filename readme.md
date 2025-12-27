<p align="center"><img src="https://laravel.com/assets/img/components/logo-laravel.svg"></p>

<p align="center">
<a href="https://travis-ci.org/laravel/framework"><img src="https://travis-ci.org/laravel/framework.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://poser.pugx.org/laravel/framework/d/total.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://poser.pugx.org/laravel/framework/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://poser.pugx.org/laravel/framework/license.svg" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel attempts to take the pain out of development by easing common tasks used in the majority of web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, yet powerful, providing tools needed for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of any modern web application framework, making it a breeze to get started learning the framework.

If you're not in the mood to read, [Laracasts](https://laracasts.com) contains over 1100 video tutorials on a range of topics including Laravel, modern PHP, unit testing, JavaScript, and more. Boost the skill level of yourself and your entire team by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for helping fund on-going Laravel development. If you are interested in becoming a sponsor, please visit the Laravel [Patreon page](https://patreon.com/taylorotwell):

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[British Software Development](https://www.britishsoftware.co)**
- [Fragrantica](https://www.fragrantica.com)
- [SOFTonSOFA](https://softonsofa.com/)
- [User10](https://user10.com)
- [Soumettre.fr](https://soumettre.fr/)
- [CodeBrisk](https://codebrisk.com)
- [1Forge](https://1forge.com)
- [TECPRESSO](https://tecpresso.co.jp/)
- [Pulse Storm](http://www.pulsestorm.net/)
- [Runtime Converter](http://runtimeconverter.com/)
- [WebL'Agence](https://weblagence.com/)

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## Novarr - Web Novel Management

Novarr is a web novel management application built on Laravel that allows you to:
- Scrape and download web novels from various sources
- Organize novels with metadata and cover images
- Generate ePub files for offline reading
- Manage your library through a web-based admin panel (Voyager)

## Docker Deployment

For detailed Docker deployment instructions, see [DOCKER.md](DOCKER.md).

### Quick Start

```bash
# Clone the repository
git clone https://github.com/your-repo/novarr.git
cd novarr

# Configure environment
cp .env.example .env
# Edit .env with your database credentials

# Deploy
make deploy
```

## Deploying to Unraid

For detailed instructions on deploying Novarr to an Unraid server, see [UNRAID_DEPLOYMENT.md](UNRAID_DEPLOYMENT.md).

### Quick Start for Unraid

1. SSH into your Unraid server
2. Clone this repository to `/mnt/user/appdata/novarr/`
3. Configure `.env` with your settings
4. Run `make deploy`
5. Access the admin panel at `http://your-unraid-ip/admin`

## Migrating from Development

To migrate an existing development database and storage to production:

1. **Export from development:**
   ```bash
   make migrate-export
   ```

2. **Copy to production server:**
   ```bash
   scp -r ./migrate/ root@your-server:/mnt/user/appdata/novarr/
   ```

3. **Import on production:**
   ```bash
   ./docker-deploy.sh --migrate
   ```

See [DOCKER.md - Migrating from Development](DOCKER.md#migrating-from-development-to-production) for detailed instructions.

## Available Commands

| Command | Description |
|---------|-------------|
| `make deploy` | Initial deployment |
| `make update` | Zero-downtime update |
| `make logs` | View all logs |
| `make shell` | Open app container shell |
| `make migrate-export` | Export data for migration |
| `make backup` | Backup database and storage |
| `make health` | Check health endpoints |

Run `make help` for a complete list of available commands.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
