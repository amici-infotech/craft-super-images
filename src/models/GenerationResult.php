<?php
/**
 * Outcome of a completed image generation run.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Generation Result
 *
 * Captures success state, storage location, delivery URL, output metadata, and optional diagnostics.
 */
final class GenerationResult
{
    /**
     * @param bool $success Whether encoding, optimization, and storage completed without error.
     * @param string $identity Stable hash identifying this derivative definition.
     * @param string $storagePath Relative path where the derivative was written.
     * @param string $url Public or delivery URL for the generated file.
     * @param string $format Output format slug of the stored file.
     * @param int $width Final image width in pixels.
     * @param int $height Final image height in pixels.
     * @param int $size File size in bytes.
     * @param string $mime MIME type of the stored file.
     * @param float $durationMs Wall-clock generation time in milliseconds.
     * @param array<string, mixed> $diagnostics Driver, optimizer, and timing details for debugging.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $identity,
        public readonly string $storagePath,
        public readonly string $url,
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size,
        public readonly string $mime,
        public readonly float $durationMs = 0.0,
        public readonly array $diagnostics = [],
    ) {
    }
}
