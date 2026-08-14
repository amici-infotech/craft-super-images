<?php
/**
 * Options controlling how an image handle is encoded to an output format.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Encode Options
 *
 * Quality, metadata stripping, and format-specific extras included in generation identity hashing.
 */
final class EncodeOptions
{
    /**
     * @param int|null $quality Output quality (format-dependent); null uses encoder defaults.
     * @param bool $stripMetadata Whether EXIF and other metadata should be removed on encode.
     * @param array<string, mixed> $extra Format-specific encoder options merged into driver calls.
     */
    public function __construct(
        public readonly ?int $quality = null,
        public readonly bool $stripMetadata = true,
        public readonly array $extra = [],
    ) {
    }

    /**
     * Returns the configured quality or a caller-supplied default when quality is null.
     *
     * @param int $default Fallback quality when `$quality` is not set.
     *
     * @return int Effective quality value for encoding.
     */
    public function qualityOrDefault(int $default): int
    {
        return $this->quality ?? $default;
    }

    /**
     * Serializes encode options into a stable array for identity hashing.
     *
     * @return array<string, mixed> Quality, stripMetadata, and extra options.
     */
    public function toIdentityArray(): array
    {
        return [
            'quality' => $this->quality,
            'stripMetadata' => $this->stripMetadata,
            'extra' => $this->extra,
        ];
    }
}
