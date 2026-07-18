<?php

use Illuminate\Support\Facades\Artisan;

it('registers the cached frontend build command', function () {
    $cacheDirectory = sys_get_temp_dir().'/frontend-build-command-'.bin2hex(random_bytes(6));

    Artisan::call('deploy:build', [
        '--dry-run' => true,
        '--build-cache-dir' => $cacheDirectory,
    ]);

    expect(Artisan::output())
        ->toContain('Frontend build fingerprint:')
        ->toContain('No matching frontend build found; npm ci and npm run build would run.');
});
