<?php

namespace amici\SuperImages\models;

final class SourceImage
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly SourceKind $kind,
        public readonly string $identity,
        public readonly ?string $path = null,
        public readonly ?string $bytes = null,
        public readonly ?string $mime = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly bool $isTemporary = false,
        public readonly array $metadata = [],
    ) {
    }

    public function readablePath(): ?string
    {
        if ($this->path !== null && is_readable($this->path)) {
            return $this->path;
        }

        return null;
    }
}
