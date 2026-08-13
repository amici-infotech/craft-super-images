<?php

namespace amici\SuperImages\models;

final class OperationDefinition
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $type,
        public readonly array $options = [],
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition): self
    {
        $type = (string)($definition['type'] ?? $definition['name'] ?? $definition['operation'] ?? '');
        unset($definition['type'], $definition['name'], $definition['operation']);

        return new self($type, self::normalizeOptions($definition));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function normalizeOptions(array $options): array
    {
        ksort($options);

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(['type' => $this->type], $this->options);
    }
}
