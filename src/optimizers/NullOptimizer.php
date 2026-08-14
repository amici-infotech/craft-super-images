<?php
/**
 * No-op optimizer that passes encoded images through unchanged.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\optimizers;

use amici\SuperImages\contracts\OptimizerInterface;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\support\ProcessRunner;
use amici\SuperImages\support\TemporaryFileManager;

/**
 * Null Optimizer
 *
 * Default fallback when external binary optimizers are disabled or unavailable.
 */
final class NullOptimizer implements OptimizerInterface
{
    /**
     * Returns the optimizer identifier used in configuration and logging.
     *
     * @return string Always "null".
     */
    public function name(): string
    {
        return 'null';
    }

    /**
     * Indicates support for all formats because no optimization is performed.
     *
     * @param string $format Target format slug (unused).
     *
     * @return bool Always true.
     */
    public function supports(string $format): bool
    {
        return true;
    }

    /**
     * Returns the encoded image unchanged.
     *
     * @param EncodedImage $encoded The image produced by the encoder stage.
     * @param string $format Target format slug (unused).
     * @param array<string, mixed> $options Optimizer options (ignored).
     *
     * @return EncodedImage The same encoded image instance.
     */
    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage
    {
        return $encoded;
    }
}
