<?php

namespace amici\SuperImages\models;

final class Dimensions
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public function pixels(): int
    {
        return $this->width * $this->height;
    }

    /**
     * @return array{width: int, height: int}
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
