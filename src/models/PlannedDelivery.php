<?php
/**
 * Planned delivery metadata for Twig and URL composition without storage I/O.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Planned Delivery
 *
 * Describes where a derivative would be stored and how its URL would be emitted for a given delivery mode.
 */
final readonly class PlannedDelivery
{
    /**
     * @param string $identity Stable hash identifying this profile/variant/format/transform combination.
     * @param string $storagePath Relative path where the derivative would be written.
     * @param string $storageUrl Public URL from the storage adapter for the stored file.
     * @param string $deliveryUrl URL emitted to templates (may be signed runtime URL in lazy mode).
     * @param string $mode Delivery mode active when planned (`eager`, `lazy`, or `hybrid`).
     * @param string $profile Profile handle for this derivative.
     * @param string $variant Variant handle within the profile.
     * @param string $format Output format slug.
     * @param int|null $widthHint Expected output width when known without generating.
     * @param int|null $heightHint Expected output height when known without generating.
     */
    public function __construct(
        public string $identity,
        public string $storagePath,
        public string $storageUrl,
        public string $deliveryUrl,
        public string $mode,
        public string $profile,
        public string $variant,
        public string $format,
        public ?int $widthHint = null,
        public ?int $heightHint = null,
    ) {
    }
}
