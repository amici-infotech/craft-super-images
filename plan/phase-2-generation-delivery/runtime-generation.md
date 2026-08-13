# Phase 2 — Runtime Generation

## Purpose

Runtime (lazy) generation creates a derivative when it is requested, using a **signed** URL endpoint.

It exists for cases where eager generation is impractical or incomplete.

It must remain secure, limited, and based on the same Phase 1 Generation Service.

---

## 1. Why Runtime Exists

Eager generation is ideal, but real sites often need:

- first request after deploy before CLI has run;
- rarely used variants;
- editorial preview flexibility;
- gradual backfill.

Runtime generation fills those gaps without making Twig itself process images.

---

## 2. Core Flow

```text
Twig renders signed runtime URL
        ↓
Browser requests runtime endpoint
        ↓
Validate signature + expiry + limits + source allow-lists
        ↓
Build GenerationRequest / ManifestUnit
        ↓
Acquire lock for identity
        ↓
marker/exists? yes → redirect/serve final URL
        ↓
generate via GenerationService
        ↓
store via StorageAdapter
        ↓
write private existence marker under storage/ (for remote/CDN)
        ↓
release lock
        ↓
redirect/serve final storage/CDN URL
```

After generation, subsequent requests should preferably use the final storage/CDN URL rather than endlessly depending on the runtime endpoint.

---

## 3. Signed URL Requirements

Runtime URLs must be signed.

Signature must cover security-sensitive fields such as:

```text
asset identity
profile / variant / recipe identity
format
normalized transform options
quality/encoder options where relevant
expiry timestamp
nonce/version if required
```

Recommended properties:

- HMAC signature with server-side secret;
- expiry window;
- constant-time signature comparison;
- reject tampered parameter sets;
- reject expired URLs.

Never accept an unsigned arbitrary transform API in production.

---

## 4. What Runtime May Accept

Allow:

- known Asset IDs;
- local paths inside configured allow-listed roots;
- remote/CDN URLs whose hosts are allow-listed and SSRF-safe;
- known profiles/variants;
- allow-listed formats;
- allow-listed operations/options within resource limits;
- signed custom transforms that pass validation.

Deny:

- arbitrary filesystem paths outside allow-listed roots;
- arbitrary remote URLs / hosts outside allow-lists;
- private-network targets after DNS resolution;
- unrestricted width/height;
- unrestricted operation lists;
- user-supplied shell arguments;
- user-supplied optimizer binaries;
- untrusted watermark remote sources.

---

## 5. Resource Limits

Hard limits must be enforced server-side even if signature is valid from an older client bug.

Examples:

```text
maxWidth
maxHeight
maxPixels
maxInputBytes
maxOperations
maxQuality ranges
allowedFormats
allowedOperations
maxProcessingSeconds
```

Exceeding limits must fail closed.

---

## 6. Deterministic Identity Still Applies

Runtime generation must produce the same identity/path as eager generation for the same inputs.

```text
eager(asset, profile, variant, format, options)
        =
lazy(asset, profile, variant, format, options)
```

If they diverge, caches fragment and cleanup becomes impossible.

---

## 7. Concurrency / Single-Flight Locking

Stampeding requests for a missing derivative must not generate N times.

Conceptual approach:

```text
lockKey = generation identity
acquire lock (cache/mutex/db lock)
  if exists -> return final URL
  else generate
release lock
```

Requirements:

- lock per identity, not global;
- TTL/expiry to survive worker crashes;
- waiters either wait or retry briefly;
- avoid deadlocks;
- do not hold locks while doing unrelated slow work beyond generation.

Craft cache, Redis, or another suitable lock backend may be used.

---

## 8. Response Strategy

Preferred:

```text
302/301/307 to final storage/CDN URL
```

or:

```text
stream final bytes with correct headers
```

Redirect is often better for CDN offload after first generation.

For first generation latency, streaming may be acceptable, but final public URL should still be the long-term delivery path.

Do not leave browsers permanently pinned to the signed runtime endpoint if a stable public URL exists.

---

## 9. Twig Relationship

Twig may output:

1. **Final deterministic public URL** when eager mode/config says derivatives are expected at storage URLs;
2. **Signed runtime URL** when lazy mode is enabled for that context.

Twig must not:

- call exists();
- read existence markers;
- generate images;
- encode images;
- talk to S3 SDKs.

Choosing between public URL and signed runtime URL is a configuration/delivery-mode decision, ideally computed cheaply from config, not from storage I/O.

---

## 10. Mode Configuration

Conceptual modes:

```text
eager
lazy
hybrid
```

Examples:

- `eager`: Twig emits final URLs; CLI/queue populate them;
- `lazy`: Twig emits signed runtime URLs;
- `hybrid`: Twig emits final URLs by default, but may fall back to signed URLs for explicitly lazy profiles.

Exact naming can evolve. Behavior must remain explicit and documented.

---

## 11. Controller / Action Design

Implement as a Craft controller action under the plugin.

Responsibilities:

- parse request;
- validate signature/expiry;
- validate limits;
- resolve Asset;
- build request;
- lock;
- generate if needed;
- respond;
- log failures safely.

No business logic duplication of Phase 1 pipeline.

---

## 12. Caching Headers

Runtime responses should set appropriate headers:

- short-lived for errors;
- long-lived for redirects to immutable derivative paths when safe;
- correct content-type when streaming.

Because deterministic paths can be immutable, CDN caching of final objects should be aggressive.

---

## 13. Failure Behavior

If generation fails:

- return a controlled error response;
- do not leak secrets/paths;
- log diagnostics;
- release locks;
- clean temp files.

Optional fallback to original Asset URL may be configurable, but must be explicit and not hide systemic failures forever.

---

## 14. Security Invariants

1. No unsigned transform endpoint.
2. No arbitrary source paths/URLs from requestors.
3. Signature covers all security-sensitive params.
4. Limits enforced server-side.
5. Secrets never appear in logs.
6. Runtime cannot bypass allow-lists via option smuggling.
7. External tools only through Phase 1 ProcessRunner.

---

## 15. Performance Notes

- first-hit latency can be high (especially AVIF);
- prefer queue backfill for popular sets;
- use locks to prevent CPU stampedes;
- after generation, serve via CDN URL;
- avoid session/auth requirements for public derivatives unless intentionally private.

Private/signed asset delivery is a separate concern from signed generation URLs.

---

## 16. Testing Requirements

- valid signature succeeds
- tampered params fail
- expired signature fails
- oversize dimensions fail
- disallowed format fails
- concurrent requests generate once
- same identity as eager path
- redirect/stream behavior
- temp cleanup on failure
- no secret leakage in responses

---

## 17. Definition of Done

- [ ] Signed URL service exists
- [ ] Runtime action exists
- [ ] Resource limits enforced
- [ ] Locking prevents duplicate generation
- [ ] Uses GenerationService only
- [ ] Final CDN/storage URL used after generation
- [ ] Eager/lazy identity parity verified
- [ ] Security tests pass

---

## Final Rule

**Lazy generation is a controlled convenience, not an open image API.**

If a request cannot be proven intentional via signature and limits, refuse it.
