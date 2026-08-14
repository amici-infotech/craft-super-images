<?php
/**
 * Lightweight reference to an image source before resolution and loading.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Source Reference
 *
 * Identifies one of three mutually exclusive source types used to build a SourceImage later in the pipeline.
 */
final class SourceReference
{
    /**
     * @param SourceKind $kind Discriminator for which source field is populated.
     * @param int|null $assetId Craft asset element ID when kind is Asset.
     * @param string|null $localPath Absolute filesystem path when kind is LocalPath.
     * @param string|null $remoteUrl HTTP(S) URL when kind is RemoteUrl.
     */
    public function __construct(
        public readonly SourceKind $kind,
        public readonly ?int $assetId = null,
        public readonly ?string $localPath = null,
        public readonly ?string $remoteUrl = null,
    ) {
    }

    /**
     * Creates a reference to a Craft asset by element ID.
     *
     * @param int $assetId Craft asset element ID.
     *
     * @return self Reference with kind Asset and assetId set.
     */
    public static function fromAsset(int $assetId): self
    {
        return new self(SourceKind::Asset, assetId: $assetId);
    }

    /**
     * Creates a reference to a local filesystem path.
     *
     * @param string $path Absolute path to a readable image file.
     *
     * @return self Reference with kind LocalPath and localPath set.
     */
    public static function fromLocalPath(string $path): self
    {
        return new self(SourceKind::LocalPath, localPath: $path);
    }

    /**
     * Creates a reference to a remote HTTP(S) URL.
     *
     * @param string $url Fully qualified URL to fetch as the source image.
     *
     * @return self Reference with kind RemoteUrl and remoteUrl set.
     */
    public static function fromRemoteUrl(string $url): self
    {
        return new self(SourceKind::RemoteUrl, remoteUrl: $url);
    }
}
