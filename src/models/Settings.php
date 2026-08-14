<?php

namespace amici\SuperImages\models;

use craft\base\Model;

class Settings extends Model
{
    public const SCHEMA_VERSION = 1;

    public bool $enabled = true;

    public string $defaultProfile = 'responsive';

    public string $defaultFormat = 'webp';

    /** @var string auto|libvips|imagick|gd */
    public string $driver = 'auto';

    /** @var array<string, mixed> */
    public array $autoGenerate = [
        'enabled' => true,
        'onUpload' => true,
        'onReplace' => true,
        'onFocalPointChange' => true,
        'queue' => true,
        'disableDuringImport' => true,
    ];

    /** @var array<string, mixed> */
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

    /** @var array<string, mixed> */
    public array $delivery = [
        'mode' => 'lazy', // eager|lazy|hybrid — eager/hybrid emit storage URLs; lazy emits signed runtime URLs
    ];

    /** @var array<string, mixed> */
    public array $runtime = [
        'enabled' => true,
        /** @var string|null Falls back to Craft security key when null. */
        'signingSecret' => null,
        'urlTtl' => 3600,
        'maxWidth' => 4096,
        'maxHeight' => 4096,
        'maxPixels' => 20_000_000,
    ];

    /** @var array<string, mixed> */
    public array $storage = [
        'default' => 'local',
        'markers' => [
            'enabled' => true,
            'path' => '@storage/super-images/markers',
        ],
        'adapters' => [
            'local' => [
                'type' => 'local',
                'path' => '@webroot/uploads/super-images',
                'baseUrl' => '@web/uploads/super-images',
            ],
        ],
    ];

    /** @var array<string, mixed> */
    public array $encoders = [
        'jpeg' => ['quality' => 82],
        'jpg' => ['quality' => 82],
        'png' => [],
        'webp' => ['quality' => 80],
        'avif' => ['quality' => 65],
    ];

    /** @var array<string, mixed> */
    public array $optimizers = [
        'enabled' => true,
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
        // Per-format tool: string name, null, or ['tool' => 'jpegoptim', 'binary' => '/usr/bin/jpegoptim']
        'jpeg' => 'jpegoptim',
        'png' => 'oxipng',
        'webp' => null,
        'avif' => null,
    ];

    /** @var array<string, mixed> */
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

    /** @var array<string, mixed> */
    public array $variants = [];

    /** @var array<string, mixed> */
    public array $volumes = [];

    /** @var array<string, mixed> */
    public array $folders = [];

    /** @var array<string, mixed> */
    public array $fields = [];

    /** @var array<string, mixed> */
    public array $cleanup = [
        'previewRetentionDays' => 2,
        'obsoleteRetentionDays' => 30,
        /** Remote storage listing is expensive; keep off unless explicitly enabled. */
        'allowRemoteScan' => false,
    ];

    /**
     * @return array<string, mixed>
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
        ];
    }
}
