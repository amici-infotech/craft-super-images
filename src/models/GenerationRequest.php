<?php

namespace amici\SuperImages\models;

use craft\base\FieldInterface;
use craft\models\Volume;
use craft\models\VolumeFolder;

final class GenerationRequest
{
    /**
     * @param list<OperationDefinition>|null $operationOverrides
     */
    public function __construct(
        public readonly ?int $assetId = null,
        public readonly ?string $localPath = null,
        public readonly ?string $remoteUrl = null,
        public readonly ?string $profile = null,
        public readonly ?string $variant = null,
        public readonly ?string $format = null,
        public readonly ?array $operationOverrides = null,
        public readonly ?Volume $volume = null,
        public readonly ?VolumeFolder $folder = null,
        public readonly ?FieldInterface $field = null,
        public readonly ?string $storageAdapter = null,
        /** When true, derivatives are stored under a preview/ namespace (Playground). */
        public readonly bool $preview = false,
    ) {
    }

    public function sourceCount(): int
    {
        return (int)($this->assetId !== null)
            + (int)($this->localPath !== null)
            + (int)($this->remoteUrl !== null);
    }
}
