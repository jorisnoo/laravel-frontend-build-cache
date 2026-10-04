# Laravel Frontend Build Cache

Reuse fingerprinted Vite builds across Laravel and Statamic deployments. On a cache hit the
package restores `public/build`; on a miss it runs `npm ci` and `npm run build`, verifies the Vite
manifest, and stores the result for later deployments.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Installation

```bash
composer require jorisnoo/laravel-frontend-build-cache
```

Laravel discovers the service provider automatically.

## Usage

Replace the npm install/build lines in the deployment script with:

```bash
php artisan deploy:build
```

The command fingerprints package and npm configuration files, common Vite/Tailwind/PostCSS
configuration, Vite environment files, and everything in `resources/`. Symlinked files and
directories are followed and their contents are included.

On Forge, cached builds live at
`$FORGE_SITE_ROOT/.deploy-cache/frontend-builds`, outside individual releases. Elsewhere they use
`storage/framework/cache/deploy-builds`. The five newest builds are retained by default.

Available options:

```bash
php artisan deploy:build --dry-run
php artisan deploy:build --force
php artisan deploy:build --build-cache-dir=/persistent/path
```

## Configuration

Publish the configuration when a project uses different inputs, output paths, package manager,
or retention:

```bash
php artisan vendor:publish --tag=frontend-build-cache-config
```

The published `config/frontend-build-cache.php` controls:

- exact input paths and glob patterns;
- the build output and manifest paths;
- an optional persistent cache directory;
- install and build commands;
- the number of cached builds retained.

## Deployment example

```bash
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
$FORGE_PHP artisan deploy:build
$FORGE_PHP artisan optimize
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

## Maintainer releases

See [RELEASING.md](RELEASING.md) for versioning, changelog entries and the release command.
