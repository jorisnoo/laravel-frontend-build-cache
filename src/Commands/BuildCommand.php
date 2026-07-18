<?php

namespace Noo\LaravelFrontendBuildCache\Commands;

use Illuminate\Console\Command;
use Noo\LaravelFrontendBuildCache\FrontendBuildCache;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

class BuildCommand extends Command
{
    protected $signature = 'deploy:build
                            {--force : Rebuild and replace an existing cached build}
                            {--dry-run : Report whether a build would run without changing files}
                            {--build-cache-dir= : Store frontend builds in this directory}';

    protected $description = 'Restore a matching frontend build or install and compile it when inputs changed';

    public function handle(): int
    {
        try {
            $root = base_path();
            $cacheDirectory = $this->resolveBuildCacheDirectory();
            $installCommand = $this->configArray('install_command');
            $buildCommand = $this->configArray('build_command');
            $cache = new FrontendBuildCache(
                $root,
                $cacheDirectory,
                $this->configString('output_directory'),
                $this->configString('manifest'),
            );
            $inputs = $cache->inputs(
                $this->configArray('inputs'),
                $this->configArray('input_patterns'),
            );
            $fingerprint = $cache->fingerprint($inputs);
            $this->line("Frontend build fingerprint: $fingerprint");
            $this->line("Build cache: $cacheDirectory");

            if (! $this->option('force') && $cache->has($fingerprint)) {
                if ($this->option('dry-run')) {
                    $this->info('Matching frontend build found; it would be restored.');

                    return self::SUCCESS;
                }

                $cache->restore($fingerprint);
                $this->info('Restored matching frontend build from cache.');

                return self::SUCCESS;
            }

            if ($this->option('dry-run')) {
                $this->warn(sprintf(
                    'No matching frontend build found; %s and %s would run.',
                    implode(' ', $installCommand),
                    implode(' ', $buildCommand),
                ));

                return self::SUCCESS;
            }

            $this->runProcess($installCommand, $root);
            $this->runProcess($buildCommand, $root);
            $cache->store($fingerprint, (bool) $this->option('force'));
            $cache->prune((int) config('frontend-build-cache.keep', 5));
            $this->info('Frontend build completed and cached.');
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  string[]  $command
     */
    private function runProcess(array $command, string $workingDirectory): void
    {
        $this->line('$ '.implode(' ', $command));
        $process = new Process($command, $workingDirectory);
        $process->setTimeout(null);
        $exitCode = $process->run(function (string $_type, string $output): void {
            $this->output->write($output, false, OutputInterface::OUTPUT_RAW);
        });

        if ($exitCode !== self::SUCCESS) {
            throw new \RuntimeException(sprintf('%s failed with exit code %d.', implode(' ', $command), $exitCode));
        }
    }

    private function resolveBuildCacheDirectory(): string
    {
        $override = $this->option('build-cache-dir');

        if (is_string($override) && $override !== '') {
            return str_starts_with($override, '/') ? $override : base_path($override);
        }

        $configured = config('frontend-build-cache.cache_directory');

        if (is_string($configured) && $configured !== '') {
            return str_starts_with($configured, '/') ? $configured : base_path($configured);
        }

        $forgeSiteRoot = getenv('FORGE_SITE_ROOT');

        if (is_string($forgeSiteRoot) && $forgeSiteRoot !== '') {
            return rtrim($forgeSiteRoot, '/').'/.deploy-cache/frontend-builds';
        }

        return storage_path('framework/cache/deploy-builds');
    }

    /**
     * @return string[]
     */
    private function configArray(string $key): array
    {
        $value = config("frontend-build-cache.$key");

        if (! is_array($value) || array_filter($value, fn ($item) => ! is_string($item)) !== []) {
            throw new \RuntimeException("frontend-build-cache.$key must be an array of strings.");
        }

        return array_values($value);
    }

    private function configString(string $key): string
    {
        $value = config("frontend-build-cache.$key");

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("frontend-build-cache.$key must be a non-empty string.");
        }

        return $value;
    }
}
