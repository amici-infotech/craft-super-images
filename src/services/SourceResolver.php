<?php
/**
 * Resolves Craft Assets, local paths, and allow-listed remote URLs into SourceImage.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\InvalidConfigurationException;
use amici\SuperImages\exceptions\SourceException;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\SourceImage;
use amici\SuperImages\models\SourceKind;
use amici\SuperImages\models\SourceReference;
use amici\SuperImages\Plugin;
use amici\SuperImages\support\PathGuard;
use amici\SuperImages\support\UrlGuard;
use craft\elements\Asset;
use yii\base\Component;

/**
 * Source Resolver
 *
 * Resolves generation requests into SourceImage instances or stable identity strings.
 * Supports Craft assets, allow-listed local paths, and allow-listed remote URLs.
 */
class SourceResolver extends Component
{
    /**
     * Resolve a generation request into a usable SourceImage on disk.
     *
     * @param GenerationRequest $request The generation request with exactly one source.
     *
     * @return SourceImage The resolved source with readable path and metadata.
     *
     * @throws SourceException When the request has zero or multiple sources, or resolution fails.
     * @throws InvalidConfigurationException When local or remote sources are disabled in config.
     */
    public function resolve(GenerationRequest $request): SourceImage
    {
        if ($request->sourceCount() !== 1) {
            throw new SourceException('Generation request must include exactly one source.');
        }

        if ($request->assetId !== null) {
            return $this->resolveAsset($request->assetId);
        }

        if ($request->localPath !== null) {
            return $this->resolveLocalPath($request->localPath);
        }

        if ($request->remoteUrl !== null) {
            return $this->resolveRemoteUrl($request->remoteUrl);
        }

        throw new SourceException('No valid source was provided.');
    }

    /**
     * Build a lightweight SourceReference from a generation request.
     *
     * @param GenerationRequest $request The generation request with exactly one source.
     *
     * @return SourceReference A serializable source reference without file I/O.
     *
     * @throws SourceException When no valid source is provided.
     */
    public function referenceFromRequest(GenerationRequest $request): SourceReference
    {
        if ($request->assetId !== null) {
            return SourceReference::fromAsset($request->assetId);
        }

        if ($request->localPath !== null) {
            return SourceReference::fromLocalPath($request->localPath);
        }

        if ($request->remoteUrl !== null) {
            return SourceReference::fromRemoteUrl($request->remoteUrl);
        }

        throw new SourceException('No valid source was provided.');
    }

    /**
     * Resolve source identity without downloading or copying files.
     *
     * @param GenerationRequest $request The generation request with exactly one source.
     *
     * @return string Stable identity string used in generation definition hashing.
     *
     * @throws SourceException When the request has zero or multiple sources, or the asset is missing.
     * @throws InvalidConfigurationException When local path sources are disabled.
     */
    public function resolveIdentity(GenerationRequest $request): string
    {
        if ($request->sourceCount() !== 1) {
            throw new SourceException('Generation request must include exactly one source.');
        }

        if ($request->assetId !== null) {
            return $this->assetIdentityFromId($request->assetId);
        }

        if ($request->localPath !== null) {
            return $this->localPathIdentity($request->localPath);
        }

        if ($request->remoteUrl !== null) {
            return 'remote:' . hash('sha256', $request->remoteUrl);
        }

        throw new SourceException('No valid source was provided.');
    }

    /**
     * Resolve a Craft asset into a temporary local SourceImage copy.
     *
     * @param int $assetId The Craft asset element ID.
     *
     * @return SourceImage The source image backed by a tracked temp copy of the asset file.
     *
     * @throws SourceException When the asset is not found or the file is not readable.
     */
    private function resolveAsset(int $assetId): SourceImage
    {
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset instanceof Asset) {
            throw new SourceException(sprintf('Asset "%d" was not found.', $assetId));
        }

        $path = $asset->getCopyOfFile();
        if (!is_readable($path)) {
            throw new SourceException('Asset file is not readable.');
        }

        Plugin::getInstance()->getTemporaryFiles()->track($path);

        return new SourceImage(
            kind: SourceKind::Asset,
            identity: $this->assetIdentity($asset),
            path: $path,
            mime: $asset->getMimeType(),
            width: $asset->getWidth(),
            height: $asset->getHeight(),
            isTemporary: true,
            metadata: [
                'assetId' => $asset->id,
                'filename' => $asset->getFilename(),
            ],
        );
    }

    /**
     * Resolve an allow-listed local filesystem path into a SourceImage.
     *
     * @param string $localPath The configured local path or alias.
     *
     * @return SourceImage The source image referencing the canonical local file.
     *
     * @throws InvalidConfigurationException When local path sources are disabled.
     * @throws SourceException When the path is outside allowed roots or not readable.
     */
    private function resolveLocalPath(string $localPath): SourceImage
    {
        $settings = Plugin::getInstance()->getSettings();
        $localConfig = $settings->sources['local'] ?? [];

        if (!($localConfig['enabled'] ?? false)) {
            throw new InvalidConfigurationException('Local path sources are disabled.');
        }

        $guard = new PathGuard($localConfig['allowedRoots'] ?? []);
        $path = $guard->resolve($localPath);
        $identity = 'local:' . hash(
            'sha256',
            $path . '|' . (string) filemtime($path) . '|' . (string) filesize($path)
        );

        return new SourceImage(
            kind: SourceKind::LocalPath,
            identity: $identity,
            path: $path,
            mime: mime_content_type($path) ?: null,
            isTemporary: false,
            metadata: ['path' => $path],
        );
    }

    /**
     * Download an allow-listed remote URL into a temporary SourceImage.
     *
     * @param string $remoteUrl The remote HTTP(S) URL to fetch.
     *
     * @return SourceImage The source image backed by a tracked temp download.
     *
     * @throws InvalidConfigurationException When remote URL sources are disabled.
     * @throws SourceException When the URL fails validation or download.
     */
    private function resolveRemoteUrl(string $remoteUrl): SourceImage
    {
        $settings = Plugin::getInstance()->getSettings();
        $remoteConfig = $settings->sources['remote'] ?? [];

        if (!($remoteConfig['enabled'] ?? false)) {
            throw new InvalidConfigurationException('Remote URL sources are disabled.');
        }

        $guard = new UrlGuard(
            $remoteConfig['allowedHosts'] ?? [],
            (int) ($remoteConfig['timeout'] ?? 10),
            (int) ($remoteConfig['maxBytes'] ?? 25_000_000),
            (int) ($remoteConfig['maxRedirects'] ?? 3),
        );

        $download = $guard->download($remoteUrl);
        $path = Plugin::getInstance()->getTemporaryFiles()->write(
            'remote-source-',
            $download['body'],
            $this->extensionFromUrl($remoteUrl),
        );
        $identity = 'remote:' . hash('sha256', $remoteUrl);

        return new SourceImage(
            kind: SourceKind::RemoteUrl,
            identity: $identity,
            path: $path,
            mime: $download['mime'],
            isTemporary: true,
            metadata: ['url' => $remoteUrl],
        );
    }

    /**
     * Build the identity string for a loaded asset element.
     *
     * @param Asset $asset The Craft asset element.
     *
     * @return string Identity in the form asset:{id}:{dateModified}.
     */
    private function assetIdentity(Asset $asset): string
    {
        return $this->formatAssetIdentity((int) $asset->id, $asset->dateModified?->getTimestamp() ?? 0);
    }

    /**
     * Load an asset by ID and return its identity string.
     *
     * @param int $assetId The Craft asset element ID.
     *
     * @return string Identity in the form asset:{id}:{dateModified}.
     *
     * @throws SourceException When the asset is not found.
     */
    private function assetIdentityFromId(int $assetId): string
    {
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset instanceof Asset) {
            throw new SourceException(sprintf('Asset "%d" was not found.', $assetId));
        }

        return $this->assetIdentity($asset);
    }

    /**
     * Format a stable asset identity from ID and modification timestamp.
     *
     * @param int $assetId The Craft asset element ID.
     * @param int $modifiedTimestamp The asset dateModified Unix timestamp.
     *
     * @return string Identity in the form asset:{id}:{timestamp}.
     */
    private function formatAssetIdentity(int $assetId, int $modifiedTimestamp): string
    {
        return 'asset:' . $assetId . ':' . $modifiedTimestamp;
    }

    /**
     * Compute identity for a local path without creating a SourceImage.
     *
     * @param string $localPath The configured local path or alias.
     *
     * @return string Identity prefixed with local: and a content hash.
     *
     * @throws InvalidConfigurationException When local path sources are disabled.
     * @throws SourceException When the path is not readable.
     */
    private function localPathIdentity(string $localPath): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $localConfig = $settings->sources['local'] ?? [];

        if (!($localConfig['enabled'] ?? false)) {
            throw new InvalidConfigurationException('Local path sources are disabled.');
        }

        $guard = new PathGuard($localConfig['allowedRoots'] ?? []);
        $path = $guard->resolve($localPath);

        if (!is_readable($path)) {
            throw new SourceException('Local path is not readable.');
        }

        return 'local:' . hash(
            'sha256',
            $path . '|' . (string) filemtime($path) . '|' . (string) filesize($path)
        );
    }

    /**
     * Guess a file extension from a remote URL path component.
     *
     * @param string $url The remote URL.
     *
     * @return string File extension without dot, or img when unknown.
     */
    private function extensionFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return 'img';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'img';
    }
}
