<?php

namespace amici\SuperImages\models;

final class EncodeOptions
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly ?int $quality = null,
        public readonly bool $stripMetadata = true,
        public readonly array $extra = [],
    ) {
    }

    public function qualityOrDefault(int $default): int
    {
        return $this->quality ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toIdentityArray(): array
    {
        return [
            'quality' => $this->quality,
            'stripMetadata' => $this->stripMetadata,
            'extra' => $this->extra,
        ];
    }
}
