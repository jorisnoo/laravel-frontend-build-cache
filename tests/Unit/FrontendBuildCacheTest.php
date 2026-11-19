<?php

namespace Noo\LaravelFrontendBuildCache\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Noo\LaravelFrontendBuildCache\FrontendBuildCache;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FrontendBuildCacheTest extends TestCase
{
    private string $root;

    private string $cacheDirectory;

    private string $sharedDirectory;

    #[Before]
    protected function createFixtures(): void
    {
        $this->root = sys_get_temp_dir().'/laravel-build-'.bin2hex(random_bytes(6));
        $this->cacheDirectory = sys_get_temp_dir().'/laravel-build-cache-'.bin2hex(random_bytes(6));
        $this->sharedDirectory = sys_get_temp_dir().'/laravel-build-shared-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/resources/js', 0777, true);
        mkdir($this->root.'/resources/views', 0777, true);
        file_put_contents($this->root.'/package.json', '{"scripts":{"build":"vite build"}}');
        file_put_contents($this->root.'/package-lock.json', '{"lockfileVersion":3}');
        file_put_contents($this->root.'/resources/js/app.js', 'console.log("first")');
        file_put_contents($this->root.'/resources/views/page.blade.php', '{{ $first }}');
    }

    #[After]
    protected function removeFixtures(): void
    {
        $filesystem = new Filesystem;
        $filesystem->deleteDirectory($this->root);
        $filesystem->deleteDirectory($this->cacheDirectory);
        $filesystem->deleteDirectory($this->sharedDirectory);
    }

    public function test_it_fingerprints_build_inputs_deterministically(): void
    {
        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        $inputs = ['package.json', 'package-lock.json', 'resources'];
        $first = $cache->fingerprint($inputs);

        $this->assertSame($first, $cache->fingerprint(array_reverse($inputs)));

        file_put_contents($this->root.'/resources/views/page.blade.php', '{{ $second }}');

        $this->assertNotSame($first, $cache->fingerprint($inputs));
    }

    public function test_it_includes_vite_environment_files(): void
    {
        file_put_contents($this->root.'/.env', 'VITE_API_URL=first');
        file_put_contents($this->root.'/.env.production', 'VITE_API_URL=production');

        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        $inputs = $cache->defaultInputs();

        $this->assertContains('.env', $inputs);
        $this->assertContains('.env.production', $inputs);

        $fingerprint = $cache->fingerprint($inputs);
        file_put_contents($this->root.'/.env.production', 'VITE_API_URL=changed');

        $this->assertNotSame($fingerprint, $cache->fingerprint($inputs));
    }

    public function test_every_default_application_source_and_lockfile_changes_the_fingerprint(): void
    {
        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        foreach (['content/page.md', 'lang/en.php', 'routes/web.php', 'app/Page.php', 'config/frontend-build-cache.php', 'composer.lock'] as $file) {
            $path = $this->root.'/'.$file;
            (new Filesystem)->ensureDirectoryExists(dirname($path));
            file_put_contents($path, 'before');
            $before = $cache->fingerprint($cache->defaultInputs());
            file_put_contents($path, 'after');
            $this->assertNotSame($before, $cache->fingerprint($cache->defaultInputs()), $file);
        }
    }

    public function test_commands_manifest_and_outputs_each_change_the_key(): void
    {
        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        $key = $cache->fingerprint([], ['npm', 'ci'], ['npm', 'run', 'build']);
        $this->assertNotSame($key, $cache->fingerprint([], ['npm', 'install'], ['npm', 'run', 'build']));
        $this->assertNotSame($key, $cache->fingerprint([], ['npm', 'ci'], ['npm', 'run', 'staging']));
        foreach ([
            ['public/build', 'public/build/other.json', []],
            ['public', 'public/build/manifest.json', []],
            ['public/build', 'public/build/manifest.json', ['bootstrap/ssr']],
        ] as [$output, $manifest, $extra]) {
            $other = new FrontendBuildCache($this->root, $this->cacheDirectory, $output, $manifest, $extra);
            $this->assertNotSame($key, $other->fingerprint([], ['npm', 'ci'], ['npm', 'run', 'build']));
        }
    }

    public function test_pruning_preserves_current_build_and_removes_only_old_staging_copies(): void
    {
        mkdir($this->cacheDirectory);
        $current = str_repeat('a', 64);
        $previous = str_repeat('b', 64);
        $stale = '.'.$current.'-deadbeef0000';
        $active = '.'.$current.'-deadbeef1111';
        foreach ([$current, $previous, $stale, $active, '.unrelated'] as $entry) {
            mkdir($this->cacheDirectory.'/'.$entry);
        }
        touch($this->cacheDirectory.'/'.$stale, time() - 7200);
        touch($this->cacheDirectory.'/.unrelated', time() - 7200);
        touch($this->cacheDirectory.'/'.$current, time() - 60);
        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        $cache->prune(0, $current);

        foreach ([$current, $active, '.unrelated'] as $entry) {
            $this->assertDirectoryExists($this->cacheDirectory.'/'.$entry);
        }
        $this->assertDirectoryDoesNotExist($this->cacheDirectory.'/'.$previous);
        $this->assertDirectoryDoesNotExist($this->cacheDirectory.'/'.$stale);
        $this->assertFalse($cache->restore($previous));
    }

    public function test_it_fingerprints_symlinked_input_contents(): void
    {
        mkdir($this->sharedDirectory, 0777, true);
        file_put_contents($this->sharedDirectory.'/shared.js', 'first');
        symlink($this->sharedDirectory.'/shared.js', $this->root.'/resources/js/shared.js');

        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        $first = $cache->fingerprint(['resources']);
        file_put_contents($this->sharedDirectory.'/shared.js', 'second');

        $this->assertNotSame($first, $cache->fingerprint(['resources']));
    }

    public function test_it_stores_and_restores_a_build(): void
    {
        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        $fingerprint = $cache->fingerprint(['package.json', 'resources']);
        mkdir($this->root.'/public/build', 0777, true);
        file_put_contents($this->root.'/public/build/manifest.json', '{}');
        file_put_contents($this->root.'/public/build/app.js', 'built');

        $cache->store($fingerprint);
        (new Filesystem)->deleteDirectory($this->root.'/public/build');

        $this->assertTrue($cache->has($fingerprint));
        $this->assertTrue($cache->restore($fingerprint));
        $this->assertSame('built', file_get_contents($this->root.'/public/build/app.js'));
        $this->assertFileExists($this->root.'/public/build/manifest.json');
    }

    public function test_it_rejects_a_build_without_a_manifest(): void
    {
        $cache = new FrontendBuildCache($this->root, $this->cacheDirectory);
        mkdir($this->root.'/public/build', 0777, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vite manifest missing after build.');

        $cache->store($cache->fingerprint(['resources']));
    }
}
