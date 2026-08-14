<?php
/**
 * Side-effect-free manifest entry mapping 1:1 to a generation request.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

use craft\elements\Asset;

/**
 * Manifest Unit
 *
 * Represents one planned derivative in a generation manifest before any encode or storage work runs.
 */
final readonly class ManifestUnit
{
    /**
     * @param int|null $assetId Craft asset element ID when the source is an asset.
     * @param string|null $localPath Absolute local path when the source is a filesystem file.
     * @param string|null $remoteUrl Remote URL when the source is fetched over HTTP(S).
     * @param string $profile Profile handle for this derivative.
     * @param string $variant Variant handle within the profile.
     * @param string $format Output format slug.
     * @param string $identity Stable hash for this derivative definition.
     * @param string $storagePath Relative path where the derivative will be stored.
     * @param string $publicUrl Public URL for the stored derivative.
     * @param string $driverName Image driver selected for this unit.
     */
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

    /**
     * Builds a manifest unit for a Craft asset source.
     *
     * @param Asset $asset Source asset element.
     * @param string $profile Profile handle.
     * @param string $variant Variant handle.
     * @param string $format Output format slug.
     * @param string $identity Stable derivative identity hash.
     * @param string $storagePath Relative storage path for the output file.
     * @param string $publicUrl Public URL for the stored derivative.
     * @param string $driverName Selected image driver name.
     *
     * @return self Manifest unit with assetId set and path/url sources null.
     */
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

    /**
     * Converts this manifest entry into a GenerationRequest for the generation pipeline.
     *
     * @return GenerationRequest Request DTO with source and target fields copied from this unit.
     */
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
