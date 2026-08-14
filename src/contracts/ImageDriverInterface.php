<?php
/**
 * Contract for loading, transforming, and encoding raster images via a backend driver.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\Dimensions;
use amici\SuperImages\models\DriverCapabilities;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\SourceImage;

/**
 * Image Driver Interface
 *
 * Abstracts libvips, Imagick, or GD for load → transform → encode workflows.
 */
interface ImageDriverInterface
{
    /**
     * Returns the driver identifier used in configuration and diagnostics.
     *
     * @return string Driver name (e.g. `libvips`, `imagick`, `gd`).
     */
    public function name(): string;

    /**
     * Whether the driver extension/binary is installed and usable in this environment.
     *
     * @return bool True when the driver can be selected for generation.
     */
    public function isAvailable(): bool;

    /**
     * Loads a source image into a driver-specific handle.
     *
     * @param SourceImage $source Resolved source with path, bytes, or asset metadata.
     *
     * @return ImageHandle In-memory image resource with dimensions and alpha flag.
     */
    public function load(SourceImage $source): ImageHandle;

    /**
     * Applies a transform operation to the handle and returns the updated handle.
     *
     * @param ImageHandle $handle Current in-memory image.
     * @param OperationInterface $operation Transform to apply (resize, crop, etc.).
     *
     * @return ImageHandle Handle after the operation, possibly with new dimensions.
     */
    public function apply(ImageHandle $handle, OperationInterface $operation): ImageHandle;

    /**
     * Reads the current pixel dimensions from a loaded handle.
     *
     * @param ImageHandle $handle In-memory image resource.
     *
     * @return Dimensions Width and height in pixels.
     */
    public function dimensions(ImageHandle $handle): Dimensions;

    /**
     * Whether this driver implements the given operation type.
     *
     * @param string $operation Operation slug (e.g. `resize`, `crop`, `watermark`).
     *
     * @return bool True when the driver can apply this operation.
     */
    public function supports(string $operation): bool;

    /**
     * Describes supported operations, formats, alpha, and watermark support.
     *
     * @return DriverCapabilities Capability flags and supported feature lists.
     */
    public function capabilities(): DriverCapabilities;

    /**
     * Encodes the handle using the driver's built-in encoder for the given format.
     *
     * @param ImageHandle $handle In-memory image resource.
     * @param string $format Target output format slug.
     * @param EncodeOptions $options Quality, metadata stripping, and format-specific extras.
     *
     * @return EncodedImage Encoded bytes or temp file path with dimensions and MIME metadata.
     */
    public function encodeNative(ImageHandle $handle, string $format, EncodeOptions $options): EncodedImage;

    /**
     * Releases driver-specific resources held by the handle.
     *
     * @param ImageHandle $handle In-memory image resource to destroy.
     */
    public function destroy(ImageHandle $handle): void;
}
