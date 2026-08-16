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

    // Master on/off switch for transforms / generation / auto-queue / runtime.
    // false = no derivatives created; Twig url/img/picture fall back to the original image
    // so templates keep rendering without errors.
    'enabled' => true,

    // Profile name used when Twig/CLI omit an explicit profile.
    'defaultProfile' => 'responsive',

    // Output format used when Twig/CLI omit an explicit format.
    'defaultFormat' => 'webp',

    // Image library preference: auto | libvips | imagick | gd
    // auto picks the best available driver on the server.
    'driver' => 'auto',

    // Delivery — same idea as Craft generateTransformsBeforePageLoad.
    // true  = create missing files during the page request, then serve storage URLs
    // false = serve a signed action URL when missing (requires runtime.enabled)
    // Omit generateBeforePageLoad to mirror Craft's general config setting.
    'delivery' => [
        'generateBeforePageLoad' => true,
        'thumbnail' => [
            'enabled' => true,
            'width' => 32,
            'format' => 'jpg',
            'quality' => 50,
            'variant' => 'thumb',
        ],
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

    // Signed deferred-generation endpoint settings.
    'runtime' => [
        // Used when generateBeforePageLoad is false.
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
        /**
         * File / folder naming conventions for generated derivatives.
         *
         * {transformHash} is sliced from the generation identity (ops, encode, driver, …).
         * Changing sepia threshold / crop size / quality therefore creates a new path
         * instead of silently reusing a stale cached file.
         *
         * Tokens:
         *   {folderHash} {transformHash} {transformFolderHash} {identity} {identityShort}
         *   {identityShard} {assetId} {basename} {variant} {profile} {format} {ext}
         *   {namespace} {volume}
         */
        'naming' => [
            // Craft Asset originals (default — settings-aware folder segment).
            'assetPath' => '{folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}',
            // Local path / remote URL originals.
            'path' => '{identityShard}/{basename}-{variant}.{ext}',
            // Length of {transformHash} / {identityShort} (8–64).
            'transformHashLength' => 16,
            // Include volume handle inside {folderHash} input.
            'includeVolumeInFolderHash' => false,
            // Compact alternative (single settings-aware folder):
            // 'assetPath' => '{transformFolderHash}/{assetId}/{basename}-{variant}.{ext}',
            // Readable alternative:
            // 'assetPath' => '{volume}/{profile}/{variant}/{assetId}-{basename}.{ext}',
        ],
    ],

    // Native encode options passed to the selected image driver.
    // Encoding itself is done by GD / Imagick / Libvips — not by jpegoptim/cwebp.
    // Optional `arguments` apply when an external tool (e.g. cwebp) is used for that format.
    'encoders' => [
        // JPEG quality (0–100) and related options.
        'jpeg' => ['quality' => 82],
        // WebP quality (0–100). method/effort is passed to cwebp when webp optimizer is cwebp.
        'webp' => [
            'quality' => 80,
            // 'method' => 4,
            // Custom cwebp argv (replaces defaults). Tokens: {input} {output} {quality} {effort} {method}
            // 'arguments' => [
            //     '-q' => '{quality}',
            //     '-m' => 6,
            //     '-sharp_yuv' => true,
            //     '-o' => '{output}',
            //     '_' => ['{input}'],
            // ],
        ],
        // AVIF quality (0–100) when AVIF is enabled in a profile.
        'avif' => ['quality' => 65],
    ],

    // Optional post-encode binary optimizers (Ubuntu packages via apt).
    'optimizers' => [
        // Master switch for all binary optimizers.
        'enabled' => true,
        // job = write first, optimize via Craft queue (faster first paint; deferred queue optimize)
        // runtime = optimize before the URL is served
        // cwebp/avifenc always run during generate (format conversion, not post-optimize).
        'optimizeType' => 'job',
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
        // Or: ['tool' => 'jpegoptim', 'binary' => '/usr/bin/jpegoptim', 'arguments' => [...]]
        'jpeg' => 'jpegoptim',
        // Example with custom jpegoptim flags (replaces built-in --stdout --strip-all recipe):
        // 'jpeg' => [
        //     'tool' => 'jpegoptim',
        //     'binary' => App::env('SUPER_IMAGES_JPEGOPTIM') ?: 'jpegoptim',
        //     'arguments' => [
        //         '--stdout' => true,
        //         '--strip-all' => true,
        //         '--max' => 85,
        //         '_' => ['{input}'],
        //     ],
        // ],
        'png' => 'oxipng',
        'webp' => 'cwebp', // PNG→cwebp when binary exists; otherwise native Imagick/libvips WebP
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

    // Cleanup / retention for Playground previews and generated derivatives.
    'cleanup' => [
        // Delete Playground files under preview/ older than this many days.
        'previewRetentionDays' => 2,
        // Default aged cleanup and `--orphaned` only delete files older than this.
        // Override per run with --retention-days. Use --all=1 to ignore entirely.
        // Does not affect immediate cleanup on Asset delete/replace (see policies.cleanup below).
        'generatedRetentionDays' => 365,
        // Allow expensive remote storage listing during cleanup (keep false).
        'allowRemoteScan' => false,
    ],

    // Global generation policies — applied to every derivative unless overridden later.
    'policies' => [
        // Encode defaults merged into per-format encoder settings (format keys win).
        'encode' => [
            // Strip EXIF/IPTC and other metadata on output (recommended for web delivery).
            'stripMetadata' => true,
            // Progressive JPEG / interlaced PNG where the active driver supports it.
            'progressive' => false,
            // PNG zlib compression level (0 = none, 9 = max). Does not affect JPEG/WebP quality.
            'pngCompression' => 6,
        ],
        // Geometry guardrails for resize, crop, fit, fill, and scale operations.
        'geometry' => [
            // When false, output never exceeds source dimensions (small sources stay small).
            'allowUpscale' => false,
            // Downscale sharpness presets: soft | normal | sharp | extra
            // Or override: ['preset' => 'sharp', 'blur' => 0.82, 'unsharp' => [...|false]]
            'sharpness' => 'sharp',
        ],
        // Safety limits checked after the source is loaded into memory.
        'safety' => [
            // Soft cap on source width×height. Larger originals are downscaled to fit
            // this budget before profile transforms run (they are not rejected).
            // Distinct from runtime.maxPixels (which only limits signed lazy-generate URLs).
            'maxSourcePixels' => 40_000_000,
        ],
        // Asset lifecycle hooks for derivative cleanup (separate from retention days above).
        'cleanup' => [
            // Remove stored derivatives when a Craft Asset is deleted.
            'onAssetDelete' => true,
            // Remove stored derivatives when a Craft Asset file is replaced.
            'onAssetReplace' => true,
        ],
        // Fallback when a source cannot be resolved or generation fails planning.
        'fallback' => [
            // When true, substitute the configured Craft Asset instead of failing.
            'enabled' => false,
            // Craft Asset ID to serve as the fallback source image.
            'assetId' => null,
        ],
    ],
];
