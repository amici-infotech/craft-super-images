<?php

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;

interface EncoderInterface
{
    public function name(): string;

    /**
     * @return list<string>
     */
    public function formats(): array;

    public function supports(string $format): bool;

    public function encode(ImageHandle $handle, string $format, EncodeOptions $options, ImageDriverInterface $driver): EncodedImage;
}
