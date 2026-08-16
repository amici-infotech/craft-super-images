<?php
/**
 * Watermark composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\WatermarkSourceException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Watermark Operation
 *
 * Composites a watermark onto the base image. Prefer an image source (`path` /
 * `sourcePath`), or pass `text` / `content` for a text watermark (Imagick only).
 * Supported options: `sourcePath` or `path`, `text` or `content`, `position`
 * (default: "bottom-right"), `opacity` (default: 0.5), `cover`, `color`, `size`,
 * `font`, `angle` (`"diagonal"` or degrees).
 * Supported drivers: Imagick only.
 */
final class Watermark extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'watermark';
    }

    /**
     * Applies the watermark via the active driver.
     *
     * @param ImageHandle $handle The base image.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The watermarked image handle.
     *
     * @throws WatermarkSourceException When neither a readable image path nor text is provided.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $sourcePath = (string) ($this->options['sourcePath'] ?? $this->options['path'] ?? '');
        $text = (string) ($this->options['text'] ?? $this->options['content'] ?? '');
        $hasPath = $sourcePath !== '' && is_readable($sourcePath);
        $hasText = trim($text) !== '';

        if (!$hasPath && !$hasText) {
            throw new WatermarkSourceException(
                'Watermark requires a readable image `path`/`sourcePath`, or non-empty `text`/`content`.',
            );
        }

        if ($hasText && !$hasPath) {
            return $this->invokeDriver($driver, 'text', $handle, $text, $this->options);
        }

        $position = (string) ($this->options['position'] ?? 'bottom-right');
        $opacity = (float) ($this->options['opacity'] ?? 0.5);
        $cover = filter_var($this->options['cover'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $this->invokeDriver($driver, 'watermark', $handle, $sourcePath, $position, $opacity, $cover);
    }
}
