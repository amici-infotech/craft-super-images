# Phase 1 — Storage

## Purpose

This document defines the **storage** architecture for **Super Images**.

Storage is responsible for persisting generated derivatives and producing public URLs.

It is intentionally separate from:

- image processing;
- encoding;
- optimization;
- configuration resolution;
- Twig rendering;
- CLI orchestration;
- runtime signing.

The generation engine must never write directly to local disks or cloud SDKs outside a storage adapter.

---

## 1. Storage Principles

1. **Storage is adapter-based.**
2. **One Storage Manager coordinates adapters.**
3. **Remote storage does not require a permanent local image mirror.**
4. **Existence markers may live under private Craft `storage/`, never webroot.**
5. **Temporary processing files are not permanent derivatives.**
6. **Derivative paths are deterministic.**
7. **Storage credentials never enter generation identity.**
8. **Storage credentials never appear in logs or Twig output.**
9. **Frontend rendering must not depend on existence checks or markers.**
10. **Custom adapters must be registerable.**
11. **S3-compatible providers share one adapter family where practical.**

---

## 2. Role in the Pipeline

```text
Source
  ↓
Process
  ↓
Encode
  ↓
Optimize
  ↓
Validate
  ↓
Storage Adapter   ← this document
  ↓
GenerationResult (path + URL + metadata)
```

Storage begins only after output validation.

---

## 3. Storage Adapter Contract

Conceptually:

```php
interface StorageAdapterInterface
{
    public function name(): string;

    public function write(
        string $path,
        string $contents,
        StorageWriteOptions $options = new StorageWriteOptions(),
    ): StorageObject;

    public function writeFile(
        string $path,
        string $localFile,
        StorageWriteOptions $options = new StorageWriteOptions(),
    ): StorageObject;

    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function url(string $path): string;

    public function capabilities(): StorageCapabilities;
}
```

Exact signatures may evolve.

Required responsibilities remain:

- write derivative bytes/files;
- optionally check existence;
- delete when explicitly requested by later cleanup tools;
- return a public or configured URL;
- expose capabilities.

---

## 4. Storage Manager

```text
StorageManager
```

Responsibilities:

- resolve the effective storage configuration for a generation;
- select the adapter;
- normalize path prefixes;
- apply base URL / CDN URL rules;
- provide a single API to Generation Service;
- register custom adapters;
- expose diagnostics.

Generation Service should call StorageManager, not concrete adapters directly.

---

## 5. Initial Adapters

### 5.1 LocalStorageAdapter

Stores derivatives on the local filesystem.

Typical use:

- local development;
- traditional single-server Craft installs;
- local cache/CDN origin setups where files are served from disk.

Requirements:

- secure directory permissions;
- create parent directories as needed;
- atomic write where practical;
- path traversal protection;
- public URL generation via configured base URL.

### 5.2 S3CompatibleStorageAdapter

One S3-compatible adapter should cover:

```text
Amazon S3
DigitalOcean Spaces
Cloudflare R2
MinIO
Other S3-compatible providers
```

Provider-specific wrappers may exist later only when behavior cannot be expressed cleanly through generic S3 settings.

### 5.3 Custom adapters

Third-party adapters must be registerable through:

```text
StorageRegistry / StorageManager::register()
```

Examples:

- FTP/SFTP (if ever needed)
- Azure Blob
- Google Cloud Storage
- proprietary CDNs

---

## 6. S3-Compatible Configuration Model

Conceptual settings:

```php
'storage' => [
    'default' => 's3',

    'adapters' => [
        'local' => [
            'type' => 'local',
            'path' => '@webroot/super-images',
            'baseUrl' => '@web/super-images',
        ],

        's3' => [
            'type' => 's3',
            'keyId' => '$SUPER_IMAGES_S3_KEY_ID',
            'secret' => '$SUPER_IMAGES_S3_SECRET',
            'bucket' => 'my-bucket',
            'region' => 'us-east-1',
            'endpoint' => null,
            'pathStyleEndpoint' => false,
            'prefix' => 'derivatives/',
            'baseUrl' => 'https://cdn.example.com/',
            'acl' => null,
            'visibility' => 'public',
        ],

        'spaces' => [
            'type' => 's3',
            'bucket' => 'my-space',
            'region' => 'nyc3',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'baseUrl' => 'https://my-space.nyc3.cdn.digitaloceanspaces.com/',
        ],

        'r2' => [
            'type' => 's3',
            'bucket' => 'my-r2-bucket',
            'endpoint' => 'https://ACCOUNT_ID.r2.cloudflarestorage.com',
            'region' => 'auto',
            'baseUrl' => 'https://images.example.com/',
        ],
    ],
]
```

Notes:

- secrets must support environment-variable aliases;
- endpoint/path-style options enable Spaces/R2/MinIO;
- `baseUrl` is the delivery URL, which may differ from the API endpoint.

---

## 7. Delivery URL Layer

Storage write location and public delivery URL are related but not identical.

```text
API endpoint / filesystem root
        ≠
Public CDN / custom domain
```

Example:

```text
Write to: s3://bucket/derivatives/abc.webp
Public:   https://cdn.example.com/derivatives/abc.webp
```

StorageManager should support:

```text
baseUrl
cdnUrl / custom domain
path prefix
HTTPS enforcement where configured
```

Do not hard-code CloudFront/Cloudflare/Bunny providers if a generic base URL covers them.

---

## 8. Deterministic Derivative Paths

Derivative path generation is centralized.

Conceptual formula:

```text
prefix
+ profile/variant/format identity path
+ deterministic hash/identity
+ extension
```

Example:

```text
derivatives/a1/b2/a1b2c3d4e5-responsive-md.webp
```

Rules:

- same generation inputs → same path;
- meaningful config/processing changes → different path/identity;
- no random filenames for permanent derivatives;
- no timestamps in permanent derivative identity;
- path algorithm owned by GenerationIdentity / path service, not by each adapter.

Adapters store at the provided path.

They must not invent alternate naming schemes.

---

## 9. Generation Identity vs Storage Path

```text
GenerationIdentity
      ↓
StoragePathBuilder
      ↓
StorageAdapter::write(path, contents)
```

Identity may be a hash.

Path may include readable components for debugging, such as:

```text
assetId segment
profile
variant
format
hash
```

Readable components are optional and must not weaken determinism or security.

Never place secrets in paths.

---

## 10. No Permanent Local Image Mirror for Remote Storage

This is forbidden as a general architecture:

```text
Process
  ↓
Write permanent local image copy under webroot/cache
  ↓
Upload remote copy
  ↓
Use local image copy as source of truth
```

Required model:

```text
Process using temporary local files if needed
  ↓
Validate
  ↓
Write derivative bytes directly to configured storage (S3/Spaces/R2/local)
  ↓
Optionally write a tiny existence marker under private Craft storage/
  ↓
Delete temporary processing files
```

### Existence Marker Store

For CDN/remote storage cases, checking remote existence on every generation decision can be slow/expensive.

Super Images may therefore maintain **dummy/marker files** under Craft’s private storage folder:

```text
storage/super-images/markers/...
```

Marker rules:

- store markers only under Craft `storage/`, never under the public web folder;
- markers are tiny (empty file or small JSON metadata), not image binaries;
- marker path is derived from generation identity / deterministic derivative path;
- write marker after successful remote store;
- delete marker when cleanup deletes the remote object;
- generation/CLI/runtime may consult markers before calling remote `exists()`/HEAD;
- Twig HTML rendering must not read markers;
- markers must never be publicly URL-routable.

Conceptual helper:

```text
ExistenceMarkerStore
├── mark(identity/path)
├── has(identity/path)
└── clear(identity/path)
```

Remote adapter remains the authority for the actual public file.

Markers are an optimization/index for generation orchestration only.

---

## 11. Temporary Files

Temporary files are allowed for:

- remote Craft source assets that must be downloaded for processing;
- drivers/tools that require filesystem paths;
- external encoders/optimizers;
- atomic local writes.

Requirements:

- use secure temp directories;
- unique temp names;
- cleanup on success;
- cleanup on failure via finally/try resources;
- never expose temp paths publicly;
- never treat temp files as permanent derivatives;
- never leave orphaned large temps under normal operation.

A dedicated TemporaryFileManager is recommended.

---

## 12. Atomic Writes

Where practical:

### Local

```text
write temp file in target filesystem
  ↓
validate
  ↓
rename/move into final path
```

### Remote

```text
upload final validated bytes
```

or:

```text
upload to staging key
  ↓
validate remote object if needed
  ↓
move/copy to final key when supported
```

Do not publish a partial/corrupt object as the final public derivative.

---

## 13. Output Validation Before Storage

Before calling storage write, validate:

- non-empty bytes;
- expected format/extension consistency;
- readable image headers where practical;
- width/height present when expected;
- MIME type matches format policy.

Invalid output must fail before permanent storage.

---

## 14. Existence Checks

Storage adapters must support `exists()` because Phase 2 runtime generation and Phase 3 cleanup/diagnostics need it.

Recommended generation-time order for remote storage:

```text
1. check private existence marker under storage/
2. if missing, optionally check remote exists()
3. generate if still missing
4. store remote object
5. write/update marker
```

However:

```text
Normal Twig render
  ↓
NO exists()
NO marker reads
NO HEAD request
NO filesystem stat of derivative
```

Existence checks/markers are for:

- lazy generation decision paths;
- CLI skip-if-exists;
- cleanup tools;
- explicit admin operations.

They are not part of ordinary HTML rendering.

---

## 15. Deletes

Phase 1 must support delete at the adapter level.

Actual cleanup workflows belong primarily to Phase 3.

Delete requirements:

- delete by deterministic path;
- tolerate already-missing objects;
- never broad-delete by unverified prefix unless a later cleanup tool explicitly and safely does so;
- dry-run support will live in higher-level cleanup commands, not inside raw adapters.

---

## 16. Storage Capabilities

Each adapter exposes capabilities:

```text
StorageCapabilities
├── supportsExists
├── supportsDelete
├── supportsPublicUrl
├── supportsSignedUrl (optional/future)
├── supportsStreaming
├── maxObjectSize
└── provider notes
```

Capability detection helps diagnostics and Control Panel later.

---

## 17. Visibility / ACL / Cache Headers

Write options may include:

```text
visibility
contentType
cacheControl
contentDisposition
metadata
acl (provider-specific, avoided when possible)
```

Prefer modern bucket policies over legacy public-ACL dependence where providers allow it.

`Content-Type` must be set correctly for each format:

```text
image/jpeg
image/png
image/webp
image/avif
```

Cache-Control should be configurable, for example:

```text
public, max-age=31536000, immutable
```

Because derivative paths are content-addressed/deterministic, long cache lifetimes are often appropriate.

---

## 18. Configuration Precedence for Storage

Storage may be configured at multiple scopes:

```text
General storage
  ↓
Volume override
  ↓
Folder override
  ↓
Asset Field override
```

Most specific wins, through the same Configuration Resolver used elsewhere.

Example:

```text
General: local
Volume images: s3
Field heroImage: no override
→ hero images from that volume use s3
```

Generation Service must receive already-resolved storage configuration.

---

## 19. Interaction with Craft Volumes

Important distinction:

```text
Craft Volume storage
        ≠
Super Images derivative storage
```

Craft Volumes store original Assets.

Super Images stores generated derivatives.

They may coincidentally use the same provider type (both S3), but Super Images must not assume:

- derivative bucket == original bucket;
- derivative prefix == volume path;
- Craft filesystem API == Super Images storage adapter.

Super Images may optionally integrate with Craft filesystem abstractions later, but Phase 1 should keep a dedicated storage adapter layer for derivatives.

---

## 20. Source Files vs Derivative Files

Source handling belongs to Source Resolver.

Storage adapters in this document are for **derivatives**.

Do not conflate:

```text
SourceResolver temporary download of remote original
```

with:

```text
StorageAdapter permanent write of generated WebP/AVIF/JPEG
```

---

## 21. URL Generation Rules

`url(path)` must:

- apply configured base URL;
- normalize slashes;
- encode path segments safely;
- produce absolute URLs when configured to do so;
- avoid leaking endpoint credentials;
- avoid signed query tokens unless explicitly using a signed-delivery mode.

For frontend eager/lazy delivery after generation, prefer stable public/CDN URLs.

Signed runtime **generation** URLs are a Phase 2 concern and are not the same as storage object URLs.

---

## 22. Error Handling

Domain exceptions:

```text
StorageException
StorageWriteException
StorageNotFoundException
StorageConfigurationException
StoragePermissionException
```

Errors should include:

- adapter name;
- bucket/container name if safe;
- object path;
- sanitized provider error class/message;

Never include:

- secret keys;
- session tokens;
- full signed URLs with credentials;
- raw authorization headers.

---

## 23. Logging

Log useful events at appropriate levels:

- adapter selection;
- write failures;
- permission failures;
- endpoint misconfiguration;
- temp cleanup failures.

Do not log successful writes on every frontend-related path if that creates noise.

Never log secrets.

---

## 24. Security Rules

- prevent path traversal (`../`);
- reject absolute paths from untrusted input;
- treat object keys as untrusted when derived from runtime params;
- use least-privilege cloud credentials;
- support env-based secrets;
- do not embed secrets in project config exports if Craft/project practices discourage it;
- validate bucket/endpoint configuration early.

---

## 25. Performance Requirements

- stream or efficiently upload large outputs where practical;
- do not re-read remote objects after write just to build URLs;
- do not download remote derivatives during normal rendering;
- reuse client connections/SDK clients where safe;
- avoid per-request re-initialization of S3 clients when configuration is unchanged;
- batch existence checks only in CLI/diagnostics contexts, never in Twig render loops.

---

## 26. Testing Requirements

### Local adapter

- write
- exists
- delete
- url
- nested path creation
- path traversal rejection
- atomic write behavior
- temp cleanup

### S3-compatible adapter

- write with mocked S3 client or test container
- correct Content-Type
- prefix handling
- baseUrl handling
- Spaces-style endpoint config
- R2-style endpoint config
- failure handling
- exists/delete

### Manager

- adapter selection
- config resolution
- custom adapter registration
- deterministic path usage

### Pipeline integration

- validated output stored once
- remote write without permanent local mirror
- GenerationResult contains usable URL

---

## 27. Suggested Class Structure

```text
src/storage/
├── StorageAdapterInterface.php
├── StorageManager.php
├── StorageRegistry.php
├── StorageObject.php
├── StorageWriteOptions.php
├── StorageCapabilities.php
├── StoragePathBuilder.php
├── TemporaryFileManager.php
├── local/
│   └── LocalStorageAdapter.php
└── s3/
    ├── S3CompatibleStorageAdapter.php
    ├── S3ClientFactory.php
    └── S3WriteOptionsMapper.php
```

---

## 28. Phase 2 / Phase 3 Dependencies

Phase 2 will assume:

```text
StorageManager
deterministic paths
public/CDN URLs
exists() for lazy generation
```

Phase 3 will assume:

```text
delete()
diagnostics via capabilities
cleanup can target obsolete deterministic paths
storage settings UI binds to same config model
```

Do not create alternate storage systems for Playground permanent output.

Playground previews should use temporary/preview storage policies and must not pollute production derivative storage.

---

## 29. Architectural Invariants

1. Generation writes only through storage adapters.
2. No GeneratedImage DB table is used as storage index.
3. Remote derivatives do not require permanent local image mirrors.
4. Existence markers, if used, live only under private Craft `storage/`.
5. Markers are never placed in the web folder and are never public delivery files.
6. Temporary files are ephemeral.
7. Paths are deterministic and centrally calculated.
8. Credentials never enter identity/logs/URLs/markers.
9. Normal frontend rendering does not call exists() or read markers.
10. S3/Spaces/R2 share S3-compatible architecture where possible.
11. Custom adapters plug into the registry/manager.
12. Craft Volume storage remains separate from derivative storage.

---

## 30. Definition of Done

Storage is complete for Phase 1 when:

- [ ] StorageAdapterInterface exists
- [ ] StorageManager exists
- [ ] Local adapter works
- [ ] S3-compatible adapter works
- [ ] Amazon S3 can be configured
- [ ] DigitalOcean Spaces can be configured
- [ ] Cloudflare R2 can be configured
- [ ] Custom adapter registration path exists
- [ ] Deterministic paths work
- [ ] Base/CDN URLs work
- [ ] Temporary files are cleaned up
- [ ] Remote writes do not leave permanent local image mirrors
- [ ] Existence marker store works under private `storage/`
- [ ] Markers are never written under webroot
- [ ] exists/delete APIs exist for later phases
- [ ] Tests cover local + mocked S3-compatible behavior
- [ ] Pipeline can store a final derivative and return a URL

---

## Final Rule

**Storage is a delivery concern, not an image-processing concern.**

Process first. Validate second. Store once through an adapter. Serve through configured public/CDN URLs. Never make the database or a local mirror the source of truth for generated derivatives.
