<?php
/**
 * Declarative transform step parsed from variant or profile configuration.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Operation Definition
 *
 * Immutable description of one transform (type + options) used in generation identity and driver dispatch.
 */
final class OperationDefinition
{
    /**
     * @param string $type Operation slug (e.g. `resize`, `crop`, `fill`, `scale`).
     * @param array<string, mixed> $options Transform parameters (width, height, mode, position, etc.).
     */
    public function __construct(
        public readonly string $type,
        public readonly array $options = [],
    ) {
    }

    /**
     * Parses a config array into an OperationDefinition.
     *
     * Accepts `type`, `name`, or `operation` as the type key; remaining keys become options.
     *
     * @param array<string, mixed> $definition Raw operation block from config or overrides.
     *
     * @return self Normalized operation with sorted options.
     */
    public static function fromArray(array $definition): self
    {
        $type = (string)($definition['type'] ?? $definition['name'] ?? $definition['operation'] ?? '');
        unset($definition['type'], $definition['name'], $definition['operation']);

        return new self($type, self::normalizeOptions($definition));
    }

    /**
     * Sorts option keys for stable identity hashing and comparison.
     *
     * @param array<string, mixed> $options Raw option key/value pairs.
     *
     * @return array<string, mixed> Options with keys sorted alphabetically.
     */
    public static function normalizeOptions(array $options): array
    {
        ksort($options);

        return $options;
    }

    /**
     * Serializes the operation into a config-compatible array.
     *
     * @return array<string, mixed> Array with `type` plus all option keys.
     */
    public function toArray(): array
    {
        return array_merge(['type' => $this->type], $this->options);
    }
}
