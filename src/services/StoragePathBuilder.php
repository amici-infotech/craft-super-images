<?php

namespace amici\SuperImages\services;

use yii\base\Component;

final class StoragePathBuilder extends Component
{
    public function build(string $identity, string $format, ?string $profile = null, ?string $variant = null): string
    {
        $format = strtolower($format);
        $extension = $format === 'jpeg' ? 'jpg' : $format;
        $prefix = substr($identity, 0, 2);
        $segments = array_filter([$prefix, $identity]);

        if ($profile !== null && $profile !== '') {
            $segments[] = $profile;
        }

        if ($variant !== null && $variant !== '') {
            $segments[] = $variant;
        }

        return implode('/', $segments) . '.' . $extension;
    }
}
