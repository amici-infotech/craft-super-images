<?php

namespace amici\SuperImages\models;

final class GenerationResult
{
    /**
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $identity,
        public readonly string $storagePath,
        public readonly string $url,
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size,
        public readonly string $mime,
        public readonly float $durationMs = 0.0,
        public readonly array $diagnostics = [],
    ) {
    }
}
