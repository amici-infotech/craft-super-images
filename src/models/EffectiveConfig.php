<?php

namespace amici\SuperImages\models;

final class EffectiveConfig
{
    /**
     * @param list<string> $formats
     * @param list<OperationDefinition> $operations
     * @param array<string, mixed> $encoderOptions
     * @param array<string, mixed> $optimizerOptions
     * @param array<string, mixed> $storageConfig
     * @param array<string, mixed> $runtime
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
    ) {
    }
}
