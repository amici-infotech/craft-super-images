<?php
/**
 * Input DTO describing a single image generation job.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

use craft\base\FieldInterface;
use craft\models\Volume;
use craft\models\VolumeFolder;

/**
 * Generation Request
 *
 * Captures source location, target profile/variant/format, and optional Craft context overrides.
 */
final class GenerationRequest
{
    /**
     * @param int|null $assetId Craft asset element ID when generating from an uploaded file.
     * @param string|null $localPath Absolute filesystem path for local-source generation.
     * @param string|null $remoteUrl Remote HTTP(S) URL for remote-source generation.
     * @param string|null $profile Profile handle; falls back to plugin default when null.
     * @param string|null $variant Variant handle within the profile.
     * @param string|null $format Output format slug; falls back to plugin default when null.
     * @param list<OperationDefinition>|null $operationOverrides Explicit transform pipeline replacing variant defaults.
     * @param array<string, mixed> $encodeOverrides Merged into resolved encoder options (e.g. thumbnail quality).
     * @param bool|null $optimizersEnabled Override post-encode optimizers; null keeps settings default.
     * @param Volume|null $volume Volume context for volume-scoped config overrides.
     * @param VolumeFolder|null $folder Folder context for folder-scoped config overrides.
     * @param FieldInterface|null $field Field context for field-scoped config overrides.
     * @param string|null $storageAdapter Storage adapter handle override.
     * @param bool $preview When true, derivatives are stored under a preview/ namespace (Playground).
     */
    public function __construct(
        public readonly ?int $assetId = null,
        public readonly ?string $localPath = null,
        public readonly ?string $remoteUrl = null,
        public readonly ?string $profile = null,
        public readonly ?string $variant = null,
        public readonly ?string $format = null,
        public readonly ?array $operationOverrides = null,
        public readonly array $encodeOverrides = [],
        public readonly ?bool $optimizersEnabled = null,
        public readonly ?Volume $volume = null,
        public readonly ?VolumeFolder $folder = null,
        public readonly ?FieldInterface $field = null,
        public readonly ?string $storageAdapter = null,
        public readonly bool $preview = false,
    ) {
    }

    /**
     * Counts how many mutually exclusive source identifiers are set on this request.
     *
     * Used to validate that exactly one of asset, local path, or remote URL is provided.
     *
     * @return int Number of non-null source fields (0–3).
     */
    public function sourceCount(): int
    {
        return (int)($this->assetId !== null)
            + (int)($this->localPath !== null)
            + (int)($this->remoteUrl !== null);
    }
}
