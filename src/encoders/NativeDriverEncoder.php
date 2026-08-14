<?php
/**
 * Encoder that delegates output to the active image driver's native encode implementation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\encoders;

use amici\SuperImages\contracts\EncoderInterface;
use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;

/**
 * Native Driver Encoder
 *
 * Wraps driver-specific encoding so the pipeline can treat all backends uniformly.
 */
final class NativeDriverEncoder implements EncoderInterface
{
    /**
     * Returns the encoder identifier used in configuration and logging.
     *
     * @return string Always "native".
     */
    public function name(): string
    {
        return 'native';
    }

    /**
     * Returns the output formats this encoder can produce.
     *
     * @return list<string> Supported format slugs (jpeg, png, webp, avif, etc.).
     */
    public function formats(): array
    {
        return ['jpeg', 'jpg', 'png', 'webp', 'avif'];
    }

    /**
     * Checks whether the encoder can produce the requested format.
     *
     * @param string $format Target format slug (case-insensitive).
     *
     * @return bool True when the format is in {@see formats()}.
     */
    public function supports(string $format): bool
    {
        return in_array(strtolower($format), $this->formats(), true);
    }

    /**
     * Encodes an image handle using the supplied driver's native encoder.
     *
     * @param ImageHandle $handle The in-memory image to encode.
     * @param string $format Target output format slug.
     * @param EncodeOptions $options Quality, metadata stripping, and other encode settings.
     * @param ImageDriverInterface $driver The driver that owns the handle resource.
     *
     * @return EncodedImage The encoded bytes and metadata.
     */
    public function encode(ImageHandle $handle, string $format, EncodeOptions $options, ImageDriverInterface $driver): EncodedImage
    {
        return $driver->encodeNative($handle, $format, $options);
    }
}
