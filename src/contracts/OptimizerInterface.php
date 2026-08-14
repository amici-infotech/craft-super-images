<?php
/**
 * Contract for post-encode lossless or lossy optimization of image bytes.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\EncodedImage;

/**
 * Optimizer Interface
 *
 * Wraps external tools (jpegoptim, oxipng, etc.) that shrink encoded output after the driver encodes.
 */
interface OptimizerInterface
{
    /**
     * Returns the optimizer identifier used in configuration and diagnostics.
     *
     * @return string Optimizer name (e.g. `jpegoptim`, `oxipng`).
     */
    public function name(): string;

    /**
     * Whether this optimizer can process the given output format.
     *
     * @param string $format Target format slug (e.g. `jpeg`, `webp`, `avif`).
     *
     * @return bool True when the optimizer accepts this format.
     */
    public function supports(string $format): bool;

    /**
     * Runs optimization on already-encoded image data and returns a new EncodedImage.
     *
     * @param EncodedImage $encoded Encoded image with bytes or a readable temp path.
     * @param string $format Output format slug being optimized.
     * @param array<string, mixed> $options Per-format optimizer settings from plugin config.
     *
     * @return EncodedImage Optimized image; may reuse dimensions/MIME with a smaller payload.
     */
    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage;
}
