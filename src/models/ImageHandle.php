<?php

namespace amici\SuperImages\models;

final class ImageHandle
{
    public function __construct(
        public readonly string $driverName,
        public mixed $resource,
        public int $width,
        public int $height,
        public bool $hasAlpha,
        public string $mime,
    ) {
    }

    public function withDimensions(int $width, int $height): self
    {
        return new self(
            $this->driverName,
            $this->resource,
            $width,
            $height,
            $this->hasAlpha,
            $this->mime,
        );
    }
}
