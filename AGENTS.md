# AGENTS.md — Xboard-mini Project Guide

> This file is intended for AI coding agents. It describes the architecture, conventions, and workflows of the **xboard-mini** project. This is a fork of the Xboard proxy protocol management panel, specifically modified for zero-Redis, SQLite-first deployment on Northflank's free tier.

---

## 1. Project Overview

**Xboard-mini** is a web-based proxy protocol management panel forked from Xboard. It provides:

- User subscription management for proxy protocols (V2Ray, Shadowsocks, Trojan, VMess, Hysteria, etc.).
- A billing and commission system with support for multiple payment gateways.
- An admin dashboard for server/node management, user management, order processing, and statistical reporting.
- A themeable user frontend and a modern admin interface.

**Primary Language:** PHP 8.2+  
**Framework:** Laravel 12 (upgraded from Laravel 11)  
**Web Runtime:** Laravel Octane with Swoole  
**Database:** SQLite (default) or MySQL 5.7+  
**Cache / Queue / Session:** Database (SQLite) — Redis has been completely removed  

**Key Difference from Upstream Xboard:**  
This fork targets the Northflank free tier. It removes all Redis dependencies, replaces MySQL with SQLite as the default, and uses a single-service SupervisorD architecture to work around Northflank's limitation that persistent volumes cannot be shared across multiple services.

---

## 2. Technology Stack

| Layer | Technology |
|-------|-----------|
| Backend Framework | Laravel 12 (PHP 8.2+) |
| High-Performance Runtime | Laravel Octane (Swoole) |
| Frontend (Admin) | React + Shadcn UI + TailwindCSS *(prebuilt, shipped as compiled assets)* |
| Frontend (User) | Vue 3 + TypeScript + NaiveUI *(prebuilt, shipped as themes)* |
| Database | SQLite (default) or MySQL 5.7+ |
| Cache / Session / Queue | Database (SQLite tables: `cache`, `sessions`, `jobs`, `failed_jobs`) |
| Queue Worker | SupervisorD (`php artisan queue:work`) + Laravel scheduler fallback |
| Containerization | Docker + Docker Compose |
| CI / CD | GitHub Actions (Docker image build and push to GHCR) |
| Static Analysis | PHPStan (Larastan) at level 5 |

**Key Composer Packages:**

- `laravel/framework` — Core framework
- `laravel/octane` — Swoole-based high-performance server
- `laravel/sanctum` — API token authentication
- `doctrine/dbal` — Database abstraction
- `guzzlehttp/guzzle` — HTTP client
- `stripe/stripe-php` — Stripe payments
- `spatie/db-dumper` — Database backups
- `google/cloud-storage` — Google Cloud Storage backups
- `bacon/bacon-qr-code` — QR code generation
- `symfony/yaml` — YAML parsing for subscription templates
- `linfo/linfo` — System information
- `zoujingli/ip2region` — IP geolocation

**Removed from upstream:**
- `laravel/horizon` — Removed because it requires Redis.

---

## 3. Directory Structure

```
├── app/                    # Laravel application code
│   ├── Console/Commands/   # Artisan commands (see §6)
│   ├── Contracts/          # Interfaces
│   ├── Exceptions/         # Custom exceptions (ApiException)
│   ├── Helpers/            # Global helper functions (`admin_setting()`, etc.)
│   ├── Http/
│   │   ├── Controllers/    # V1 (legacy) and V2 (current) API controllers
│   │   ├── Middleware/     # Auth, Admin, Client, Server, Language, etc.
│   │   ├── Requests/       # Form request validation classes
│   │   ├── Resources/      # Eloquent API resources
│   │   └── Routes/         # Route definition classes for V1 and V2 APIs
│   ├── Jobs/               # Queue jobs (email, Telegram, traffic fetch, etc.)
│   ├── Models/             # Eloquent models (User, Server, Plan, Order, etc.)
│   ├── Observers/          # Model observers
│   ├── Protocols/          # Subscription protocol handlers (Clash, Surge, Sing-box, etc.)
│   ├── Providers/          # Service providers
│   ├── Scope/              # Eloquent query scopes
│   ├── Services/           # Business logic services
│   ├── Support/            # Support classes (ProtocolManager, Setting, AbstractProtocol)
│   ├── Traits/             # Reusable traits (HasPluginConfig, QueryOperators)
│   └── Utils/              # Utility classes (CacheKey, Dict, Helper)
├── bootstrap/              # Laravel bootstrap
├── config/                 # Laravel configuration files
├── database/
│   ├── factories/          # Model factories
│   ├── migrations/         # 31 migration files (including manually created cache table migration)
│   └── seeders/            # Database seeders
├── docs/                   # Deployment and migration documentation
├── plugins/                # Payment plugin directory (AlipayF2f, Epay, Stripe, etc.)
├── public/                 # Web root (index.php, theme assets, compiled JS/CSS)
├── resources/
│   ├── js/                 # Minimal Laravel JS entrypoints
│   ├── lang/               # JSON translation files (zh-CN, zh-TW, en-US)
│   ├── rules/              # Subscription rule templates (Clash, Sing-box, Surge, etc.)
│   ├── sass/               # SASS stylesheets
│   └── views/              # Blade templates (mail, admin, client subscribe)
├── routes/                 # Web and console route definitions
├── storage/                # Logs, cache, compiled views, uploaded themes
├── theme/                  # Frontend themes (Xboard, v2board)
└── .docker/                # Docker and Supervisor configuration
```

### Autoloaded Namespaces (PSR-4)

- `App\` → `app/`
- `Library\` → `library/` *(directory does not currently exist)*
- `Plugin\` → `plugins/`

### Global Helper File

`app/Helpers/Functions.php` is loaded via `composer.json` `files` autoload. It defines:

- `admin_setting($key, $default)` — read/write application settings from the `v2_setting` table.
- `admin_settings_batch(array $keys)` — batch read settings with caching.
- `source_base_url(string $path)` — get base URL from Referer or Host.

---

## 4. API Architecture

The application exposes two API versions:

| Version | Prefix | Purpose |
|---------|--------|---------|
| V1 | `/api/v1` | Legacy API for clients, servers, passport, users |
| V2 | `/api/v2` | Current API for admin, passport, users |

Routes are defined in `app/Http/Routes/V1/` and `app/Http/Routes/V2/` as classes with a `map(Registrar $router)` method. The `RouteServiceProvider` globs these files and registers them automatically.

**Route Files:**
- V1: `ClientRoute.php`, `GuestRoute.php`, `PassportRoute.php`, `ServerRoute.php`, `UserRoute.php`
- V2: `AdminRoute.php`, `PassportRoute.php`, `UserRoute.php`

**Middleware Groups:**

- `web` — Session, CSRF, cookies (used for the theme frontend).
- `api` — Stateless API requests.
- `admin` + `log` — Admin-only routes protected by Sanctum (`is_admin` check).
- `user` — Authenticated user routes.
- `client` — Client app routes (subscription clients).
- `server` — Server/node reporting routes.
- `staff` — Staff-level routes.

---

## 5. Key Subsystems

### 5.1 Models and Database

Core models live in `app/Models/`. The database uses `v2_` prefixed table names inherited from the original v2board project:

- `v2_user` — Users (`is_admin`, `uuid`, `token`, traffic limits, expiration).
- `v2_server` — Proxy server/node definitions (Shadowsocks, VMess, Trojan, Hysteria, etc.).
- `v2_plan` — Subscription plans.
- `v2_order` — User orders.
- `v2_payment` — Payment records.
- `v2_commission_log` — Affiliate commission records.
- `v2_coupon` — Discount coupons.
- `v2_giftcard_template / v2_giftcard_code / v2_giftcard_usage` — Gift card system.
- `v2_ticket / v2_ticket_message` — Support tickets.
- `v2_stat_user / v2_stat_server / v2_stat` — Traffic and server statistics.
- `v2_setting` — Key-value application settings.
- `v2_plugin` — Installed plugin registry.
- `v2_server_group / v2_server_route / v2_server_log` — Server organization and logs.
- `v2_invite_code / v2_knowledge / v2_notice` — Invites, knowledge base, notices.
- `v2_traffic_reset_log` — Traffic reset history.
- `cache`, `sessions`, `jobs`, `failed_jobs` — Laravel system tables (SQLite).

**Migrations:** 31 files in `database/migrations/`. Notably, `2025_08_08_000000_create_cache_table.php` was manually added (instead of using `php artisan cache:table`) to make initialization idempotent and reliable in containerized environments.

### 5.2 Services

Business logic is encapsulated in `app/Services/`:

- `AuthService`, `UserService` — Authentication and user management.
- `OrderService`, `PaymentService`, `CouponService` — Commerce logic.
- `PlanService`, `ServerService` — Plan and node management.
- `ThemeService` — Theme discovery, installation, and view path registration.
- `UpdateService` — Version tracking and update orchestration.
- `StatisticalService`, `TrafficResetService` — Reporting and traffic accounting.
- `MailService`, `TelegramService` — Notifications.
- `UserOnlineService` — Online user tracking and cleanup.
- `GiftCardService` — Gift card logic.
- `Plugin\PluginManager` — Plugin lifecycle management.

### 5.3 Protocols (Subscription Handlers)

`app/Protocols/` contains classes that generate subscription configs for various clients. Each class extends `App\Support\AbstractProtocol`:

- `Clash`, `ClashMeta`, `Shadowrocket`, `QuantumultX`, `Stash`, `Surge`, `Surfboard`, `Loon`
- `SingBox` — JSON-based Sing-box config
- `General` — Plain URL list
- `Shadowsocks` — Shadowsocks-specific format

Protocol classes are auto-discovered by `ProtocolManager` (registered via `ProtocolServiceProvider`).

Subscription rule templates are stored in `resources/rules/` (YAML for Clash, JSON for Sing-box, `.conf` for Surge/Surfboard).

### 5.4 Plugins

`plugins/` contains payment gateway plugins. Each plugin is a subdirectory with:

- `Plugin.php` — Must implement the plugin contract (typically extends `App\Services\Plugin\AbstractPlugin`).
- `config.json` — Plugin metadata and configuration schema.

Built-in plugins: `AlipayF2f`, `Btcpay`, `CoinPayments`, `Coinbase`, `Epay`, `Mgate`, `Smogate`, `Telegram`.

Plugins are loaded by `PluginManager` and registered via `PluginServiceProvider`. The admin panel allows installing, enabling, and configuring plugins dynamically.

### 5.5 Themes

Themes live in `theme/` and `storage/theme/` (user-uploaded). A theme is a directory containing:

- `config.json` — Theme metadata.
- `dashboard.blade.php` — Main view template.
- `assets/` — Static assets (JS, CSS, images).

System themes: `Xboard` (modern Vue3/TS user frontend), `v2board` (legacy).

`ThemeService` registers view namespaces and handles theme switching, installation from ZIP, and asset publishing to `public/theme/`.

### 5.6 Jobs

Queue jobs in `app/Jobs/`:

- `OrderHandleJob` — Process paid orders.
- `SendEmailJob`, `SendTelegramJob` — Async notifications.
- `StatUserJob`, `StatServerJob` — Periodic statistics collection.
- `TrafficFetchJob` — Collect traffic usage from nodes.
- `SyncUserOnlineStatusJob` — Online user tracking.

---

## 6. Artisan Commands

Custom commands in `app/Console/Commands/`:

| Command | Description |
|---------|-------------|
| `xboard:update` | Run migrations, restore plugins, update version cache, refresh theme |
| `xboard:create-admin` | Create admin user from `ADMIN_EMAIL` / `ADMIN_PASSWORD` env vars |
| `xboard:statistics` | Daily statistics aggregation |
| `xboard:rollback` | Rollback helper |
| `check:order` | Process pending orders (every minute) |
| `check:commission` | Process commission payouts (every minute) |
| `check:ticket` | Ticket automation (every minute) |
| `check:server` | Server health checks |
| `reset:traffic` | Reset monthly traffic for users (every minute) |
| `reset:log` | Clean old logs (daily) |
| `reset:password` | Reset a user's password |
| `reset:user` | Reset user data |
| `send:remindMail` | Send expiration/traffic reminder emails (daily at 11:30) |
| `backup:database` | Backup database to Google Cloud Storage |
| `cleanup:database` | Clean expired stats and logs (weekly, recommended for Northflank free tier) |
| `migrate:v2b` | Migrate data from v2board |
| `export:v2log` | Export v2board logs |

**Known Issue:** `XboardUpdate` references `XboardInstall::restoreProtectedPlugins($this)`, but the `XboardInstall` class does not exist in this repository. This is a residual reference from upstream that may cause `xboard:update` to fail if not addressed.

**Scheduling:** Defined in `app/Console/Kernel.php`. Uses `onOneServer()` for cluster safety. Plugin schedules are registered dynamically via `PluginManager::registerPluginSchedules()`.

**Northflank-specific schedule additions:**
- `cleanup:database --type=stats,logs --days=90` runs weekly to control SQLite storage size.
- `queue:work --max-time=55 --stop-when-empty --max-jobs=50 --sleep=3` runs every minute as a fallback queue worker for environments without persistent process support.
- `cleanup:expired-online-status` runs every minute to clean stale online user records.

---

## 7. Build and Run Commands

### Local Development (without Docker)

```bash
# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate --force

# Create admin user
php artisan xboard:create-admin

# Start Octane development server
php artisan octane:start --port=8080 --host=0.0.0.0

# In another terminal, start the queue worker
php artisan queue:work --tries=3
```

### Docker Compose (Recommended for local testing)

```bash
# Quick start (from README)
docker compose up -d
```

The Docker image is based on `phpswoole/swoole:php8.2-alpine` and runs:

- **Octane** on port `8080`
- **Queue worker** via SupervisorD

### Northflank Deployment

This project is specifically designed for Northflank's free tier:

1. Use `northflank.yml` to define a single `web` service.
2. Attach a persistent storage volume named `xboard-data`.
3. The `entrypoint.sh` script handles all initialization on first boot:
   - Validates `APP_KEY` is set.
   - Creates SQLite database file if using SQLite.
   - Runs `php artisan migrate --force`.
   - Sets admin secure path if `ADMIN_SECURE_PATH` is provided.
   - Creates admin user if `ADMIN_EMAIL` and `ADMIN_PASSWORD` are provided.
   - Starts SupervisorD to manage Octane and the queue worker.

**Critical Northflank constraints:**
- Only one persistent volume can be attached per service.
- Therefore, web and queue worker must run in the same container (handled by SupervisorD).
- The volume should be mounted at `/www/storage` to persist SQLite data, logs, and cached files.

### Static Analysis

```bash
vendor/bin/phpstan analyse --configuration=phpstan.neon
```

- Configuration: `phpstan.neon`
- Level: 5
- Scope: `app/` directory only

---

## 8. Code Style Guidelines

- **Indentation:** 4 spaces (`.editorconfig`)
- **Line endings:** LF
- **Charset:** UTF-8
- **Trailing whitespace:** trimmed (except `.md` files)
- **YAML:** 2 spaces

**PHP Conventions:**

- Follow Laravel coding style (PSR-12 aligned).
- Use typed properties and return types where possible (PHP 8.2).
- Use `declare(strict_types=1)` sparingly; the existing codebase does not uniformly use it.
- Comments are often in Chinese. When adding new code, match the language of surrounding comments.
- Namespaces: `App\` for application code, `Plugin\` for plugins.

**Naming:**

- Controllers: `PascalCaseController`
- Services: `PascalCaseService`
- Models: `PascalCase` (singular)
- Database tables: `v2_snake_case`
- Route classes: `PascalCaseRoute` with a `map(Registrar $router)` method
- Form requests: `PascalCase` under `App\Http\Requests\{Admin,Passport,Staff,User}`

---

## 9. Testing

**There is currently no test suite in this project.** The `tests/` directory does not exist, and there is no `phpunit.xml` or `phpunit.xml.dist` configuration file. `phpunit` is listed as a dev dependency for Larastan compatibility only.

If you add tests:

- Create a `tests/` directory at the project root.
- Follow Laravel's `Feature/` and `Unit/` structure.
- Use `php artisan test` or `vendor/bin/phpunit` to run them.

---

## 10. Security Considerations

- **Authentication:** Uses Laravel Sanctum for API token authentication. The `Admin` middleware checks `is_admin` on the user model.
- **Admin Path:** The admin panel URL is randomized by default using `hash('crc32b', config('app.key'))`. It can be overridden via `ADMIN_SECURE_PATH` env var or the `secure_path` setting.
- **Safe Mode:** `admin_setting('safe_mode_enable')` restricts frontend access to the configured `app_url` host.
- **Force HTTPS:** Can be enabled via `force_https` setting.
- **APP_KEY:** Required. The Docker entrypoint aborts if `APP_KEY` is not set.
- **CSRF:** Web routes (theme frontend) use CSRF protection.
- **SQL Injection:** Use Eloquent or Query Builder; avoid raw SQL without parameterization.
- **File Uploads:** Theme ZIP uploads are validated and extracted. Ensure `storage/` permissions are restricted.

---

## 11. Deployment Processes

### Docker Image Build (GitHub Actions)

- Triggered on push to `master` or `new-dev`.
- Builds multi-platform images (`linux/amd64`, `linux/arm64`) for GHCR.
- Tags: `new`, `latest`, branch name, SHA, and version.
- Workflow file: `.github/workflows/docker-publish.yml`

### Manual Update (on server with Git)

Use the provided `update.sh` script:

```bash
./update.sh
```

This fetches the latest `master`, resets hard, runs `composer update`, and executes `php artisan xboard:update`.

### Environment Variables (Critical)

| Variable | Purpose |
|----------|---------|
| `APP_KEY` | Laravel encryption key (32 chars, base64) |
| `APP_URL` | Application base URL |
| `DB_CONNECTION` | `sqlite` (default for this fork) or `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL credentials (if using MySQL) |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Auto-create admin on first Docker boot |
| `ADMIN_SECURE_PATH` | Custom admin panel path |
| `QUEUE_CONNECTION` | `database` (default) |
| `CACHE_DRIVER` | `database` (default) |
| `SESSION_DRIVER` | `database` (default) |
| `MAIL_*` | SMTP / Mailgun configuration |
| `ENABLE_AUTO_BACKUP_AND_UPDATE` | Enable Google Cloud Storage backups |
| `GOOGLE_CLOUD_*` | GCS backup credentials |

---

## 12. Important Conventions for Agents

1. **Always use `admin_setting()` for configuration reads.** Do not read `env()` directly in business logic (except in config files or service providers). Settings are cached; `admin_setting()` handles caching and fallbacks.

2. **Database tables use `v2_` prefix.** Migrations should preserve this convention for consistency.

3. **Do not delete built-in plugins.** The `xboard:update` command attempts to restore "protected" plugins (though the referenced `XboardInstall` class is currently missing).

4. **Theme assets are copied to `public/theme/`.** When modifying themes, remember that `ThemeService` copies the theme directory on first load or when switched.

5. **Protocol classes are auto-discovered.** Adding a new subscription format only requires creating a class in `app/Protocols/` that extends `AbstractProtocol`.

6. **Route classes are auto-discovered.** Adding a new route file in `app/Http/Routes/V1/` or `V2/` automatically registers it under the corresponding API prefix.

7. **Queue jobs should be idempotent.** Many jobs run on a schedule; design them to be safe if retried.

8. **When modifying the admin path, a restart is required.** Octane caches the route definitions in memory.

9. **SQLite is the default and preferred database for this fork.** When making changes, ensure they work with SQLite. Avoid MySQL-specific raw SQL.

10. **Keep storage under control.** The Northflank free tier has limited storage. The `cleanup:database` command is scheduled weekly to purge old stats and logs. Consider this when adding new logging or statistics tables.

---

## 13. External Documentation

- Deployment guides: `docs/en/installation/`
- Migration guides from v2board: `docs/en/migration/`
- Device limit and performance notes: `docs/en/development/`

---

*Last updated: 2026-05-16*
