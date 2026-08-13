<?php

namespace amici\SuperImages\models;

final class GenerationDefinition
{
    /**
     * @param list<OperationDefinition> $operations
     */
    public function __construct(
        public readonly string $sourceIdentity,
        public readonly string $profile,
        public readonly string $variant,
        public readonly string $format,
        public readonly array $operations,
        public readonly EncodeOptions $encodeOptions,
        public readonly array $optimizerOptions,
        public readonly string $driverPreference,
        public readonly string $storageAdapter,
        public readonly int $schemaVersion,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toIdentityPayload(): array
    {
        return [
            'sourceIdentity' => $this->sourceIdentity,
            'profile' => $this->profile,
            'variant' => $this->variant,
            'format' => $this->format,
            'operations' => array_map(static fn(OperationDefinition $op) => $op->toArray(), $this->operations),
            'encodeOptions' => $this->encodeOptions->toIdentityArray(),
            'optimizerOptions' => $this->optimizerOptions,
            'driverPreference' => $this->driverPreference,
            'schemaVersion' => $this->schemaVersion,
        ];
    }
}
