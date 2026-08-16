<?php
/**
 * Text composition operation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations\composition;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\operations\AbstractOperation;

/**
 * Text Operation
 *
 * Draws text onto the base image (Imagick only).
 * Supported options: `content` or `text`, `font`, `size`, `color`, `position`,
 * `padding`, `opacity`, `angle` (degrees or `"diagonal"` for BL→TR), `cover`.
 */
final class Text extends AbstractOperation
{
    /**
     * Returns the operation identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return 'text';
    }

    /**
     * Applies text via the active driver.
     *
     * @param ImageHandle $handle The base image.
     * @param ImageDriverInterface $driver The active image driver.
     *
     * @return ImageHandle The image handle with text drawn.
     *
     * @throws UnsupportedOperationException When content is empty or the driver cannot draw text.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle
    {
        $content = (string) ($this->options['content'] ?? $this->options['text'] ?? '');
        if (trim($content) === '') {
            throw new UnsupportedOperationException('Text operation requires non-empty `content` (or `text`).');
        }

        return $this->invokeDriver($driver, 'text', $handle, $content, $this->options);
    }
}
