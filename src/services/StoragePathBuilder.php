<?php

namespace amici\SuperImages\services;

use yii\base\Component;

final class StoragePathBuilder extends Component
{
    /**
     * Build a deterministic storage-relative path.
     *
     * When `$namespace` is set (e.g. `preview/20260814`), the path becomes:
     * `{namespace}/{prefix}/{identity}/{profile}/{variant}.ext`
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
