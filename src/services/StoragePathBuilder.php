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
 * Constructs sharded, deterministic storage paths from generation identity,
 * profile, variant, format, and optional namespace (e.g. preview dates).
 *
 * Layout is deliberately flat: two short shard directories (4 hex chars total,
 * up to 65,536 buckets) hold the derivative file directly — there is no
 * per-derivative directory. Profile and variant are embedded in the filename
 * for readability; they are not needed for uniqueness since the identity hash
 * already encodes profile, variant, format, and every operation/encoder option.
 */
final class StoragePathBuilder extends Component
{
    /**
     * Build a deterministic storage-relative path.
     *
     * Default layout: `{shard1}/{shard2}/{identity}--{profile}-{variant}.ext`
     * With namespace (e.g. `preview/20260814`): `{namespace}/{shard1}/{shard2}/{identity}--{profile}-{variant}.ext`
     *
     * @param string $identity The SHA-256 generation identity hash.
     * @param string $format The output format (jpeg is stored as .jpg).
     * @param string|null $profile Optional profile segment embedded in the filename.
     * @param string|null $variant Optional variant segment embedded in the filename.
     * @param string|null $namespace Optional namespace prefix (preview paths, etc.).
     *
     * @return string Storage-relative path including file extension.
     */
    public function build(
        string $identity,
        string $format,
        ?string $profile = null,
        ?string $variant = null,
        ?string $namespace = null,
    ): string {
        $format = strtolower($format);
        $extension = $format === 'jpeg' ? 'jpg' : $format;

        $shard1 = substr($identity, 0, 2);
        $shard2 = substr($identity, 2, 2);

        $segments = [];

        if ($namespace !== null && $namespace !== '') {
            $namespace = trim(str_replace('\\', '/', $namespace), '/');
            if ($namespace !== '') {
                $segments[] = $namespace;
            }
        }

        $segments[] = $shard1;
        $segments[] = $shard2;

        $suffixParts = array_filter([$profile, $variant], static fn(?string $part): bool => $part !== null && $part !== '');
        $filename = $suffixParts !== []
            ? $identity . '--' . implode('-', $suffixParts)
            : $identity;

        return implode('/', $segments) . '/' . $filename . '.' . $extension;
    }
}
