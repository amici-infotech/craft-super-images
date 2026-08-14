<?php
/**
 * Contract for encoding in-memory image handles to output file formats.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;

/**
 * Encoder Interface
 *
 * Formats a loaded ImageHandle into EncodedImage bytes, delegating driver-specific work when needed.
 */
interface EncoderInterface
{
    /**
     * Returns the encoder identifier used in configuration and diagnostics.
     *
     * @return string Encoder name (e.g. `native`, `imagick`).
     */
    public function name(): string;

    /**
     * Lists output format slugs this encoder can produce.
     *
     * @return list<string> Supported format identifiers (e.g. `webp`, `avif`).
     */
    public function formats(): array;

    /**
     * Whether this encoder can produce the given output format.
     *
     * @param string $format Target format slug.
     *
     * @return bool True when the encoder accepts this format.
     */
    public function supports(string $format): bool;

    /**
     * Encodes the handle to the requested format using the supplied driver.
     *
     * @param ImageHandle $handle Loaded image resource from an ImageDriverInterface.
     * @param string $format Target output format slug.
     * @param EncodeOptions $options Quality, metadata stripping, and format-specific extras.
     * @param ImageDriverInterface $driver Driver used for native encode fallback when required.
     *
     * @return EncodedImage Encoded bytes or temp file path with dimensions and MIME metadata.
     */
    public function encode(ImageHandle $handle, string $format, EncodeOptions $options, ImageDriverInterface $driver): EncodedImage;
}
