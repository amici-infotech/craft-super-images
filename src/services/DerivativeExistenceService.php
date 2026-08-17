<?php
/**
 * Fast derivative existence checks without redundant remote HEAD requests.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\Plugin;
use yii\base\Component;

/**
 * Derivative Existence Service
 *
 * For remote adapters, prefers local existence markers and the per-asset index
 * before calling the storage API. Results are memoized for the current request.
 */
final class DerivativeExistenceService extends Component
{
    /** @var array<string, bool> Per-request cache keyed by generation identity. */
    private array $_cache = [];

    /** @var array<int, array<string, array{identity: string, storagePath: string, adapter: string}>> Asset ID → identity map. */
    private array $_assetIndexMaps = [];

    /** @var array<string, array{identity?: string, createdAt?: int, metadata?: array<string, mixed>}|null> Marker payload cache. */
    private array $_markerCache = [];

    /** @var array<string, array{identity?: string, createdAt?: int, metadata?: array<string, mixed>}|null> Storage path → marker cache. */
    private array $_markerByPathCache = [];

    /**
     * Whether a derivative already exists in storage (or is known locally).
     *
     * For remote adapters, a matching existence marker or asset-index entry is
     * trusted without a network HEAD — markers are only written after a
     * successful upload. Stale entries (wrong adapter) are cleared locally.
     *
     * @param string $storageAdapter Adapter handle.
     * @param string $storagePath Relative storage path.
     * @param string $identity Generation identity hash.
     * @param int|null $assetId Craft asset ID when known (enables asset-index shortcut).
     *
     * @return bool True when the derivative is present or locally indexed.
     */
    public function exists(
        string $storageAdapter,
        string $storagePath,
        string $identity,
        ?int $assetId = null,
    ): bool {
        if ($identity !== '' && isset($this->_cache[$identity])) {
            return $this->_cache[$identity];
        }

        $plugin = Plugin::getInstance();
        $adapter = $plugin->getStorageManager()->select($storageAdapter);
        $remote = $adapter->capabilities()->remote;

        if ($remote) {
            $markers = $plugin->getExistenceMarkers();

            if ($markers->isEnabled() && $markers->exists($identity)) {
                $payload = $this->_markerPayload($identity);
                $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                $markerAdapter = (string) ($metadata['adapter'] ?? '');
                $markerPath = (string) ($metadata['path'] ?? '');

                if (
                    ($markerAdapter !== '' && $markerAdapter !== $storageAdapter)
                    || ($markerPath !== '' && $markerPath !== $storagePath)
                ) {
                    $markers->delete($identity);
                    unset($this->_markerCache[$identity]);
                } else {
                    return $this->_remember($identity, true);
                }
            }

            if ($assetId !== null) {
                $entry = $this->_indexedEntry($assetId, $identity);

                if ($entry === null && $storagePath !== '') {
                    $entry = $this->_indexedEntryByPath($assetId, $storagePath);
                }

                if ($entry !== null) {
                    $entryAdapter = (string) ($entry['adapter'] ?? '');
                    $entryPath = (string) ($entry['storagePath'] ?? '');

                    if (
                        ($entryAdapter !== '' && $entryAdapter !== $storageAdapter)
                        || ($entryPath !== '' && $entryPath !== $storagePath)
                    ) {
                        $plugin->getAssetDerivativeIndex()->forget($assetId, $identity);
                        unset($this->_assetIndexMaps[$assetId]);
                    } else {
                        return $this->_remember($identity, true);
                    }
                }
            }

            if ($storagePath !== '') {
                $payload = $this->_markerByPath($storagePath);

                if ($payload !== null) {
                    $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                    $markerAdapter = (string) ($metadata['adapter'] ?? '');

                    if ($markerAdapter === '' || $markerAdapter === $storageAdapter) {
                        return $this->_remember($identity, true);
                    }
                }
            }
        }

        $objectExists = $adapter->exists($storagePath);

        return $this->_remember($identity, $objectExists);
    }

    /**
     * Loads a marker payload once per request.
     *
     * @param string $identity Generation identity hash.
     *
     * @return array{identity?: string, createdAt?: int, metadata?: array<string, mixed>}|null
     */
    private function _markerPayload(string $identity): ?array
    {
        if (!array_key_exists($identity, $this->_markerCache)) {
            $this->_markerCache[$identity] = Plugin::getInstance()->getExistenceMarkers()->read($identity);
        }

        return $this->_markerCache[$identity];
    }

    /**
     * @return array{identity?: string, createdAt?: int, metadata?: array<string, mixed>}|null
     */
    private function _markerByPath(string $storagePath): ?array
    {
        if (!array_key_exists($storagePath, $this->_markerByPathCache)) {
            $this->_markerByPathCache[$storagePath] = Plugin::getInstance()
                ->getExistenceMarkers()
                ->findByStoragePath($storagePath);
        }

        return $this->_markerByPathCache[$storagePath];
    }

    /**
     * Finds one indexed entry, loading the asset index once per request.
     *
     * @param int $assetId Craft asset element ID.
     * @param string $identity Generation identity hash.
     *
     * @return array{identity: string, storagePath: string, adapter: string}|null
     */
    private function _indexedEntry(int $assetId, string $identity): ?array
    {
        $map = $this->_assetIndexMap($assetId);

        return $map[$identity] ?? null;
    }

    /**
     * Finds an indexed entry by storage path when identity lookup misses.
     *
     * @param int $assetId Craft asset element ID.
     * @param string $storagePath Relative storage path.
     *
     * @return array{identity: string, storagePath: string, adapter: string}|null
     */
    private function _indexedEntryByPath(int $assetId, string $storagePath): ?array
    {
        foreach ($this->_assetIndexMap($assetId) as $entry) {
            if ($entry['storagePath'] === $storagePath) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{identity: string, storagePath: string, adapter: string}>
     */
    private function _assetIndexMap(int $assetId): array
    {
        if (!isset($this->_assetIndexMaps[$assetId])) {
            $map = [];
            foreach (Plugin::getInstance()->getAssetDerivativeIndex()->entries($assetId) as $entry) {
                $map[$entry['identity']] = $entry;
            }
            $this->_assetIndexMaps[$assetId] = $map;
        }

        return $this->_assetIndexMaps[$assetId];
    }

    /**
     * Stores a per-request existence result when identity is non-empty.
     *
     * @param string $identity Generation identity hash.
     * @param bool $exists Whether the derivative exists.
     *
     * @return bool The same `$exists` value (for fluent return).
     */
    private function _remember(string $identity, bool $exists): bool
    {
        if ($identity !== '') {
            $this->_cache[$identity] = $exists;
        }

        return $exists;
    }
}
