<?php
/**
 * Base class for image transformation operations.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\contracts\OperationInterface;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\OperationDefinition;

/**
 * Abstract Operation
 *
 * Provides shared option handling and driver support checks for concrete image operations.
 * Subclasses must implement {@see name()} and {@see apply()}.
 */
abstract class AbstractOperation implements OperationInterface
{
    /** @var array<string, mixed> Normalized operation options from the definition. */
    protected array $options;

    /**
     * Creates an operation instance with normalized options.
     *
     * @param array<string, mixed> $options Raw operation options keyed by parameter name.
     */
    public function __construct(array $options = [])
    {
        $this->options = OperationDefinition::normalizeOptions($options);
    }

    /**
     * Returns the normalized options passed to this operation.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * Determines whether the given driver can perform this operation.
     *
     * @param ImageHandle $handle The current image handle (available for context; unused by default).
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return bool True when the driver reports support for this operation name.
     */
    public function supports(ImageHandle $handle, ImageDriverInterface $driver): bool
    {
        return $driver->supports($this->name());
    }

    /**
     * Resolves target width/height from options, deriving any missing dimension
     * proportionally from the source aspect ratio instead of defaulting to the
     * full source size.
     *
     * When both `width` and `height` are given, both are returned as-is. When
     * only one is given, the other is calculated to preserve the source aspect
     * ratio (so a width-only crop/fill behaves like a proportional resize
     * instead of slicing a thin strip out of the full-height source). When
     * neither is given, the full source dimensions are returned.
     *
     * @param ImageHandle $handle The source image handle supplying current dimensions.
     *
     * @return array{0: int, 1: int} Tuple of [width, height], each at least 1.
     */
    protected function resolveDimensions(ImageHandle $handle): array
    {
        $width = isset($this->options['width']) ? (int)$this->options['width'] : null;
        $height = isset($this->options['height']) ? (int)$this->options['height'] : null;

        if ($width === null && $height === null) {
            return [$handle->width, $handle->height];
        }

        if ($width === null) {
            $width = (int)round($handle->width * ($height / max(1, $handle->height)));
        }

        if ($height === null) {
            $height = (int)round($handle->height * ($width / max(1, $handle->width)));
        }

        return [max(1, $width), max(1, $height)];
    }

    /**
     * Returns the canonical operation name used in definitions and the registry.
     *
     * @return string The operation identifier (e.g. "resize", "crop").
     */
    abstract public function name(): string;

    /**
     * Applies the transformation to the image handle using the given driver.
     *
     * @param ImageHandle $handle The image to transform.
     * @param ImageDriverInterface $driver The driver that performs the low-level work.
     *
     * @return ImageHandle The transformed image handle.
     */
    abstract public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle;

    /**
     * Call a driver transform method when present (built-in or third-party).
     *
     * Prefer this over hard-coding instanceof lists so registered custom drivers work
     * as long as they implement the same method signature as the built-ins.
     *
     * @param ImageDriverInterface $driver Active driver.
     * @param string $method Driver method name (e.g. `resize`, `grayscale`).
     * @param ImageHandle $handle Current image handle.
     * @param mixed ...$args Additional method arguments after the handle.
     *
     * @return ImageHandle Transformed handle.
     *
     * @throws UnsupportedOperationException When the driver does not expose the method.
     */
    protected function invokeDriver(
        ImageDriverInterface $driver,
        string $method,
        ImageHandle $handle,
        mixed ...$args,
    ): ImageHandle {
        if (!is_callable([$driver, $method])) {
            throw new UnsupportedOperationException(sprintf(
                'Operation "%s" requires driver method %s(); "%s" does not implement it.',
                $this->name(),
                $method,
                $driver->name(),
            ));
        }

        /** @var ImageHandle $result */
        $result = $driver->{$method}($handle, ...$args);

        return $result;
    }
}
