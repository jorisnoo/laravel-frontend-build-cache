# Changelog

All notable changes to `laravel-frontend-build-cache` will be documented in this file.

## [0.1.0] - 2026-11-19

### Added

- First tagged release of `php artisan deploy:build`, with persistent Vite builds and dry-run and forced rebuild modes.
- Configure `additional_outputs` to cache SSR bundles alongside the client manifest. Runtime dependencies are installed even on a cache hit when additional outputs are configured.

### Changed

- Requires PHP 8.3+ and Laravel 12 or 13; users of the development branch on Laravel 11 must upgrade Laravel first.
- Cache keys now include commands, output locations, the published config, Composer lockfile, and common Laravel, Statamic and Wayfinder inputs. Existing entries are not reused. Merge the new inputs into any previously published config.

### Fixed

- A forced dry run accurately reports a rebuild; a missing cache entry no longer counts as a successful restore.
- Pruning keeps the just-built entry even with a zero retention setting and removes abandoned staging directories older than one hour.
