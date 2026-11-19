<?php

namespace Noo\LaravelFrontendBuildCache;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FrontendBuildCache
{
    private const VERSION = 2;

    /** @var list<string> */
    private readonly array $outputs;

    /**
     * @param  list<string>  $additionalOutputs
     */
    public function __construct(
        private readonly string $root,
        private readonly string $cacheDirectory,
        private readonly string $outputDirectory = 'public/build',
        private readonly string $manifest = 'public/build/manifest.json',
        array $additionalOutputs = [],
    ) {
        $this->outputs = [$outputDirectory, ...$additionalOutputs];
        $directories = [rtrim($this->cacheDirectory, '/')];
        foreach ($this->outputs as $output) {
            if (trim($output, '/') === '' || in_array('.', explode('/', $output), true)
                || in_array('..', explode('/', $output), true) || str_contains($output, '//') || str_ends_with($output, '/')) {
                throw new RuntimeException('Build output directories must be normalized paths without dot segments.');
            }

            $path = $this->absolutePath($output);
            if (str_starts_with(rtrim($this->root, '/').'/', $path.'/')) {
                throw new RuntimeException('Build output must not contain the application root.');
            }
            foreach ($directories as $directory) {
                if (str_starts_with($directory.'/', $path.'/') || str_starts_with($path.'/', $directory.'/')) {
                    throw new RuntimeException('The build outputs and cache directory must not overlap.');
                }
            }

            $directories[] = $path;
        }
        $this->manifestRelativeToOutput();
    }

    /**
     * @return string[]
     */
    public function defaultInputs(): array
    {
        $config = require __DIR__.'/../config/frontend-build-cache.php';

        return $this->inputs($config['inputs'], $config['input_patterns']);
    }

    /**
     * @param  string[]  $inputs
     * @param  string[]  $patterns
     * @return string[]
     */
    public function inputs(array $inputs, array $patterns = []): array
    {
        foreach ($patterns as $pattern) {
            foreach (glob(rtrim($this->root, '/').'/'.$pattern) ?: [] as $path) {
                $inputs[] = substr($path, strlen(rtrim($this->root, '/')) + 1);
            }
        }

        return array_values(array_unique($inputs));
    }

    /**
     * @param  string[]  $inputs
     * @param  string[]  $installCommand
     * @param  string[]  $buildCommand
     */
    public function fingerprint(array $inputs, array $installCommand = [], array $buildCommand = []): string
    {
        $context = hash_init('sha256');
        hash_update($context, 'laravel-frontend-build-cache-v'.self::VERSION."\0");
        hash_update($context, serialize([$installCommand, $buildCommand, $this->outputs, $this->manifest])."\0");

        foreach ($this->inputFiles($inputs) as $relativePath => $input) {
            hash_update($context, $relativePath."\0");
            hash_update($context, $input['type']."\0");

            if ($input['link'] !== null) {
                hash_update($context, $input['link']."\0");
            }

            if ($input['type'] !== 'file' && $input['type'] !== 'link-file') {
                continue;
            }

            $stream = fopen($input['path'], 'rb');

            if ($stream === false) {
                throw new RuntimeException("Unable to read build input: {$input['path']}");
            }

            hash_update_stream($context, $stream);
            fclose($stream);
            hash_update($context, "\0");
        }

        return hash_final($context);
    }

    public function has(string $fingerprint): bool
    {
        $entry = $this->cachePath($fingerprint);
        foreach (array_keys($this->outputs) as $index) {
            if (! is_dir($entry.'/'.$index)) {
                return false;
            }
        }

        return is_file($entry.'/0/'.$this->manifestRelativeToOutput());
    }

    public function restore(string $fingerprint): bool
    {
        if (! $this->has($fingerprint)) {
            return false;
        }

        $source = $this->cachePath($fingerprint);
        $staged = [];

        try {
            foreach ($this->outputs as $index => $output) {
                $destination = $this->absolutePath($output);
                $staging = dirname($destination).'/.'.basename($destination).'-restore-'.bin2hex(random_bytes(6));
                $staged[$staging] = $destination;
                $this->copyDirectory($source.'/'.$index, $staging);

                if ($index === 0 && ! is_file($staging.'/'.$this->manifestRelativeToOutput())) {
                    throw new RuntimeException('The restored frontend build does not contain its Vite manifest.');
                }
            }

            foreach ($staged as $staging => $destination) {
                $this->removeDirectory($destination);

                if (! rename($staging, $destination)) {
                    throw new RuntimeException("Unable to restore the frontend build to $destination");
                }
            }
        } finally {
            foreach (array_keys($staged) as $staging) {
                $this->removeDirectory($staging);
            }
        }

        return true;
    }

    public function store(string $fingerprint, bool $replace = false): void
    {
        if (! is_file($this->absolutePath($this->manifest))) {
            throw new RuntimeException('Vite manifest missing after build.');
        }

        $this->ensureDirectory($this->cacheDirectory);
        $destination = $this->cachePath($fingerprint);
        $staging = $this->cacheDirectory.'/.'.$fingerprint.'-'.bin2hex(random_bytes(6));

        try {
            foreach ($this->outputs as $index => $output) {
                $source = $this->absolutePath($output);
                if (! is_dir($source)) {
                    throw new RuntimeException("Missing frontend build output: $output");
                }
                $this->copyDirectory($source, $staging.'/'.$index);
            }

            if (! $replace && $this->has($fingerprint)) {
                return;
            }

            $this->removeDirectory($destination);

            if (! rename($staging, $destination)) {
                throw new RuntimeException("Unable to publish the frontend build cache at $destination");
            }
        } finally {
            $this->removeDirectory($staging);
        }
    }

    public function prune(int $keep = 5, ?string $current = null): void
    {
        if (! is_dir($this->cacheDirectory)) {
            return;
        }

        $entries = [];
        $staleBefore = time() - 3600;

        foreach (new FilesystemIterator($this->cacheDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! $entry->isDir() || $entry->isLink()) {
                continue;
            }

            $name = $entry->getFilename();
            if (preg_match('/^[a-f0-9]{64}$/D', $name)) {
                $entries[$entry->getPathname()] = $name === $current ? PHP_INT_MAX : $entry->getMTime();
            } elseif (preg_match('/^\.[a-f0-9]{64}-[a-f0-9]{12}$/D', $name) && $entry->getMTime() < $staleBefore) {
                $this->removeDirectory($entry->getPathname());
            }
        }

        arsort($entries);

        foreach (array_slice(array_keys($entries), max(1, $keep)) as $path) {
            $this->removeDirectory($path);
        }
    }

    /**
     * @param  string[]  $inputs
     * @return array<string, array{path: string, type: string, link: ?string}>
     */
    private function inputFiles(array $inputs): array
    {
        $files = [];

        foreach ($inputs as $input) {
            $absolutePath = $this->absolutePath($input);
            $relativePath = $this->relativePath($absolutePath);

            if (is_link($absolutePath)) {
                $this->collectLink($absolutePath, $relativePath, $files, []);
            } elseif (is_file($absolutePath)) {
                $files[$relativePath] = ['path' => $absolutePath, 'type' => 'file', 'link' => null];
            } elseif (is_dir($absolutePath)) {
                $this->collectDirectory($absolutePath, $relativePath, $files, []);
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param  array<string, array{path: string, type: string, link: ?string}>  $files
     * @param  string[]  $ancestors
     */
    private function collectDirectory(string $directory, string $relativePath, array &$files, array $ancestors): void
    {
        $resolvedDirectory = realpath($directory);

        if ($resolvedDirectory === false || in_array($resolvedDirectory, $ancestors, true)) {
            return;
        }

        $ancestors[] = $resolvedDirectory;
        $entries = scandir($directory);

        if ($entries === false) {
            throw new RuntimeException("Unable to read build input directory: $directory");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            $childRelativePath = trim($relativePath.'/'.$entry, '/');

            if (is_link($path)) {
                $this->collectLink($path, $childRelativePath, $files, $ancestors);
            } elseif (is_file($path)) {
                $files[$childRelativePath] = ['path' => $path, 'type' => 'file', 'link' => null];
            } elseif (is_dir($path)) {
                $this->collectDirectory($path, $childRelativePath, $files, $ancestors);
            }
        }
    }

    /**
     * @param  array<string, array{path: string, type: string, link: ?string}>  $files
     * @param  string[]  $ancestors
     */
    private function collectLink(string $path, string $relativePath, array &$files, array $ancestors): void
    {
        $target = readlink($path);

        if ($target === false) {
            throw new RuntimeException("Unable to read build input symlink: $path");
        }

        if (is_file($path)) {
            $files[$relativePath] = ['path' => $path, 'type' => 'link-file', 'link' => $target];

            return;
        }

        $files[$relativePath] = [
            'path' => $path,
            'type' => is_dir($path) ? 'link-directory' : 'broken-link',
            'link' => $target,
        ];

        if (is_dir($path)) {
            $this->collectDirectory($path, $relativePath, $files, $ancestors);
        }
    }

    private function cachePath(string $fingerprint): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new RuntimeException('Invalid frontend build fingerprint.');
        }

        return rtrim($this->cacheDirectory, '/').'/'.$fingerprint;
    }

    private function manifestRelativeToOutput(): string
    {
        $output = trim($this->outputDirectory, '/');
        $manifest = trim($this->manifest, '/');

        if (! str_starts_with($manifest, $output.'/')) {
            throw new RuntimeException('The Vite manifest must be inside the build output directory.');
        }

        return substr($manifest, strlen($output) + 1);
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->root, '/').'/'.ltrim($path, '/');
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->root, '/').'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination.'/'.substr($item->getPathname(), strlen(rtrim($source, '/')) + 1);

            if ($item->isDir() && ! $item->isLink()) {
                $this->ensureDirectory($target);
            } elseif ($item->isLink()) {
                $this->ensureDirectory(dirname($target));

                if (! symlink(readlink($item->getPathname()), $target)) {
                    throw new RuntimeException("Unable to copy symlink to $target");
                }
            } else {
                $this->ensureDirectory(dirname($target));

                if (! copy($item->getPathname(), $target)) {
                    throw new RuntimeException("Unable to copy frontend build file to $target");
                }
            }
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create directory: $path");
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
