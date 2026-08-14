<?php
/**
 * Fully resolved configuration for a single generation run.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Effective Config
 *
 * Merges plugin defaults with profile, variant, volume, folder, and field overrides into one immutable snapshot.
 */
final class EffectiveConfig
{
    /**
     * @param string $driver Selected image driver name.
     * @param string $profile Resolved profile handle.
     * @param string $variant Resolved variant handle.
     * @param string $format Target output format slug.
     * @param list<string> $formats All format slugs in the profile (for manifest expansion).
     * @param list<OperationDefinition> $operations Merged transform pipeline for this generation.
     * @param array<string, mixed> $encoderOptions Per-format encoder settings (quality, etc.).
     * @param array<string, mixed> $optimizerOptions Per-format optimizer tool configuration.
     * @param string $storageAdapter Storage adapter handle to persist the derivative.
     * @param array<string, mixed> $storageConfig Resolved adapter configuration block.
     * @param array<string, mixed> $runtime Signed runtime URL settings and limits.
     * @param bool $optimizersEnabled Whether post-encode optimizers should run.
     * @param bool $allowUpscale Whether geometry operations may enlarge beyond source dimensions.
     * @param int $maxSourcePixels Maximum source width×height allowed after load.
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $profile,
        public readonly string $variant,
        public readonly string $format,
        public readonly array $formats,
        public readonly array $operations,
        public readonly array $encoderOptions,
        public readonly array $optimizerOptions,
        public readonly string $storageAdapter,
        public readonly array $storageConfig,
        public readonly array $runtime,
        public readonly bool $optimizersEnabled = true,
        public readonly bool $allowUpscale = true,
        public readonly int $maxSourcePixels = 40_000_000,
    ) {
    }
}
