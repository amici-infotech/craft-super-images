<?php
/**
 * Normalized profile definition parsed from plugin configuration.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Profile Definition
 *
 * Describes output formats, named variants, shared transforms, and default transform options for a profile.
 */
final class ProfileDefinition
{
    /**
     * @param string $name Profile handle.
     * @param list<string> $formats Output format slugs generated for each variant in this profile.
     * @param array<string, array<string, mixed>> $variants Named variant configs keyed by handle.
     * @param list<array<string, mixed>> $transforms Profile-level transforms applied before variant operations.
     * @param array<string, mixed> $defaults Default transform options merged into each variant.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $formats = [],
        public readonly array $variants = [],
        public readonly array $transforms = [],
        public readonly array $defaults = [],
    ) {
    }

    /**
     * Parses a raw config array into a normalized ProfileDefinition.
     *
     * @param string $name Profile handle from config keys.
     * @param array<string, mixed> $config Raw profile block from plugin settings.
     *
     * @return self Normalized profile with coerced formats, variants, transforms, and defaults.
     */
    public static function fromArray(string $name, array $config): self
    {
        $variants = [];
        if (isset($config['variants']) && is_array($config['variants'])) {
            foreach ($config['variants'] as $variantName => $variantConfig) {
                if (is_array($variantConfig)) {
                    $variants[(string) $variantName] = $variantConfig;
                }
            }
        }

        $transforms = [];
        if (isset($config['transforms']) && is_array($config['transforms'])) {
            foreach ($config['transforms'] as $transform) {
                if (is_array($transform)) {
                    $transforms[] = $transform;
                }
            }
        }

        return new self(
            $name,
            array_values(array_map('strval', $config['formats'] ?? [])),
            $variants,
            $transforms,
            is_array($config['defaults'] ?? null) ? $config['defaults'] : [],
        );
    }
}
