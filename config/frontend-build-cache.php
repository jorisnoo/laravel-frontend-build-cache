<?php

return [
    'inputs' => [
        'package.json',
        'package-lock.json',
        'npm-shrinkwrap.json',
        '.npmrc',
        'composer.lock',
        'config/frontend-build-cache.php',
        'app',
        'content',
        'lang',
        'routes',
        'resources',
    ],

    'input_patterns' => [
        'vite.config.*',
        'tailwind.config.*',
        'postcss.config.*',
        'webpack.config.*',
        'tsconfig*.json',
        'jsconfig*.json',
        '.env',
        '.env.*',
    ],

    'output_directory' => 'public/build',
    // For SSR, add 'bootstrap/ssr'. Additional outputs require an install on hits.
    'additional_outputs' => [],
    'manifest' => 'public/build/manifest.json',

    // Null uses $FORGE_SITE_ROOT/.deploy-cache/frontend-builds on Forge and
    // storage/framework/cache/deploy-builds elsewhere.
    'cache_directory' => null,

    'install_command' => ['npm', 'ci'],
    'build_command' => ['npm', 'run', 'build'],
    'keep' => 5,
];
