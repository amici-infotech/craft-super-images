<?php
/**
 * Plugin settings model for Super Images configuration.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

use craft\base\Model;

/**
 * Settings Model
 *
 * Holds CP/project-config settings for profiles, storage, encoders, optimizers, and delivery.
 */
class Settings extends Model
{
    /** @var int Schema version included in generation identity payloads. */
    public const SCHEMA_VERSION = 4;

    /** @var bool Whether the plugin processes generation requests. */
    public bool $enabled = true;

    /** @var string Default profile handle when none is specified on a request. */
    public string $defaultProfile = 'responsive';

    /** @var string Default output format slug when none is specified on a request. */
    public string $defaultFormat = 'webp';

    /** @var string Image driver preference: `auto`, `libvips`, `imagick`, or `gd`. */
    public string $driver = 'auto';

    /** @var array<string, mixed> Auto-generation triggers and queue behaviour. */
    public array $autoGenerate = [
        'enabled' => true,
        'onUpload' => true,
        'onReplace' => true,
        'onFocalPointChange' => true,
        'queue' => true,
        'disableDuringImport' => true,
    ];

    /** @var array<string, mixed> Local and remote source resolution rules. */
    public array $sources = [
        'local' => [
            'enabled' => true,
            'allowedRoots' => [
                '@webroot/images',
                '@webroot/uploads',
            ],
        ],
        'remote' => [
            'enabled' => true,
            'allowedHosts' => [],
            'timeout' => 10,
            'maxBytes' => 25_000_000,
            'maxRedirects' => 3,
        ],
    ];

    /** @var array<string, mixed> Delivery behaviour (Craft-style before-page-load + thumbnails). */
    public array $delivery = [
        /**
         * Like Craft's generateTransformsBeforePageLoad:
         * true  = generate missing files during Twig, emit storage URLs
         * false = emit signed runtime action URLs when missing (requires runtime.enabled)
         * Omit to mirror Craft::$app->config->general->generateTransformsBeforePageLoad.
         */
        'generateBeforePageLoad' => true,
        /**
         * Tiny immediate `src` for picture() while full srcset / `<source>` URLs load.
         */
        'thumbnail' => [
            'enabled' => true,
            'width' => 32,
            'format' => 'jpg',
            'quality' => 50,
            'variant' => 'thumb',
        ],
    ];

    /** @var array<string, mixed> Signed runtime URL generation limits and secrets. */
    public array $runtime = [
        /** Required when generateBeforePageLoad is false. */
        'enabled' => true,
        /** @var string|null Falls back to Craft security key when null. */
        'signingSecret' => null,
        'urlTtl' => 3600,
        'maxWidth' => 4096,
        'maxHeight' => 4096,
        'maxPixels' => 20_000_000,
    ];

    /** @var array<string, mixed> Default storage adapter and marker directory configuration. */
    public array $storage = [
        'default' => 'local',
        'markers' => [
            'enabled' => true,
            'path' => '@storage/super-images/markers',
        ],
        /**
         * Derivative path naming templates.
         * Tokens: {folderHash} {transformHash} {transformFolderHash} {identity}
         * {identityShort} {identityShard} {assetId} {basename} {variant} {profile}
         * {format} {ext} {namespace} {volume}
         *
         * Default asset paths include {transformHash} (from the generation identity)
         * so changing ops/settings always creates a new folder instead of reusing cache.
         */
        'naming' => [
            'assetPath' => '{folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}',
            'path' => '{identityShard}/{basename}-{variant}.{ext}',
            'transformHashLength' => 16,
            'includeVolumeInFolderHash' => false,
        ],
        'adapters' => [
            'local' => [
                'type' => 'local',
                'path' => '@webroot/uploads/super-images',
                'baseUrl' => '@web/uploads/super-images',
            ],
        ],
    ];

    /** @var array<string, mixed> Per-format encoder defaults (quality and format-specific options). */
    public array $encoders = [
        'jpeg' => ['quality' => 82],
        'jpg' => ['quality' => 82],
        'png' => [],
        'webp' => ['quality' => 80],
        'avif' => ['quality' => 65],
    ];

    /** @var array<string, mixed> Post-encode optimizer tool configuration per format. */
    public array $optimizers = [
        'enabled' => true,
        /**
         * When to run same-format post-optimizers (jpegoptim, optipng, …):
         * - job     = write file first, optimize via Craft queue (faster page load; deferred after write)
         * - runtime = optimize during the generate request before serving
         * Format converters (cwebp / avifenc) always run during generate.
         */
        'optimizeType' => 'job',
        /**
         * Absolute paths (or env-resolved paths) for external tools.
         * Tool names alone are resolved via PATH when omitted.
         */
        'binaries' => [
            'jpegoptim' => 'jpegoptim',
            'oxipng' => 'oxipng',
            'optipng' => 'optipng',
            'pngquant' => 'pngquant',
            'cwebp' => 'cwebp',
            'avifenc' => 'avifenc',
        ],
        // Per-format tool: string name, null, or
        // ['tool' => 'jpegoptim', 'binary' => '/usr/bin/jpegoptim', 'arguments' => ['--max' => 85, '_' => ['{input}']]]
        'jpeg' => 'jpegoptim',
        'png' => 'oxipng',
        'webp' => null,
        'avif' => null,
    ];

    /** @var array<string, mixed> Named responsive/transform profiles and their variants. */
    public array $profiles = [
        'responsive' => [
            'formats' => [
                'jpg',
                'webp',
                // 'avif',
            ],
            'variants' => [
                'sm' => ['width' => 576],
                'md' => ['width' => 768],
                'lg' => ['width' => 992],
                'xl' => ['width' => 1280],
                '2xl' => ['width' => 1600],
            ],
            'defaults' => [
                'position' => 'center-center',
                'mode' => 'fit',
                'jpegQuality' => 80,
            ],
        ],
    ];

    /** @var array<string, mixed> Standalone variant definitions keyed by handle. */
    public array $variants = [];

    /** @var array<string, mixed> Per-volume overrides for profiles, storage, or generation rules. */
    public array $volumes = [];

    /** @var array<string, mixed> Per-folder overrides within asset volumes. */
    public array $folders = [];

    /** @var array<string, mixed> Per-field overrides for asset fields. */
    public array $fields = [];

    /** @var array<string, mixed> Retention and cleanup rules for preview and generated derivatives. */
    public array $cleanup = [
        'previewRetentionDays' => 2,
        /**
         * Minimum age (in days) a *generated* (non-preview) derivative must reach before
         * `--orphaned` or `--all` console cleanup is allowed to delete it. Defaults to a
         * full year so derivatives are cached long-term by default; raise or lower via
         * config. This never blocks `purgeAssetDerivatives()` triggered by an explicit
         * Craft Asset delete/replace — those always remove the affected asset's files
         * immediately since the source itself changed.
         */
        'generatedRetentionDays' => 365,
        /** Remote storage listing is expensive; keep off unless explicitly enabled. */
        'allowRemoteScan' => false,
    ];

    /** @var array<string, mixed> Global generation policies (encode, geometry, safety, cleanup, fallback). */
    public array $policies = [
        'encode' => [
            'stripMetadata' => true,
            'progressive' => false, // progressive JPEG / interlace where supported
            'pngCompression' => 6,  // 0–9
        ],
        'geometry' => [
            'allowUpscale' => false,
            /**
             * Downscale sharpness: soft | normal | sharp | extra
             * Or an array: ['preset' => 'sharp', 'blur' => 0.82, 'unsharp' => [...|false]]
             */
            'sharpness' => 'sharp',
        ],
        'safety' => [
            'maxSourcePixels' => 40_000_000, // soft-cap: downscale huge sources after load
        ],
        'cleanup' => [
            'onAssetDelete' => true,
            'onAssetReplace' => true,
        ],
        'fallback' => [
            'enabled' => false,
            // Craft asset ID used when the requested source cannot be planned/generated
            'assetId' => null,
        ],
    ];

    /**
     * Exports settings as a plain array for config merging and diagnostics.
     *
     * @return array<string, mixed> All configurable keys and their current values.
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'defaultProfile' => $this->defaultProfile,
            'defaultFormat' => $this->defaultFormat,
            'driver' => $this->driver,
            'autoGenerate' => $this->autoGenerate,
            'delivery' => $this->delivery,
            'sources' => $this->sources,
            'runtime' => $this->runtime,
            'storage' => $this->storage,
            'encoders' => $this->encoders,
            'optimizers' => $this->optimizers,
            'profiles' => $this->profiles,
            'variants' => $this->variants,
            'volumes' => $this->volumes,
            'folders' => $this->folders,
            'fields' => $this->fields,
            'cleanup' => $this->cleanup,
            'policies' => $this->policies,
        ];
    }
}
