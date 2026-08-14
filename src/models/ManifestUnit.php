<?php

namespace amici\SuperImages\models;

use craft\elements\Asset;

/**
 * Side-effect-free manifest unit — maps 1:1 to a GenerationRequest.
 */
final readonly class ManifestUnit
{
    public function __construct(
        public ?int $assetId,
        public ?string $localPath,
        public ?string $remoteUrl,
        public string $profile,
        public string $variant,
        public string $format,
        public string $identity,
        public string $storagePath,
        public string $publicUrl,
        public string $driverName,
    ) {
    }

    public static function fromAsset(
        Asset $asset,
        string $profile,
        string $variant,
        string $format,
        string $identity,
        string $storagePath,
        string $publicUrl,
        string $driverName,
    ): self {
        return new self(
            assetId: (int) $asset->id,
            localPath: null,
            remoteUrl: null,
            profile: $profile,
            variant: $variant,
            format: $format,
            identity: $identity,
            storagePath: $storagePath,
            publicUrl: $publicUrl,
            driverName: $driverName,
        );
    }

    public function toGenerationRequest(): GenerationRequest
    {
        return new GenerationRequest(
            assetId: $this->assetId,
            localPath: $this->localPath,
            remoteUrl: $this->remoteUrl,
            profile: $this->profile,
            variant: $this->variant,
            format: $this->format,
        );
    }
}
