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

    /**
     * Whether a derivative already exists in storage (or is known locally).
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
            $localShortcut = false;

            if ($markers->isEnabled() && $markers->exists($identity)) {
                $payload = $markers->read($identity);
                $markerAdapter = (string) ($payload['metadata']['adapter'] ?? '');

                if ($markerAdapter !== '' && $markerAdapter !== $storageAdapter) {
                    $markers->delete($identity);
                } else {
                    $localShortcut = true;
                }
            }

            if (!$localShortcut && $assetId !== null) {
                $entry = $this->_findIndexedEntry($assetId, $identity);

                if ($entry !== null) {
                    $entryAdapter = (string) ($entry['adapter'] ?? '');

                    if ($entryAdapter !== '' && $entryAdapter !== $storageAdapter) {
                        $plugin->getAssetDerivativeIndex()->forget($assetId, $identity);
                    } else {
                        $localShortcut = true;
                    }
                }
            }

            if ($localShortcut) {
                if ($adapter->exists($storagePath)) {
                    return $this->_remember($identity, true);
                }

                $this->_purgeLocal($identity, $assetId);

                return $this->_remember($identity, false);
            }
        }

        $objectExists = $adapter->exists($storagePath);

        return $this->_remember($identity, $objectExists);
    }

    /**
     * Finds one indexed entry for an asset/identity pair.
     *
     * @param int $assetId Craft asset element ID.
     * @param string $identity Generation identity hash.
     *
     * @return array{identity: string, storagePath: string, adapter: string}|null
     */
    private function _findIndexedEntry(int $assetId, string $identity): ?array
    {
        foreach (Plugin::getInstance()->getAssetDerivativeIndex()->entries($assetId) as $entry) {
            if ($entry['identity'] === $identity) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Clears stale local marker/index entries when the remote object is missing.
     *
     * @param string $identity Generation identity hash.
     * @param int|null $assetId Craft asset ID when known.
     *
     * @return void
     */
    private function _purgeLocal(string $identity, ?int $assetId): void
    {
        $plugin = Plugin::getInstance();
        $plugin->getExistenceMarkers()->delete($identity);

        if ($assetId !== null) {
            $plugin->getAssetDerivativeIndex()->forget($assetId, $identity);
        }
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
