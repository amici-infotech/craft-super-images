<?php
/**
 * Reference custom operation — usable from Twig as { type: 'tint', color: '#ff0000', opacity: 0.2 }.
 *
 * Requires your image driver to implement tint() or map to an existing driver method.
 */

namespace myagency\superimages\examples\operations;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Example Tint Operation
 *
 * Applies a color overlay. Register via RegisterOperationsEvent and reference
 * from Twig `operations` arrays or profile definitions.
 */
final class ExampleTintOperation extends AbstractOperation
{
    /**
     * Returns the operation handle used in Twig and the operation registry.
     *
     * @return string
     */
    public function name(): string
    {
        return 'tint';
    }

    /**
     * Applies a tint via the active driver.
     *
     * Uses invokeDriver() so third-party drivers work when they expose tint()
     * with the same signature as the built-in drivers.
     *
     * Supported options:
     * - `color` — hex color string (default `#000000`)
     * - `opacity` — float 0–1 (default `0.25`)
     *
     * @param ImageHandle $handle The image to tint.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The tinted image handle.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        return $this->invokeDriver($driver, 'tint', $handle, [
            'color' => (string) ($this->options['color'] ?? '#000000'),
            'opacity' => (float) ($this->options['opacity'] ?? 0.25),
        ]);
    }
}
