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

Derivatives use a folder-grouped layout so related files stay grouped and
filenames stay readable:

```text
{folderHash}/{assetId}/{basename}-{variant}.{ext}
```

- `folderHash` — `md5('/' . folderPath)` so assets in the same volume folder share a prefix
- `assetId` — Craft asset element ID
- `basename-variant.ext` — original filename stem + variant handle + format

Examples:

```text
41762720c56668e667b056cfce41e4c6/184704/hero-md.webp
41762720c56668e667b056cfce41e4c6/184704/hero-lg.jpg
preview/20260814/41762720c56668e667b056cfce41e4c6/184704/hero-sm.webp
```

Non-asset sources (local path / remote URL) use a short identity shard instead of
`folderHash/assetId`:

```text
{identity[0:2]}/{identity[2:4]}/{basename}-{variant}.{ext}
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
