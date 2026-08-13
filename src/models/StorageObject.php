<?php

namespace amici\SuperImages\models;

final class StorageObject
{
    public function __construct(
        public readonly string $path,
        public readonly string $url,
        public readonly int $size,
        public readonly string $mime,
    ) {
    }
}
