<?php

namespace amici\SuperImages\models;

final class DriverCapabilities
{
    /**
     * @param list<string> $operations
     * @param list<string> $formats
     */
    public function __construct(
        public readonly array $operations = [],
        public readonly array $formats = [],
        public readonly bool $supportsAlpha = true,
        public readonly bool $supportsWatermark = false,
    ) {
    }
}
