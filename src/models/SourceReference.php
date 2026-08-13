<?php

namespace amici\SuperImages\models;

final class SourceReference
{
    public function __construct(
        public readonly SourceKind $kind,
        public readonly ?int $assetId = null,
        public readonly ?string $localPath = null,
        public readonly ?string $remoteUrl = null,
    ) {
    }

    public static function fromAsset(int $assetId): self
    {
        return new self(SourceKind::Asset, assetId: $assetId);
    }

    public static function fromLocalPath(string $path): self
    {
        return new self(SourceKind::LocalPath, localPath: $path);
    }

    public static function fromRemoteUrl(string $url): self
    {
        return new self(SourceKind::RemoteUrl, remoteUrl: $url);
    }
}
