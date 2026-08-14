<?php
/**
 * Normalized variant definition with shorthand-to-operation conversion.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Variant Definition
 *
 * Represents one named size/transform preset within a profile or standalone variants config.
 */
final class VariantDefinition
{
    /**
     * @param string $name Variant handle.
     * @param array<string, mixed> $options Raw variant options (width, height, mode, operations, etc.).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $options = [],
    ) {
    }

    /**
     * Parses a raw config array into a VariantDefinition.
     *
     * @param string $name Variant handle from config keys.
     * @param array<string, mixed> $config Raw variant block from plugin settings.
     *
     * @return self Variant with options passed through unchanged.
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self($name, $config);
    }

    /**
     * Converts variant options into an ordered list of OperationDefinition instances.
     *
     * Explicit `operations` arrays are parsed directly; otherwise width/height/mode shorthand
     * is mapped to resize, crop, fill, or scale operations.
     *
     * @return list<OperationDefinition> Transform pipeline for this variant.
     */
    public function toOperations(): array
    {
        if (isset($this->options['operations']) && is_array($this->options['operations'])) {
            return array_map(
                static fn(array $op) => OperationDefinition::fromArray($op),
                $this->options['operations'],
            );
        }

        $operations = [];

        if (isset($this->options['width']) || isset($this->options['height'])) {
            $mode = (string)($this->options['mode'] ?? 'fit');
            $type = match ($mode) {
                'crop' => 'crop',
                'fill' => 'fill',
                'scale' => 'scale',
                default => 'resize',
            };

            $operations[] = new OperationDefinition($type, array_filter([
                'width' => $this->options['width'] ?? null,
                'height' => $this->options['height'] ?? null,
                'mode' => $mode,
                'position' => $this->options['position'] ?? null,
            ], static fn(mixed $v) => $v !== null));
        }

        return $operations;
    }
}
