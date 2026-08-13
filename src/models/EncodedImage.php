<?php

namespace amici\SuperImages\models;

final class EncodedImage
{
    public function __construct(
        public readonly string $format,
        public readonly int $width,
        public readonly int $height,
        public readonly int $size,
        public readonly string $mime,
        public readonly ?string $bytes = null,
        public readonly ?string $path = null,
        public readonly bool $isTemporary = false,
    ) {
    }

    public function hasBytes(): bool
    {
        return $this->bytes !== null && $this->bytes !== '';
    }

    public function hasPath(): bool
    {
        return $this->path !== null && $this->path !== '';
    }

    public function withBytes(string $bytes, int $size): self
    {
        return new self(
            $this->format,
            $this->width,
            $this->height,
            $size,
            $this->mime,
            $bytes,
            null,
            false,
        );
    }

    public function withPath(string $path, int $size, bool $isTemporary = false): self
    {
        return new self(
            $this->format,
            $this->width,
            $this->height,
            $size,
            $this->mime,
            null,
            $path,
            $isTemporary,
        );
    }
}
