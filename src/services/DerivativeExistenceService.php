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

            // Fast path 1: identity marker — written only after a successful remote upload.
            // Trust without HEAD (~300 ms on R2/S3). Invalidate when adapter/path drift
            // (e.g. after switching storage backends or changing path layout).
            if ($markers->isEnabled() && $markers->exists($identity)) {
                $payload = $this->_markerPayload($identity);
                $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

                if ($this->_locationMismatch($meta, $storageAdapter, $storagePath, 'adapter', 'path')) {
                    $markers->delete($identity);
                    unset($this->_markerCache[$identity]);
                } else {
                    return $this->_remember($identity, true);
                }
            }

            // Fast path 2: per-asset derivative index (used by cleanup and remote purge).
            // Fall back to path lookup when identity hash changed but storage path stayed the same.
            if ($assetId !== null) {
                $entry = $this->_indexedEntry($assetId, $identity)
                    ?? ($storagePath !== '' ? $this->_indexedEntryByPath($assetId, $storagePath) : null);

                if ($entry !== null) {
                    if ($this->_locationMismatch($entry, $storageAdapter, $storagePath, 'adapter', 'storagePath')) {
                        $plugin->getAssetDerivativeIndex()->forget($assetId, $identity);
                        unset($this->_assetIndexMaps[$assetId]);
                    } else {
                        return $this->_remember($identity, true);
                    }
                }
            }

            // Fast path 3: marker indexed by storage path (covers identity drift without HEAD).
            if ($storagePath !== '') {
                $payload = $this->_markerByPath($storagePath);

                if ($payload !== null) {
                    $meta = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
                    $markerAdapter = (string) ($meta['adapter'] ?? '');

                    if ($markerAdapter === '' || $markerAdapter === $storageAdapter) {
                        return $this->_remember($identity, true);
                    }
                }
            }
        }

        // Slow path: network HEAD / filesystem stat when no local trust signal exists.

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
     * Loads a marker payload by storage path, cached once per request.
     *
     * Used when the generation identity hash changed (config/ops drift) but the
     * deterministic storage path stayed the same — avoids a remote HEAD in that case.
     *
     * @param string $storagePath Relative storage path.
     *
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
     * Builds and caches the asset-index map for one Craft asset ID per request.
     *
     * @param int $assetId Craft asset element ID.
     *
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
     * Whether stored adapter/path metadata no longer matches the requested location.
     *
     * Markers and asset-index entries record which adapter and path they belong to.
     * When config changes (new R2 bucket, different `baseUrl`, path prefix tweak), old
     * records must be discarded so we do not trust a cache hit for the wrong object.
     *
     * Empty stored values are treated as "unknown" and do not trigger a mismatch.
     *
     * @param array<string, mixed> $fields Marker `metadata` or asset-index entry row.
     * @param string $expectedAdapter Adapter handle for this existence check.
     * @param string $expectedPath Relative storage path for this existence check.
     * @param string $adapterKey Key holding the adapter name inside `$fields` (`adapter`).
     * @param string $pathKey Key holding the storage path inside `$fields` (`path` or `storagePath`).
     *
     * @return bool True when the stored location no longer matches and the record is stale.
     */
    private function _locationMismatch(
        array $fields,
        string $expectedAdapter,
        string $expectedPath,
        string $adapterKey,
        string $pathKey,
    ): bool {
        $adapter = (string) ($fields[$adapterKey] ?? '');
        $path = (string) ($fields[$pathKey] ?? '');

        return ($adapter !== '' && $adapter !== $expectedAdapter)
            || ($path !== '' && $path !== $expectedPath);
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
