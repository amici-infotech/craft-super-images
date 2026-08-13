# Super Images — Security

This document lists security precautions for Super Images.

It is cross-cutting. Phase 1–3 implementations must satisfy these rules unless a documented exception is approved.

Related docs:

- `README.md` (core decisions)
- `phase-2-generation-delivery/runtime-generation.md`
- `phase-1-core-engine/storage.md`
- `phase-1-core-engine/configuration.md`

---

## 1. Threat model (summary)

Super Images will process images from:

- Craft Assets
- local paths such as `/images/abc.png`
- remote/CDN URLs

and may expose:

- Twig helpers
- CLI commands
- queue workers
- signed runtime generation endpoints
- Control Panel / Playground

Primary risks:

- arbitrary file read via local paths
- SSRF via remote URL fetch
- unrestricted image bomb / huge pixel DoS
- unsigned open transform API abuse
- shell injection through optimizer/encoder binaries
- secret leakage in logs/URLs/identity
- public exposure of private marker/temp files
- cache poisoning / identity confusion across sources

---

## 2. Source security

### 2.1 Craft Assets

- resolve only through Craft Asset APIs;
- never accept raw filesystem paths that bypass Craft volume permissions for Asset sources;
- respect Craft volume access rules in CP/Playground contexts.

### 2.2 Local paths

Examples:

```text
/images/abc.png
@webroot/images/abc.png
```

Rules:

- allow only paths inside configured allow-listed roots;
- resolve and canonicalize paths before use;
- reject `..` / path traversal after normalization;
- reject symlinks that escape allow-listed roots when detectable;
- reject paths under private Craft `storage/` unless explicitly allow-listed for trusted internal use;
- never allow local-path sources to read `.env`, config, or credential files.

Suggested config:

```php
'sources' => [
    'local' => [
        'enabled' => true,
        'allowedRoots' => [
            '@webroot/images',
            '@webroot/uploads',
        ],
    ],
],
```

### 2.3 Remote / CDN URLs

Rules:

- enable remote sources only when configured;
- allow only configured hosts / host patterns;
- allow only `http`/`https`;
- block private/link-local/loopback IP ranges after DNS resolution (SSRF protection);
- enforce timeouts, max redirect count, and max download bytes;
- validate Content-Type / image headers before processing;
- do not follow redirects to disallowed hosts;
- do not fetch credentials from user-supplied URLs into logs.

Suggested config:

```php
'sources' => [
    'remote' => [
        'enabled' => true,
        'allowedHosts' => [
            'cdn.example.com',
            '*.imgix.net',
        ],
        'timeout' => 10,
        'maxBytes' => 25_000_000,
        'maxRedirects' => 3,
    ],
],
```

---

## 3. Runtime generation security

- all runtime transform URLs must be signed;
- signature covers source identity + transform + format + expiry + relevant options;
- constant-time signature compare;
- expired URLs fail closed;
- tampered params fail closed;
- enforce server-side resource limits even when signature is valid;
- no unsigned “open transform” endpoint in production;
- do not accept arbitrary class names, adapter names, binary names, or filesystem targets from request input.

Resource limits should include:

```text
maxWidth
maxHeight
maxPixels
maxInputBytes
maxOperations
allowedFormats
allowedOperations
maxProcessingSeconds
```

---

## 4. Process / binary security

- all external binaries run only through ProcessRunner;
- no `shell_exec` / string-built shells from user input;
- argument arrays only;
- allow-listed binaries/paths from config/capability detection;
- timeouts and output size caps;
- never pass raw user strings as shell fragments.

---

## 5. Secrets and credentials

Must use environment variables / Craft env aliases where practical:

```text
S3 key/secret
Spaces/R2 credentials
runtime signing secret
optional webhook tokens
```

Never:

- commit secrets to git;
- put secrets into generation identity;
- print secrets in CLI/CP/Twig/diagnostics;
- include secrets in marker files;
- expose secrets in error messages.

---

## 6. Storage and marker security

- public derivatives are served from configured public/CDN URLs only;
- existence markers live under private Craft `storage/super-images/...`;
- markers must never be web-routable;
- temp processing files must never be web-routable;
- prevent path traversal in object keys and local marker paths;
- use least-privilege cloud credentials (write/delete only required prefix);
- validate bucket/endpoint configuration early.

---

## 7. Automatic queue generation safety

Asset upload/replace auto-queue must:

- run asynchronously (queue), not encode huge AVIF sets in the upload request;
- honor enable/disable config switches;
- support bulk-import mute/disable;
- only generate for configured volumes/fields/profiles;
- be idempotent and retry-safe;
- not escalate permissions beyond Craft’s normal queue worker context.

---

## 8. Twig / frontend safety

- escape HTML attributes in tag builders;
- do not render secrets;
- do not accept untrusted remote hosts outside allow-lists;
- do not perform exists/marker/HEAD checks during normal render;
- signed URLs may appear in `src`/`srcset` only when lazy mode requires them.

---

## 9. Control Panel / Playground safety

- permission-gate settings, playground, diagnostics, cleanup;
- Playground previews use isolated temp/preview storage;
- connection tests must not echo secrets;
- destructive cleanup requires explicit confirmation / dry-run support.

---

## 10. Denial-of-service precautions

Protect against:

- image bombs (very large dimensions);
- extremely large source downloads;
- stampeding lazy generation for one identity (single-flight locks);
- unbounded queue fan-out on bulk asset imports;
- pathological operation chains.

Fail closed with clear errors when limits are exceeded.

---

## 11. Logging precautions

Safe to log:

- asset id / source type
- profile/variant/format
- generation identity
- adapter name
- sanitized exception class/message

Never log:

- storage secrets
- signing secrets
- full signed URLs with signatures if avoidable in verbose logs
- authorization headers
- raw .env values

---

## 12. Implementation checklist

- [ ] Local path allow-list + canonicalization
- [ ] Remote host allow-list + SSRF protections
- [ ] Signed runtime URLs
- [ ] Resource limits enforced server-side
- [ ] ProcessRunner-only binary execution
- [ ] Secrets via env, never in identity/logs
- [ ] Markers only under private `storage/`
- [ ] No webroot image mirrors for remote derivatives
- [ ] Auto-queue configurable and non-blocking
- [ ] HTML escaping in tag builders
- [ ] CP permissions for sensitive actions
- [ ] Cleanup dry-run + prefix guards

---

## 13. When adding a new feature

Ask:

1. Can untrusted input reach filesystem, network fetch, or shell?
2. Does this affect generation identity?
3. Can this create a public DoS path?
4. Could secrets leak into URLs/logs/markers?
5. Does Twig remain free of existence I/O and processing?

If any answer is risky, update this document and the relevant phase module before coding.
