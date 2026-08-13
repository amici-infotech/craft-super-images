<?php

namespace amici\SuperImages\models;

final class StorageWriteOptions
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $contentType = 'application/octet-stream',
        public readonly bool $public = true,
        public readonly array $metadata = [],
    ) {
    }
}
