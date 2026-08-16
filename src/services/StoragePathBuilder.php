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
 * Renders storage paths from configurable naming templates.
 *
 * Default asset layout (settings-aware):
 *
 *     {folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}
 *
 * `{transformHash}` is derived from the generation identity (operations, encode
 * options, driver, …) so changing sepia threshold / crop size / etc. writes a
 * new path instead of reusing a stale cache file.
 *
 * Non-asset sources default to `{identityShard}/{basename}-{variant}.{ext}`.
 * Preview generations are prefixed with `{namespace}/` when the template omits it.
 */
final class StoragePathBuilder extends Component
{
    /** Default length of `{transformHash}` / `{identityShort}` segments. */
    public const DEFAULT_TRANSFORM_HASH_LENGTH = 16;

    /**
     * Default naming templates and options.
     *
     * @return array{
     *     assetPath: string,
     *     path: string,
     *     transformHashLength: int,
     *     includeVolumeInFolderHash: bool
     * }
     */
    public static function defaultNaming(): array
    {
        return [
            /**
             * Craft Asset originals.
             * {transformHash} changes whenever generation settings/ops change.
             */
            'assetPath' => '{folderHash}/{transformHash}/{assetId}/{basename}-{variant}.{ext}',
            /**
             * Local path / remote URL originals.
             */
            'path' => '{identityShard}/{basename}-{variant}.{ext}',
            /** Characters taken from the identity for {transformHash}/{identityShort}. */
            'transformHashLength' => self::DEFAULT_TRANSFORM_HASH_LENGTH,
            /** When true, {folderHash} input is /{volumeHandle}/{folderPath}/. */
            'includeVolumeInFolderHash' => false,
        ];
    }

    /**
     * Human-readable token glossary for CP / docs.
     *
     * @return list<array{token: string, description: string}>
     */
    public static function tokenGlossary(): array
    {
        return [
            ['token' => '{folderHash}', 'description' => 'MD5 of the Craft asset folder path (groups files by volume folder).'],
            ['token' => '{transformHash}', 'description' => 'First N chars of the generation identity — unique per ops/settings (default N=16).'],
            ['token' => '{transformFolderHash}', 'description' => 'MD5(folderPath + identity) — single folder hash that mixes Craft folder + settings.'],
            ['token' => '{identity}', 'description' => 'Full SHA-256 generation identity.'],
            ['token' => '{identityShort}', 'description' => 'Alias of {transformHash}.'],
            ['token' => '{identityShard}', 'description' => 'Two-level shard from identity: ab/cd.'],
            ['token' => '{assetId}', 'description' => 'Craft asset ID (asset sources only).'],
            ['token' => '{basename}', 'description' => 'Original filename without extension.'],
            ['token' => '{variant}', 'description' => 'Variant handle (md, lg, custom id, …).'],
            ['token' => '{profile}', 'description' => 'Profile handle (responsive, …).'],
            ['token' => '{format}', 'description' => 'Output format slug (webp, jpg, …).'],
            ['token' => '{ext}', 'description' => 'File extension (jpeg → jpg).'],
            ['token' => '{namespace}', 'description' => 'Optional prefix (e.g. preview/20260816).'],
            ['token' => '{volume}', 'description' => 'Volume handle when available.'],
        ];
    }

    /**
     * Build a deterministic storage-relative path.
     *
     * @param string $identity The SHA-256 generation identity hash.
     * @param string $format The output format (jpeg is stored as .jpg).
     * @param string|null $profile Optional profile handle.
     * @param string|null $variant Optional variant handle embedded in the filename.
     * @param string|null $namespace Optional namespace prefix (preview paths, etc.).
     * @param int|null $assetId Craft asset ID — when set, uses the assetPath template.
     * @param string|null $basename Original filename without extension (e.g. `hero`).
     * @param string|null $folderHash md5 hash of the asset folder path.
     * @param array<string, mixed>|null $naming Naming config from `storage.naming` (merged with defaults).
     * @param string|null $folderPath Raw Craft folder path (for {transformFolderHash}).
     * @param string|null $volumeHandle Volume handle for tokens / optional folder hash.
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
        ?array $naming = null,
        ?string $folderPath = null,
        ?string $volumeHandle = null,
    ): string {
        $naming = $this->resolveNaming($naming);
        $format = strtolower($format);
        $extension = $format === 'jpeg' ? 'jpg' : $format;

        $hashLength = max(8, min(64, (int)($naming['transformHashLength'] ?? self::DEFAULT_TRANSFORM_HASH_LENGTH)));
        $transformHash = substr($identity, 0, $hashLength);
        $safeBasename = $this->sanitizeBasename($basename, $identity);
        $safeVariant = $this->sanitizeSegment($variant);
        $safeProfile = $this->sanitizeSegment($profile);
        $safeNamespace = $this->normalizeNamespace($namespace);
        $safeVolume = $this->sanitizeSegment($volumeHandle !== null ? mb_strtolower($volumeHandle) : null);

        $resolvedFolderHash = $folderHash !== null && $folderHash !== ''
            ? $this->sanitizeSegment($folderHash)
            : substr($identity, 0, 32);

        $transformFolderHash = md5(
            ($folderPath !== null && $folderPath !== '' ? trim(str_replace('\\', '/', $folderPath), '/') : '')
            . '|'
            . $identity
        );

        $tokens = [
            'folderHash' => $resolvedFolderHash,
            'transformHash' => $transformHash,
            'transformFolderHash' => $transformFolderHash,
            'identity' => $identity,
            'identityShort' => $transformHash,
            'identityShard' => substr($identity, 0, 2) . '/' . substr($identity, 2, 2),
            'assetId' => ($assetId !== null && $assetId > 0) ? (string) $assetId : '',
            'basename' => $safeBasename,
            'variant' => $safeVariant,
            'profile' => $safeProfile,
            'format' => $extension === 'jpg' ? 'jpg' : $format,
            'ext' => $extension,
            'namespace' => $safeNamespace,
            'volume' => $safeVolume,
        ];

        $isAsset = $assetId !== null && $assetId > 0;
        $template = $isAsset
            ? (string)($naming['assetPath'] ?? self::defaultNaming()['assetPath'])
            : (string)($naming['path'] ?? self::defaultNaming()['path']);

        $path = $this->renderTemplate($template, $tokens);
        $path = $this->collapsePath($path);

        if ($safeNamespace !== '' && !str_starts_with($path, $safeNamespace . '/') && $path !== $safeNamespace) {
            $path = $safeNamespace . '/' . ltrim($path, '/');
        }

        if ($path === '' || str_ends_with($path, '/')) {
            $path = trim($path, '/') . '/' . $safeBasename
                . ($safeVariant !== '' ? '-' . $safeVariant : '')
                . '.' . $extension;
        }

        if (!str_contains(basename($path), '.')) {
            $path .= '.' . $extension;
        }

        return $path;
    }

    /**
     * Render an example path for CP previews.
     *
     * @param array<string, mixed>|null $naming Naming override.
     * @param bool $forAsset Whether to use the asset template.
     *
     * @return string Example relative path.
     */
    public function examplePath(?array $naming = null, bool $forAsset = true): string
    {
        $identity = str_repeat('a1b2c3d4', 8); // 64 hex chars

        return $this->build(
            identity: $identity,
            format: 'webp',
            profile: 'responsive',
            variant: 'md',
            namespace: null,
            assetId: $forAsset ? 184704 : null,
            basename: 'hero',
            folderHash: '41762720c56668e667b056cfce41e4c6',
            naming: $naming,
            folderPath: 'marketing/heroes',
            volumeHandle: 'images',
        );
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
     * Merge caller naming config with defaults.
     *
     * @param array<string, mixed>|null $naming Raw naming config.
     *
     * @return array<string, mixed>
     */
    public function resolveNaming(?array $naming): array
    {
        return array_merge(self::defaultNaming(), $naming ?? []);
    }

    /**
     * Replace `{tokens}` in a path template.
     *
     * @param string $template Path template.
     * @param array<string, string> $tokens Token map without braces.
     *
     * @return string
     */
    private function renderTemplate(string $template, array $tokens): string
    {
        $replacements = [];
        foreach ($tokens as $key => $value) {
            $replacements['{' . $key . '}'] = $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * Drop empty segments produced by blank optional tokens (e.g. missing variant).
     *
     * Also rewrites `basename-.ext` → `basename.ext` when variant is empty.
     *
     * @param string $path Rendered path.
     *
     * @return string
     */
    private function collapsePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        $path = trim($path, '/');

        // basename-{variant} with empty variant → basename-
        $path = preg_replace('#-(\.[A-Za-z0-9]+)$#', '$1', $path) ?? $path;
        // Drop empty path segments from blank tokens
        $parts = array_values(array_filter(explode('/', $path), static fn(string $p): bool => $p !== '' && $p !== '-'));

        return implode('/', $parts);
    }

    /**
     * @param string|null $namespace Raw namespace.
     *
     * @return string
     */
    private function normalizeNamespace(?string $namespace): string
    {
        if ($namespace === null || $namespace === '') {
            return '';
        }

        return trim(str_replace('\\', '/', $namespace), '/');
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
     * and a small set of filesystem-reserved symbols.
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
