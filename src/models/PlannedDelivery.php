<?php

namespace amici\SuperImages\models;

/**
 * Planned delivery metadata for Twig / URL composition (no I/O).
 */
final readonly class PlannedDelivery
{
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
