<?php
/**
 * Builds deterministic storage-relative paths for generated derivatives.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use yii\base\Component;

/**
 * Storage Path Builder
 *
 * Constructs readable, folder-grouped storage paths:
 *
 *     {folderHash}/{assetId}/{basename}-{variant}.{ext}
 *
 * Example:
 *
 *     41762720c56668e667b056cfce41e4c6/184704/hero-md.webp
 *
 * Non-asset sources fall back to `{identityPrefix}/{basename}-{variant}.{ext}`.
 * Preview generations are prefixed with `preview/{YYYYMMDD}/`.
 */
final class StoragePathBuilder extends Component
{
    /**
     * Build a deterministic storage-relative path.
     *
     * Asset layout: `{folderHash}/{assetId}/{basename}-{variant}.{ext}`
     * Other sources: `{identity[0:2]}/{identity[2:4]}/{basename}-{variant}.{ext}`
     * With namespace: `{namespace}/…` (e.g. `preview/20260814/…`)
     *
     * @param string $identity The SHA-256 generation identity hash (used for non-asset sharding / fallback names).
     * @param string $format The output format (jpeg is stored as .jpg).
     * @param string|null $profile Optional profile handle (unused in path; kept for API stability).
     * @param string|null $variant Optional variant handle embedded in the filename.
     * @param string|null $namespace Optional namespace prefix (preview paths, etc.).
     * @param int|null $assetId Craft asset ID — when set, becomes the second path segment.
     * @param string|null $basename Original filename without extension (e.g. `hero`).
     * @param string|null $folderHash md5 hash of the asset folder path (first path segment).
     *
     * @return string Storage-relative path including file extension.
     */
    public function build(
        string $identity,
        string $format,
        ?string $profile = null,
        ?string $variant = null,
        ?string $namespace = null,
        ?int $assetId = null,
        ?string $basename = null,
        ?string $folderHash = null,
    ): string {
        unset($profile); // Reserved for future path patterns; identity already encodes profile.

        $format = strtolower($format);
        $extension = $format === 'jpeg' ? 'jpg' : $format;

        $segments = [];

        if ($namespace !== null && $namespace !== '') {
            $namespace = trim(str_replace('\\', '/', $namespace), '/');
            if ($namespace !== '') {
                $segments[] = $namespace;
            }
        }

        $safeBasename = $this->sanitizeBasename($basename, $identity);
        $safeVariant = $this->sanitizeSegment($variant);

        if ($assetId !== null && $assetId > 0) {
            $hash = $folderHash !== null && $folderHash !== ''
                ? $this->sanitizeSegment($folderHash)
                : substr($identity, 0, 32);
            $segments[] = $hash;
            $segments[] = (string) $assetId;
        } else {
            $segments[] = substr($identity, 0, 2);
            $segments[] = substr($identity, 2, 2);
        }

        $filename = $safeVariant !== ''
            ? $safeBasename . '-' . $safeVariant
            : $safeBasename;

        return implode('/', $segments) . '/' . $filename . '.' . $extension;
    }

    /**
     * Build a stable folder hash from a volume folder path.
     *
     * Uses `md5('/' . folderPath)` so all assets in the same folder share a prefix.
     *
     * @param string $folderPath Asset folder path relative to the volume (may be empty).
     * @param string|null $volumeHandle Optional volume handle when volume should be part of the hash.
     * @param bool $includeVolume When true, prefixes the hash input with the volume handle.
     *
     * @return string 32-character md5 hex digest.
     */
    public function folderHash(string $folderPath, ?string $volumeHandle = null, bool $includeVolume = false): string
    {
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        $input = '/';

        if ($includeVolume && $volumeHandle !== null && $volumeHandle !== '') {
            $input .= mb_strtolower($volumeHandle) . '/';
        }

        if ($folderPath !== '') {
            $input .= $folderPath . '/';
        }

        return md5($input);
    }

    /**
     * Sanitize a basename for use in storage filenames.
     *
     * @param string|null $basename Preferred basename from the source file.
     * @param string $identity Fallback identity used when basename is empty.
     *
     * @return string Safe basename segment.
     */
    private function sanitizeBasename(?string $basename, string $identity): string
    {
        $basename = $this->sanitizeSegment($basename);

        if ($basename === '') {
            return substr($identity, 0, 12);
        }

        return $basename;
    }

    /**
     * Strip characters that are unsafe in storage path segments.
     *
     * Preserves Unicode letters and numbers (Hebrew, Cyrillic, CJK, …) so original
     * asset basenames survive. Only removes path separators, control characters,
     and a small set of filesystem-reserved symbols.
     *
     * @param string|null $value Raw segment value.
     *
     * @return string Sanitized segment, or empty string when null/blank.
     */
    private function sanitizeSegment(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Normalize separators first so they never become directory breaks.
        $value = str_replace(['\\', '/'], '-', $value);

        // Drop C0/C1 controls and DEL.
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        // Filesystem-reserved / URL-awkward symbols → hyphen. Keep letters from
        // every script (\p{L}), marks (\p{M}), numbers (\p{N}), and . _ -
        $value = preg_replace(
            '/[^\p{L}\p{M}\p{N}._-]+/u',
            '-',
            $value,
        ) ?? '';

        // Collapse repeated hyphens produced by replacements.
        $value = preg_replace('/-+/', '-', $value) ?? '';
        $value = trim($value, '.-');

        return $value;
    }
}
