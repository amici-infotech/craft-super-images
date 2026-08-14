# Storage

Derivative storage is **independent** of Craft Volumes. Craft Volumes hold originals; Super Images adapters hold generated derivatives.

---

## Local adapter

```php
'storage' => [
    'default' => 'local',
    'adapters' => [
        'local' => [
            'type' => 'local',
            'path' => '@webroot/uploads/super-images',
            'baseUrl' => '@web/uploads/super-images',
        ],
    ],
],
```

Default path uses `/uploads/super-images` so templates under `/super-images` are not shadowed.

---

## S3-compatible (S3 / Spaces / R2)

Requires `aws/aws-sdk-php`.

```php
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
```

Remote storage does **not** keep a permanent local image mirror. Tiny existence markers live under:

```text
@storage/super-images/markers
```

Never under webroot.

---

## Path shape

Paths are deliberately flat — two short shard directories (4 hex chars from the
identity, up to 65,536 buckets) hold the derivative file directly. There is no
per-derivative directory, so a volume with hundreds of thousands of derivatives
still keeps directory listings small and shallow:

```text
{optional-namespace}/{shard1}/{shard2}/{identity}--{profile}-{variant}.{ext}
```

Profile and variant are embedded in the filename for readability only — they
are not required for uniqueness, since the identity hash already encodes
profile, variant, format, and every operation/encoder option.

Examples:

```text
68/fc/68fcc0e1…--responsive-sm.jpg
preview/20260814/01/f9/01f900ab…--responsive-md.webp
```

Playground uses the `preview/Ymd/` namespace so experiments do not collide with production derivatives.

---

## Volume overrides

```php
'volumes' => [
    'images' => [
        'storage' => 's3',
        'profile' => 'responsive',
        'autoGenerate' => true,
    ],
],
```
