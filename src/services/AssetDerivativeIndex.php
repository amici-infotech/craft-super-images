<?php
/**
 * Lightweight per-asset index of generated derivatives.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use Craft;
use yii\base\Component;

/**
 * Asset Derivative Index
 *
 * Tracks derivative identities and storage paths per Craft asset under
 * `@storage/super-images/asset-index/{assetId}.json`. Enables cleanup on
 * asset delete or replace without scanning all storage backends.
 */
final class AssetDerivativeIndex extends Component
{
    /** @var string|null Lazily resolved absolute root for index files. */
    private ?string $_rootPath = null;

    /**
     * Upserts a derivative entry for an asset.
     *
     * Deduplicates by identity — later writes replace the stored path/adapter.
     *
     * @param int $assetId Craft asset element ID.
     * @param string $identity Stable derivative identity hash.
     * @param string $storagePath Relative storage path for the derivative.
     * @param string $adapter Storage adapter handle.
     *
     * @return void
     */
    public function record(int $assetId, string $identity, string $storagePath, string $adapter): void
    {
        $payload = $this->read($assetId);
        $derivatives = $payload['derivatives'] ?? [];
        $indexed = [];

        foreach ($derivatives as $entry) {
            if (!is_array($entry) || !isset($entry['identity'])) {
                continue;
            }

            $indexed[(string) $entry['identity']] = $entry;
        }

        $indexed[$identity] = [
            'identity' => $identity,
            'storagePath' => $storagePath,
            'adapter' => $adapter,
        ];

        $this->write($assetId, [
            'assetId' => $assetId,
            'updatedAt' => time(),
            'derivatives' => array_values($indexed),
        ]);
    }

    /**
     * Returns indexed derivative entries for an asset.
     *
     * @param int $assetId Craft asset element ID.
     *
     * @return list<array{identity: string, storagePath: string, adapter: string}>
     */
    public function entries(int $assetId): array
    {
        $payload = $this->read($assetId);
        $entries = [];

        foreach ($payload['derivatives'] ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $identity = (string) ($entry['identity'] ?? '');
            $storagePath = (string) ($entry['storagePath'] ?? '');
            $adapter = (string) ($entry['adapter'] ?? '');

            if ($identity === '' || $storagePath === '') {
                continue;
            }

            $entries[] = [
                'identity' => $identity,
                'storagePath' => $storagePath,
                'adapter' => $adapter,
            ];
        }

        return $entries;
    }

    /**
     * Removes the index file for an asset.
     *
     * @param int $assetId Craft asset element ID.
     *
     * @return void
     */
    public function clear(int $assetId): void
    {
        $path = $this->indexPath($assetId);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Reads and decodes an asset index payload.
     *
     * @param int $assetId Craft asset element ID.
     *
     * @return array<string, mixed> Decoded index payload or an empty scaffold.
     */
    private function read(int $assetId): array
    {
        $path = $this->indexPath($assetId);

        if (!is_file($path)) {
            return [
                'assetId' => $assetId,
                'updatedAt' => null,
                'derivatives' => [],
            ];
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [
                'assetId' => $assetId,
                'updatedAt' => null,
                'derivatives' => [],
            ];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [
                'assetId' => $assetId,
                'updatedAt' => null,
                'derivatives' => [],
            ];
        }

        if (!is_array($decoded)) {
            return [
                'assetId' => $assetId,
                'updatedAt' => null,
                'derivatives' => [],
            ];
        }

        return $decoded;
    }

    /**
     * Persists an asset index payload to disk.
     *
     * @param int $assetId Craft asset element ID.
     * @param array<string, mixed> $payload Index payload to encode.
     *
     * @return void
     */
    private function write(int $assetId, array $payload): void
    {
        $path = $this->indexPath($assetId);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Builds the absolute filesystem path for an asset index file.
     *
     * @param int $assetId Craft asset element ID.
     *
     * @return string Absolute path to `{assetId}.json`.
     */
    private function indexPath(int $assetId): string
    {
        if ($this->_rootPath === null) {
            $this->_rootPath = (string) Craft::getAlias('@storage/super-images/asset-index');
        }

        return rtrim($this->_rootPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $assetId
            . '.json';
    }
}
