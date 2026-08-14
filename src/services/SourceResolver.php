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
 */
class SourceResolver extends Component
{
    /**
     * Resolve a generation request into a usable SourceImage.
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

    private function assetIdentity(Asset $asset): string
    {
        return $this->formatAssetIdentity((int) $asset->id, $asset->dateModified?->getTimestamp() ?? 0);
    }

    private function assetIdentityFromId(int $assetId): string
    {
        $asset = Asset::find()->id($assetId)->one();

        if (!$asset instanceof Asset) {
            throw new SourceException(sprintf('Asset "%d" was not found.', $assetId));
        }

        return $this->assetIdentity($asset);
    }

    private function formatAssetIdentity(int $assetId, int $modifiedTimestamp): string
    {
        return 'asset:' . $assetId . ':' . $modifiedTimestamp;
    }

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
