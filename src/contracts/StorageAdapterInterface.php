<?php

namespace amici\SuperImages\contracts;

use amici\SuperImages\models\StorageCapabilities;
use amici\SuperImages\models\StorageObject;
use amici\SuperImages\models\StorageWriteOptions;

interface StorageAdapterInterface
{
    public function name(): string;

    public function write(string $path, string $contents, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject;

    public function writeFile(string $path, string $localFile, StorageWriteOptions $options = new StorageWriteOptions()): StorageObject;

    public function exists(string $path): bool;

    public function delete(string $path): void;

    public function url(string $path): string;

    public function capabilities(): StorageCapabilities;
}
