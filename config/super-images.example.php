<?php

use craft\helpers\App;

/**
 * Example project config for Super Images.
 *
 * Copy to your Craft project as:
 *   config/super-images.php
 *
 * Credentials should use environment variables.
 */
return [
    'enabled' => true,
    'defaultProfile' => 'responsive',
    'defaultFormat' => 'webp',
    'driver' => 'auto',

    'autoGenerate' => [
        'enabled' => true,
        'onUpload' => true,
        'onReplace' => true,
        'onFocalPointChange' => true,
        'queue' => true,
        'disableDuringImport' => true,
    ],

    'sources' => [
        'local' => [
            'enabled' => true,
            'allowedRoots' => [
                '@webroot/images',
                '@webroot/uploads',
            ],
        ],
        'remote' => [
            'enabled' => true,
            'allowedHosts' => [
                'cdn.example.com',
            ],
            'timeout' => 10,
            'maxBytes' => 25_000_000,
            'maxRedirects' => 3,
        ],
    ],

    'runtime' => [
        'enabled' => true,
        'signingSecret' => App::env('SUPER_IMAGES_SIGNING_SECRET'),
        'urlTtl' => 3600,
        'maxWidth' => 4096,
        'maxHeight' => 4096,
        'maxPixels' => 20_000_000,
    ],

    'storage' => [
        'default' => App::env('SUPER_IMAGES_STORAGE') ?: 'local',
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
            's3' => [
                'type' => 's3',
                'keyId' => App::env('SUPER_IMAGES_S3_KEY_ID'),
                'secret' => App::env('SUPER_IMAGES_S3_SECRET'),
                'bucket' => App::env('SUPER_IMAGES_S3_BUCKET'),
                'region' => App::env('SUPER_IMAGES_S3_REGION'),
                'endpoint' => App::env('SUPER_IMAGES_S3_ENDPOINT'),
                'prefix' => 'derivatives/',
                'baseUrl' => App::env('SUPER_IMAGES_CDN_URL'),
            ],
        ],
    ],

    'encoders' => [
        'jpeg' => ['quality' => 82],
        'webp' => ['quality' => 80],
        'avif' => ['quality' => 65],
    ],

    'optimizers' => [
        'enabled' => true,
        'jpeg' => 'jpegoptim',
        'png' => 'oxipng',
        'webp' => null,
        'avif' => null,
    ],

    'profiles' => [
        'responsive' => [
            'formats' => ['jpg', 'webp', 'avif'],
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
    ],

    'volumes' => [
        // 'images' => [
        //     'profile' => 'responsive',
        //     'autoGenerate' => true,
        //     'storage' => 's3',
        // ],
    ],

    'fields' => [
        // 'heroImage' => [
        //     'profiles' => ['responsive'],
        // ],
    ],

    'cleanup' => [
        'previewRetentionDays' => 2,
        'obsoleteRetentionDays' => 30,
    ],
];
