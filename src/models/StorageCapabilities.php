<?php

namespace amici\SuperImages\models;

final class StorageCapabilities
{
    public function __construct(
        public readonly bool $remote = false,
        public readonly bool $publicUrls = true,
        public readonly bool $atomicWrite = false,
    ) {
    }
}
