<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/frontend-build-command-'.bin2hex(random_bytes(6));
    mkdir($this->root);
    $this->app->setBasePath($this->root);
    $this->app->useStoragePath($this->root.'/storage');
    $this->forgeRoot = getenv('FORGE_SITE_ROOT');
    putenv('FORGE_SITE_ROOT');
    config(['frontend-build-cache.cache_directory' => $this->root.'/cache']);
});

afterEach(function () {
    putenv($this->forgeRoot === false ? 'FORGE_SITE_ROOT' : 'FORGE_SITE_ROOT='.$this->forgeRoot);
    (new Filesystem)->deleteDirectory($this->root);
});

it('reports a miss without creating a cache directory', function () {
    expect(Artisan::call('deploy:build', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('No matching frontend build found; npm ci and npm run build would run.');
    expect(is_dir($this->root.'/cache'))->toBeFalse();
});

it('installs and builds on a miss, restores a hit, and replaces and prunes builds', function () {
    config([
        'frontend-build-cache.install_command' => [PHP_BINARY, '-r', 'file_put_contents("installs", "I", FILE_APPEND);'],
        'frontend-build-cache.build_command' => [PHP_BINARY, '-r', 'is_dir("public/build") || mkdir("public/build", 0777, true); file_put_contents("public/build/manifest.json", file_get_contents("installs"));'],
        'frontend-build-cache.keep' => 1,
    ]);
    expect(Artisan::call('deploy:build'))->toBe(0);
    $manifest = $this->root.'/public/build/manifest.json';
    expect(file_get_contents($manifest))->toBe('I');
    unlink($manifest);

    expect(Artisan::call('deploy:build', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Matching frontend build found; it would be restored.');
    expect(Artisan::call('deploy:build', ['--dry-run' => true, '--force' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Forced rebuild;');
    expect(file_exists($manifest))->toBeFalse();
    expect(Artisan::call('deploy:build'))->toBe(0);
    expect(Artisan::output())->toContain('Restored matching frontend build');
    expect(file_get_contents($manifest))->toBe('I');

    expect(Artisan::call('deploy:build', ['--force' => true]))->toBe(0);
    unlink($manifest);
    expect(Artisan::call('deploy:build'))->toBe(0);
    expect(file_get_contents($manifest))->toBe('II');
    $old = glob($this->root.'/cache/*');
    file_put_contents($this->root.'/composer.lock', 'changed');
    expect(Artisan::call('deploy:build'))->toBe(0);
    expect(file_get_contents($manifest))->toBe('III');
    expect(glob($this->root.'/cache/*'))->toHaveCount(1)->not->toBe($old);
});

it('restores the SSR bundle and installs runtime dependencies on a hit', function () {
    config([
        'frontend-build-cache.install_command' => [PHP_BINARY, '-r', 'mkdir("node_modules");'],
        'frontend-build-cache.build_command' => [PHP_BINARY, '-r', 'mkdir("public/build", 0777, true); mkdir("bootstrap/ssr", 0777, true); file_put_contents("public/build/manifest.json", "{}"); file_put_contents("bootstrap/ssr/ssr.js", "ssr");'],
    ]);
    expect(Artisan::call('deploy:build'))->toBe(1);
    expect(Artisan::output())->toContain('SSR output detected');
    expect(is_dir($this->root.'/cache'))->toBeFalse();

    $filesystem = new Filesystem;
    config(['frontend-build-cache.additional_outputs' => ['bootstrap/ssr']]);
    foreach (['Frontend build completed', 'Restored matching frontend build'] as $message) {
        foreach (['public', 'bootstrap', 'node_modules'] as $directory) {
            $filesystem->deleteDirectory($this->root.'/'.$directory);
        }
        expect(Artisan::call('deploy:build'))->toBe(0);
        expect(Artisan::output())->toContain($message);
        expect(file_get_contents($this->root.'/bootstrap/ssr/ssr.js'))->toBe('ssr');
        expect(is_dir($this->root.'/node_modules'))->toBeTrue();
    }
});

it('does not cache failed commands', function (string $key) {
    config([
        'frontend-build-cache.install_command' => [PHP_BINARY, '-r', 'exit(0);'],
        "frontend-build-cache.$key" => [PHP_BINARY, '-r', 'exit(7);'],
    ]);
    expect(Artisan::call('deploy:build'))->toBe(1);
    expect(Artisan::output())->toContain('failed with exit code 7');
    expect(is_dir($this->root.'/cache'))->toBeFalse();
})->with(['install_command', 'build_command']);

it('rejects invalid configuration before installing dependencies', function (string $key, mixed $value, string $message) {
    config(["frontend-build-cache.$key" => $value]);
    expect(Artisan::call('deploy:build'))->toBe(1);
    expect(Artisan::output())->toContain($message);
})->with([
    ['build_command', 'npm run build', 'must be an array of strings'],
    ['manifest', '', 'must be a non-empty string'],
    ['manifest', 'manifest.json', 'manifest must be inside'],
    ['additional_outputs', ['public'], 'must not overlap'],
]);

it('resolves the cache directory in option, config, Forge, storage order', function () {
    putenv('FORGE_SITE_ROOT='.$this->root);
    config(['frontend-build-cache.cache_directory' => 'configured']);
    expect(Artisan::call('deploy:build', ['--dry-run' => true, '--build-cache-dir' => 'override']))->toBe(0);
    expect(Artisan::output())->toContain('Build cache: '.$this->root.'/override');
    expect(Artisan::call('deploy:build', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Build cache: '.$this->root.'/configured');
    config(['frontend-build-cache.cache_directory' => null]);
    expect(Artisan::call('deploy:build', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Build cache: '.$this->root.'/.deploy-cache/frontend-builds');
    putenv('FORGE_SITE_ROOT');
    expect(Artisan::call('deploy:build', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Build cache: '.$this->root.'/storage/framework/cache/deploy-builds');
});
