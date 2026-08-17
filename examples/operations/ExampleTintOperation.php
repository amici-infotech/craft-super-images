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

final class ExampleTintOperation extends AbstractOperation
{
    public function name(): string
    {
        return 'tint';
    }

    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        // Duck-typed: custom drivers work if they expose the same method name.
        return $this->invokeDriver($driver, 'tint', $handle, [
            'color' => (string) ($this->options['color'] ?? '#000000'),
            'opacity' => (float) ($this->options['opacity'] ?? 0.25),
        ]);
    }
}
