# Laravel Frontend Build Cache

Reuse fingerprinted Vite builds across Laravel and Statamic deployments. On a cache hit the
package restores `public/build`; on a miss it runs `npm ci` and `npm run build`, verifies the Vite
manifest, and stores the result for later deployments.

## Requirements

- PHP 8.3+
- Laravel 12 or 13. Laravel 11 is not supported.

## Installation

Install the stable GitHub release through Composer's VCS support:

```bash
composer config repositories.frontend-build-cache vcs https://github.com/jorisnoo/laravel-frontend-build-cache
composer require jorisnoo/laravel-frontend-build-cache:^0.1
```

Laravel discovers the service provider automatically.

## Usage

Replace the npm install/build lines in the deployment script with:

```bash
php artisan deploy:build
```

The fingerprint includes the resolved install/build commands, output and manifest paths, npm
and Composer lockfiles, package/config files, Vite environment files, and files under
`app/`, `content/`, `lang/`, `resources/` and `routes/`. Missing inputs are skipped and symlinked
inputs are followed. Add every other path scanned by Tailwind `@source` or imported by Vite
to `inputs`/`input_patterns`. For example, list local path repositories or patched vendor
sources whose changes are not represented by `composer.lock`. If upgrading a published config,
merge the new default inputs into it.

On Forge, cached builds live at
`$FORGE_SITE_ROOT/.deploy-cache/frontend-builds`, outside individual releases. Elsewhere they use
`storage/framework/cache/deploy-builds`. The five newest builds are retained by default;
the just-stored build is always kept, even with `keep` set to zero or null.

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

### SSR and other outputs

Set `build_command` to your combined client/SSR build command and `additional_outputs` to
`['bootstrap/ssr']` (or your SSR output path). Keep `output_directory` set to the client
build containing `manifest`. The command caches all listed directories together, and fails
if one is missing or if it finds an unlisted `bootstrap/ssr` bundle. Use separate output
directories that do not contain each other or the cache.

With additional outputs configured, `install_command` runs on cache hits too: SSR servers
need the current release's Node dependencies. `node_modules` is not a portable build output
and should not be listed. With only the client output, a hit skips both Node commands.

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
