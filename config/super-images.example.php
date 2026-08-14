<?php

use craft\helpers\App;

/**
 * Super Images project config.
 *
 * Place this file at: config/super-images.php
 *
 * Prefer environment variables for secrets and host-specific binary paths.
 * Live servers are assumed to be Ubuntu.
 */
return [

    // Master on/off switch for the entire plugin.
    'enabled' => true,

    // Profile name used when Twig/CLI omit an explicit profile.
    'defaultProfile' => 'responsive',

    // Output format used when Twig/CLI omit an explicit format.
    'defaultFormat' => 'webp',

    // Image library preference: auto | libvips | imagick | gd
    // auto picks the best available driver on the server.
    'driver' => 'auto',

    // How Twig delivery URLs are emitted.
    // lazy   = signed runtime generate URL (generate on first request)
    // eager  = final storage/CDN URL (pre-generate via CLI/queue)
    // hybrid = currently same as eager (reserved for later overrides)
    'delivery' => [
        'mode' => 'lazy',
    ],

    // Automatic eager generation when Craft Assets are saved.
    'autoGenerate' => [
        // Turn the whole auto-generate feature on/off.
        'enabled' => true,
        // Queue generation after a new Asset upload.
        'onUpload' => true,
        // Queue generation after an Asset file replace.
        'onReplace' => true,
        // Queue generation when focal point changes.
        'onFocalPointChange' => true,
        // true = push Craft queue jobs; false = generate inline (slow for CP).
        'queue' => true,
        // Skip auto-generate during imports / maintenance-style saves.
        'disableDuringImport' => true,
    ],

    // Allowed original-image sources besides Craft Assets.
    'sources' => [
        // Public/local filesystem originals (e.g. /images/hero.png).
        'local' => [
            // Allow local-path sources at all.
            'enabled' => true,
            // Only files under these roots may be processed.
            'allowedRoots' => [
                '@webroot/images',
                '@webroot/uploads',
            ],
        ],
        // Remote/CDN originals by absolute URL.
        'remote' => [
            // Allow remote URL sources at all.
            'enabled' => true,
            // Host allow-list (exact hostnames). Empty = deny all remotes.
            'allowedHosts' => [
                'cdn.example.com',
            ],
            // HTTP timeout in seconds when fetching a remote original.
            'timeout' => 10,
            // Max download size in bytes for a remote original.
            'maxBytes' => 25_000_000,
            // Max redirects when fetching a remote original.
            'maxRedirects' => 3,
        ],
    ],

    // Signed lazy-generation endpoint settings.
    'runtime' => [
        // Allow /actions/super-images/runtime/generate
        'enabled' => true,
        // HMAC secret for signed URLs. Falls back to Craft securityKey when null/empty.
        'signingSecret' => App::env('SUPER_IMAGES_SIGNING_SECRET'),
        // Signed URL lifetime in seconds.
        'urlTtl' => 3600,
        // Maximum requested width accepted by runtime generation.
        'maxWidth' => 4096,
        // Maximum requested height accepted by runtime generation.
        'maxHeight' => 4096,
        // Maximum width*height accepted by runtime generation.
        'maxPixels' => 20_000_000,
    ],

    // Where generated derivatives are stored (independent of Craft Volumes).
    'storage' => [
        // Default adapter handle from adapters below.
        'default' => App::env('SUPER_IMAGES_STORAGE') ?: 'local',
        // Tiny existence markers for remote adapters (never under webroot).
        'markers' => [
            // Write markers when remote storage is used.
            'enabled' => true,
            // Private Craft storage path for markers.
            'path' => '@storage/super-images/markers',
        ],
        // Named storage adapters.
        'adapters' => [
            'local' => [
                // Adapter type: local | s3
                'type' => 'local',
                // Filesystem root for derivatives.
                'path' => '@webroot/uploads/super-images',
                // Public base URL for derivatives.
                'baseUrl' => '@web/uploads/super-images',
            ],
            // 's3' => [
            //     'type' => 's3',
            //     'keyId' => App::env('SUPER_IMAGES_S3_KEY_ID'),
            //     'secret' => App::env('SUPER_IMAGES_S3_SECRET'),
            //     'bucket' => App::env('SUPER_IMAGES_S3_BUCKET'),
            //     'region' => App::env('SUPER_IMAGES_S3_REGION'),
            //     'endpoint' => App::env('SUPER_IMAGES_S3_ENDPOINT'),
            //     'prefix' => 'derivatives/',
            //     'baseUrl' => App::env('SUPER_IMAGES_CDN_URL'),
            // ],
        ],
    ],

    // Native encode options passed to the selected image driver.
    // Encoding itself is done by GD / Imagick / Libvips — not by jpegoptim/cwebp.
    'encoders' => [
        // JPEG quality (0–100) and related options.
        'jpeg' => ['quality' => 82],
        // WebP quality (0–100).
        'webp' => ['quality' => 80],
        // AVIF quality (0–100) when AVIF is enabled in a profile.
        'avif' => ['quality' => 65],
    ],

    // Optional post-encode binary optimizers (Ubuntu packages via apt).
    'optimizers' => [
        // Master switch for all binary optimizers.
        'enabled' => true,
        // Absolute paths or command names. Prefer env vars on each server.
        // Typical Ubuntu paths: /usr/bin/jpegoptim, /usr/bin/cwebp, …
        'binaries' => [
            'jpegoptim' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: 'jpegoptim',
            'oxipng' => App::env('SUPER_IMAGES_OXIPNG') ?: 'oxipng',
            'optipng' => App::env('SUPER_IMAGES_OPTIPNG') ?: 'optipng',
            'pngquant' => App::env('SUPER_IMAGES_PNGQUANT') ?: 'pngquant',
            'cwebp' => App::env('SUPER_IMAGES_CWEBP') ?: 'cwebp',
            'avifenc' => App::env('SUPER_IMAGES_AVIFENC') ?: 'avifenc',
        ],
        // Which optimizer tool to run per output format (null = skip).
        // Or: 'jpeg' => ['tool' => 'jpegoptim', 'binary' => '/usr/bin/jpegoptim'],
        'jpeg' => 'jpegoptim',
        'png' => 'oxipng',
        'webp' => null, // set to 'cwebp' to re-optimize WebP via libwebp
        'avif' => null,
    ],

    // Named generation profiles (variants × formats).
    'profiles' => [
        'responsive' => [
            // Formats generated for each variant. Prefer WebP for now.
            'formats' => [
                'jpg',
                'webp',
                // 'avif',
            ],
            // Named sizes. Keys become variant names (sm, md, …).
            'variants' => [
                'sm' => ['width' => 576],
                'md' => ['width' => 768],
                'lg' => ['width' => 992],
                'xl' => ['width' => 1280],
                '2xl' => ['width' => 1600],
            ],
            // Defaults applied when a variant omits mode/position/quality.
            'defaults' => [
                'position' => 'center-center',
                'mode' => 'fit',
                'jpegQuality' => 80,
            ],
        ],
    ],

    // Per Craft Volume handle overrides (profile, storage, autoGenerate, …).
    'volumes' => [
        // 'images' => [
        //     'profile' => 'responsive',
        //     'autoGenerate' => true,
        //     'storage' => 's3',
        // ],
    ],

    // Per Asset field handle overrides.
    'fields' => [
        // 'heroImage' => [
        //     'profiles' => ['responsive'],
        // ],
    ],

    // Cleanup / retention for Playground previews and obsolete objects.
    'cleanup' => [
        // Delete Playground files under preview/ older than this many days.
        'previewRetentionDays' => 2,
        // Retention window for obsolete derivative cleanup strategies.
        'obsoleteRetentionDays' => 30,
        // Allow expensive remote storage listing during cleanup (keep false).
        'allowRemoteScan' => false,
    ],
];
