<?php
/**
 * Normalized profile definition.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

final class ProfileDefinition
{
    /**
     * @param list<string> $formats
     * @param array<string, array<string, mixed>> $variants
     * @param list<array<string, mixed>> $transforms
     * @param array<string, mixed> $defaults
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
     * @param array<string, mixed> $config
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
