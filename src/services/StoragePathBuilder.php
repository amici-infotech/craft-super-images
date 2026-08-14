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
 */
final class StoragePathBuilder extends Component
{
    /**
     * Build a deterministic storage-relative path.
     *
     * Default layout: `{prefix}/{identity}/{profile}/{variant}.ext`
     * With namespace (e.g. `preview/20260814`): `{namespace}/{prefix}/{identity}/{profile}/{variant}.ext`
     *
     * @param string $identity The SHA-256 generation identity hash.
     * @param string $format The output format (jpeg is stored as .jpg).
     * @param string|null $profile Optional profile segment.
     * @param string|null $variant Optional variant segment.
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
        $prefix = substr($identity, 0, 2);
        $segments = [];

        if ($namespace !== null && $namespace !== '') {
            $namespace = trim(str_replace('\\', '/', $namespace), '/');
            if ($namespace !== '') {
                $segments[] = $namespace;
            }
        }

        $segments[] = $prefix;
        $segments[] = $identity;

        if ($profile !== null && $profile !== '') {
            $segments[] = $profile;
        }

        if ($variant !== null && $variant !== '') {
            $segments[] = $variant;
        }

        return implode('/', $segments) . '.' . $extension;
    }
}
