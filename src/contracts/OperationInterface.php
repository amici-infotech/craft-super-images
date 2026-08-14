<?php
/**
 * Contract for a single image transform applied during generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\ImageHandle;

/**
 * Operation Interface
 *
 * Represents one transform step (resize, crop, fill, etc.) executed by an image driver.
 */
interface OperationInterface
{
    /**
     * Returns the operation slug used in identity hashing and driver capability checks.
     *
     * @return string Operation name (e.g. `resize`, `crop`, `scale`).
     */
    public function name(): string;

    /**
     * Returns normalized options that define this operation (width, height, mode, etc.).
     *
     * @return array<string, mixed> Option key/value pairs included in the generation identity.
     */
    public function options(): array;

    /**
     * Whether the given driver can apply this operation to the current handle.
     *
     * @param ImageHandle $handle Loaded image with current dimensions and alpha state.
     * @param ImageDriverInterface $driver Candidate driver for execution.
     *
     * @return bool True when the operation is supported for this handle/driver pair.
     */
    public function supports(ImageHandle $handle, ImageDriverInterface $driver): bool;

    /**
     * Applies the operation via the driver and returns the transformed handle.
     *
     * @param ImageHandle $handle Loaded image to transform.
     * @param ImageDriverInterface $driver Driver that executes the operation.
     *
     * @return ImageHandle Handle after the transform, possibly with new dimensions.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle;
}
