<?php
/**
 * Registry of image transformation operations.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\OperationInterface;
use amici\SuperImages\events\RegisterOperationsEvent;
use amici\SuperImages\exceptions\InvalidOperationException;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\operations\color\Brightness;
use amici\SuperImages\operations\color\Contrast;
use amici\SuperImages\operations\color\Grayscale;
use amici\SuperImages\operations\color\Invert;
use amici\SuperImages\operations\color\Saturation;
use amici\SuperImages\operations\color\Sepia;
use amici\SuperImages\operations\composition\Background;
use amici\SuperImages\operations\composition\Border;
use amici\SuperImages\operations\composition\Overlay;
use amici\SuperImages\operations\composition\Padding;
use amici\SuperImages\operations\composition\Watermark;
use amici\SuperImages\operations\effects\Blur;
use amici\SuperImages\operations\effects\Sharpen;
use amici\SuperImages\operations\geometry\Crop;
use amici\SuperImages\operations\geometry\Fill;
use amici\SuperImages\operations\geometry\Fit;
use amici\SuperImages\operations\geometry\Flip;
use amici\SuperImages\operations\geometry\Resize;
use amici\SuperImages\operations\geometry\Rotate;
use amici\SuperImages\operations\geometry\Scale;
use yii\base\Component;

/**
 * Operation Registry
 *
 * Maps operation type names to implementation classes and instantiates operations
 * from OperationDefinition payloads. Supports plugin extension via register event.
 */
class OperationRegistry extends Component
{
    /**
     * Event fired after built-in operations are registered so plugins can add custom types.
     */
    public const EVENT_REGISTER_OPERATIONS = 'registerOperations';

    /**
     * Map of lowercase operation name to implementation class.
     *
     * @var array<string, class-string<OperationInterface>>
     */
    private array $_map = [];

    /**
     * Register built-in operations and trigger the register event for extensions.
     *
     * @return void
     */
    public function registerDefaults(): void
    {
        $this->register('resize', Resize::class);
        $this->register('crop', Crop::class);
        $this->register('fit', Fit::class);
        $this->register('fill', Fill::class);
        $this->register('scale', Scale::class);
        $this->register('rotate', Rotate::class);
        $this->register('flip', Flip::class);
        $this->register('brightness', Brightness::class);
        $this->register('contrast', Contrast::class);
        $this->register('saturation', Saturation::class);
        $this->register('grayscale', Grayscale::class);
        $this->register('sepia', Sepia::class);
        $this->register('invert', Invert::class);
        $this->register('sharpen', Sharpen::class);
        $this->register('blur', Blur::class);
        $this->register('background', Background::class);
        $this->register('padding', Padding::class);
        $this->register('border', Border::class);
        $this->register('watermark', Watermark::class);
        $this->register('overlay', Overlay::class);

        $event = new RegisterOperationsEvent();
        $this->trigger(self::EVENT_REGISTER_OPERATIONS, $event);

        foreach ($event->operations as $name => $class) {
            $this->register($name, $class);
        }
    }

    /**
     * Register an operation implementation class under a type name.
     *
     * @param string $name Operation type name (case-insensitive).
     * @param class-string<OperationInterface> $class Fully qualified operation class name.
     *
     * @return void
     */
    public function register(string $name, string $class): void
    {
        $this->_map[strtolower($name)] = $class;
    }

    /**
     * Instantiate an operation from a definition.
     *
     * @param OperationDefinition $definition The operation type and options payload.
     *
     * @return OperationInterface The operation instance ready to apply to an image handle.
     *
     * @throws InvalidOperationException When the operation type is not registered.
     */
    public function create(OperationDefinition $definition): OperationInterface
    {
        $name = strtolower($definition->type);
        $class = $this->_map[$name] ?? null;

        if ($class === null) {
            throw new InvalidOperationException(sprintf('Unknown operation "%s".', $definition->type));
        }

        return new $class($definition->options);
    }

    /**
     * List all registered operation type names.
     *
     * @return list<string> Lowercase operation names.
     */
    public function names(): array
    {
        return array_keys($this->_map);
    }
}
