<?php

namespace amici\SuperImages\models;

final class VariantDefinition
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly array $options = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self($name, $config);
    }

    /**
     * @return list<OperationDefinition>
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
