# Phase 3 — Cleanup & Diagnostics

## Purpose

Define safe cleanup tooling and operational diagnostics for Super Images.

Because there is **no GeneratedImage database table**, cleanup and status must be conservative and explicit.

---

## 1. Goals

- help operators understand system health;
- remove obsolete/abandoned derivatives carefully;
- support dry-run;
- avoid deleting files merely because they cannot be proven used this second;
- never become a foot-gun for production CDN contents.

---

## 2. Diagnostics Surfaces

### CLI

```bash
php craft super-images/status
php craft super-images/config --asset=123
php craft super-images/doctor
```

### Control Panel

Diagnostics section showing:

- drivers/encoders/optimizers availability;
- storage connectivity;
- queue health;
- recent sanitized errors;
- schema/config version.

---

## 3. Doctor Command

`doctor` should run safe checks:

```text
PHP extensions
Libvips/Imagick/GD availability
format encode capability
optimizer binaries
storage config present
temp dir writable
signature secret configured for lazy mode
resource limits configured
```

Output clear PASS/WARN/FAIL items.

Doctor must not require full derivative scans.

---

## 4. Cleanup Philosophy

Cleanup is conservative by default.

May remove:

- derivatives for deleted Assets (when deterministically discoverable);
- derivatives from obsolete identities after retention window;
- abandoned Playground/preview artifacts;
- explicitly selected obsolete prefixes after confirmation.

Must not remove:

- files just because `exists` mapping is incomplete;
- unknown files outside known Super Images prefixes;
- originals in Craft Volumes;
- objects not matching Super Images path patterns.

---

## 5. Cleanup Command

Conceptual:

```bash
php craft super-images/cleanup
php craft super-images/cleanup --dry-run
php craft super-images/cleanup --previews-only
php craft super-images/cleanup --older-than=30d
php craft super-images/cleanup --force   # still requires safe guards
```

Default should be dry-run friendly and retention-aware.

---

## 6. How Cleanup Can Work Without a DB

Possible strategies:

### A. Deterministic expected set vs scanned prefix

```text
for assets in scope:
  build expected identities/paths from current manifests
scan storage prefix
candidates = scanned - expected
apply retention policy
delete candidates
```

This can be expensive and must be batched/opt-in.

### B. Preview namespace cleanup

Playground/preview prefixes can be expired by age safely.

### C. Explicit obsolete schema version prefixes

If path strategy includes schema version segments, old versions can be targeted.

Always prefer dry-run first.

---

## 7. Retention Policy

Example config:

```php
'cleanup' => [
    'previewRetentionDays' => 2,
    'obsoleteRetentionDays' => 30,
    'allowRemoteScan' => false,
]
```

Remote full scans should be off by default because of API cost.

---

## 8. Status Without False Precision

Avoid dashboards that pretend:

```text
Processed: 11,934
Pending: 548
```

unless those numbers are grounded in real queue state or an explicit scan.

Acceptable:

```text
Queue jobs pending: 548
Queue jobs failed: 12
Last generate CLI run: ...
Storage default: s3
Doctor: 1 warning
```

Scan-derived derivative counts must be labeled as approximate/expensive.

---

## 9. Error Diagnostics

Maintain sanitized recent failure records if useful (cache/log-based), including:

- asset id;
- profile/variant/format;
- exception class;
- message;
- timestamp;

Never store secrets/signed URLs/credentials in diagnostics payloads.

---

## 10. Safety Rails

- permissions required;
- dry-run default recommendation;
- confirmation for destructive remote deletes;
- prefix allow-list;
- rate-limited scans;
- audit log of cleanup actions.

---

## 11. Testing Requirements

- doctor checks
- dry-run cleanup lists candidates only
- preview cleanup deletes expired previews
- refuses paths outside managed prefix
- no deletion of Craft originals
- diagnostics omit secrets

---

## 12. Definition of Done

- [ ] status/doctor commands work
- [ ] cleanup supports dry-run
- [ ] preview cleanup works
- [ ] obsolete cleanup is retention-aware and conservative
- [ ] CP diagnostics reflect capability services
- [ ] no GeneratedImage table introduced for cleanup bookkeeping as source of truth

---

## Final Rule

**When unsure whether a derivative is obsolete, keep it until policy and evidence say otherwise.**

Cleanup should be dull and safe, not clever and destructive.
