<?php

namespace amici\SuperImages\encoders;

use amici\SuperImages\contracts\EncoderInterface;
use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;

final class NativeDriverEncoder implements EncoderInterface
{
    public function name(): string
    {
        return 'native';
    }

    public function formats(): array
    {
        return ['jpeg', 'jpg', 'png', 'webp', 'avif'];
    }

    public function supports(string $format): bool
    {
        return in_array(strtolower($format), $this->formats(), true);
    }

    public function encode(ImageHandle $handle, string $format, EncodeOptions $options, ImageDriverInterface $driver): EncodedImage
    {
        return $driver->encodeNative($handle, $format, $options);
    }
}
