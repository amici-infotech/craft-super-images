<?php

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
 */
class OperationRegistry extends Component
{
    public const EVENT_REGISTER_OPERATIONS = 'registerOperations';

    /** @var array<string, class-string<OperationInterface>> */
    private array $_map = [];

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
     * @param class-string<OperationInterface> $class
     */
    public function register(string $name, string $class): void
    {
        $this->_map[strtolower($name)] = $class;
    }

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
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->_map);
    }
}
