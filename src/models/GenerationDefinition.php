<?php
/**
 * Immutable definition of everything that contributes to a derivative identity hash.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Generation Definition
 *
 * Bundles source identity, transform pipeline, encode/optimizer options, and schema version for cache keys.
 */
final class GenerationDefinition
{
    /**
     * @param string $sourceIdentity Stable hash of the source image (asset, path, or URL).
     * @param string $profile Profile handle.
     * @param string $variant Variant handle.
     * @param string $format Output format slug.
     * @param list<OperationDefinition> $operations Ordered transform pipeline.
     * @param EncodeOptions $encodeOptions Quality and metadata options for encoding.
     * @param array<string, mixed> $optimizerOptions Per-format optimizer configuration.
     * @param string $driverPreference Configured driver preference (`auto`, `libvips`, etc.).
     * @param string $storageAdapter Storage adapter handle.
     * @param int $schemaVersion Settings schema version included in the identity payload.
     * @param SharpnessSettings $sharpness Downscale sharpness included in the identity payload.
     */
    public function __construct(
        public readonly string $sourceIdentity,
        public readonly string $profile,
        public readonly string $variant,
        public readonly string $format,
        public readonly array $operations,
        public readonly EncodeOptions $encodeOptions,
        public readonly array $optimizerOptions,
        public readonly string $driverPreference,
        public readonly string $storageAdapter,
        public readonly int $schemaVersion,
        public readonly SharpnessSettings $sharpness,
    ) {
    }

    /**
     * Serializes all identity-affecting fields into a stable array for hashing.
     *
     * @return array<string, mixed> Payload used to compute the derivative identity hash.
     */
    public function toIdentityPayload(): array
    {
        return [
            'sourceIdentity' => $this->sourceIdentity,
            'profile' => $this->profile,
            'variant' => $this->variant,
            'format' => $this->format,
            'operations' => array_map(static fn(OperationDefinition $op) => $op->toArray(), $this->operations),
            'encodeOptions' => $this->encodeOptions->toIdentityArray(),
            'optimizerOptions' => $this->optimizerOptions,
            'driverPreference' => $this->driverPreference,
            'schemaVersion' => $this->schemaVersion,
            'sharpness' => $this->sharpness->toIdentityArray(),
        ];
    }
}
