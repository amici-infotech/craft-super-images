<?php
/**
 * Cleanup for Super Images preview and generated derivatives.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\Plugin;
use amici\SuperImages\support\PathGuard;
use Craft;
use craft\elements\Asset;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use yii\base\Component;

/**
 * Cleanup Service
 *
 * Scans local derivative storage for aged or all files, purges indexed
 * derivatives for a specific or orphaned Craft asset, and optionally clears
 * the asset derivative index. Never touches Craft originals.
 */
class CleanupService extends Component
{
    /**
     * Scan and optionally delete expired preview derivatives under `preview/`.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention; defaults to cleanup.previewRetentionDays.
     * @param null|callable(string $path, string $action, int $index, int $total): void $onItem Progress callback.
     *
     * @return array<string, mixed> Cleanup report.
     */
    public function cleanupPreviews(bool $dryRun = false, ?int $retentionDays = null, ?callable $onItem = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $retentionDays ??= (int) ($settings->cleanup['previewRetentionDays'] ?? 2);

        return $this->purgeStorageDerivatives(
            $dryRun,
            $retentionDays,
            ignoreRetention: false,
            previewOnly: true,
            onItem: $onItem,
        );
    }

    /**
     * Delete all indexed derivatives for a Craft asset.
     *
     * @param int $assetId Craft asset element ID.
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param null|callable(string $path, string $action, int $index, int $total): void $onItem Progress callback.
     *
     * @return array<string, mixed> Cleanup report with candidates, deleted, and errors.
     */
    public function purgeAssetDerivatives(int $assetId, bool $dryRun = false, ?callable $onItem = null): array
    {
        $plugin = Plugin::getInstance();
        $entries = $plugin->getAssetDerivativeIndex()->entries($assetId);
        $total = count($entries);
        $index = 0;

        $result = [
            'dryRun' => $dryRun,
            'assetId' => $assetId,
            'candidates' => $total,
            'deleted' => 0,
            'errors' => 0,
        ];

        foreach ($entries as $entry) {
            $identity = $entry['identity'];
            $storagePath = $entry['storagePath'];
            $adapterName = $entry['adapter'] !== ''
                ? $entry['adapter']
                : (string) ($plugin->getSettings()->storage['default'] ?? 'local');

            $index++;

            if ($dryRun) {
                $this->notify($onItem, $storagePath, 'dry-run', $index, $total);

                continue;
            }

            try {
                $adapter = $plugin->getStorageManager()->select($adapterName);
                $adapter->delete($storagePath);
                $plugin->getExistenceMarkers()->delete($identity);
                $result['deleted']++;
                $this->notify($onItem, $storagePath, 'deleted', $index, $total);
            } catch (\Throwable $exception) {
                $result['errors']++;
                $this->notify($onItem, $storagePath, 'failed', $index, $total);
                Craft::warning(
                    sprintf(
                        'Failed to purge derivative "%s" for asset %d: %s',
                        $identity,
                        $assetId,
                        $exception->getMessage(),
                    ),
                    __METHOD__,
                );
            }
        }

        if (!$dryRun && $result['errors'] === 0) {
            $plugin->getAssetDerivativeIndex()->clear($assetId);
        }

        return $result;
    }

    /**
     * Purge derivatives for Craft assets that no longer exist.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention; defaults to cleanup.generatedRetentionDays.
     * @param null|callable(string $path, string $action, int $index, int $total): void $onItem Progress callback.
     *
     * @return array<string, mixed> Aggregated cleanup report across all orphaned assets.
     */
    public function purgeOrphanedDerivatives(bool $dryRun = false, ?int $retentionDays = null, ?callable $onItem = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $retentionDays ??= (int) (
            $settings->cleanup['generatedRetentionDays']
            ?? $settings->cleanup['obsoleteRetentionDays']
            ?? 365
        );
        $retentionDays = max(0, $retentionDays);
        $cutoff = time() - ($retentionDays * 86400);

        $index = $plugin->getAssetDerivativeIndex();
        $assetIds = $index->allIndexedAssetIds();

        $result = [
            'dryRun' => $dryRun,
            'retentionDays' => $retentionDays,
            'cutoff' => $cutoff,
            'assetsScanned' => count($assetIds),
            'assetsOrphaned' => 0,
            'assetsSkippedFresh' => 0,
            'candidates' => 0,
            'deleted' => 0,
            'errors' => 0,
        ];

        if ($assetIds === []) {
            return $result;
        }

        $existingIds = array_map('intval', Asset::find()
            ->id($assetIds)
            ->status(null)
            ->trashed(null)
            ->ids());

        $orphanedIds = [];
        foreach ($assetIds as $assetId) {
            if (in_array($assetId, $existingIds, true)) {
                continue;
            }

            $updatedAt = $index->updatedAt($assetId);
            if ($updatedAt !== null && $updatedAt >= $cutoff) {
                $result['assetsSkippedFresh']++;

                continue;
            }

            $orphanedIds[] = $assetId;
        }

        $result['assetsOrphaned'] = count($orphanedIds);

        // Pre-count units so progress totals are accurate.
        $pending = [];
        $totalUnits = 0;
        foreach ($orphanedIds as $assetId) {
            $entries = $index->entries($assetId);
            $pending[$assetId] = $entries;
            $totalUnits += count($entries);
        }

        $result['candidates'] = $totalUnits;
        $unitIndex = 0;

        foreach ($pending as $assetId => $entries) {
            foreach ($entries as $entry) {
                $unitIndex++;
                $label = sprintf('asset#%d %s', $assetId, $entry['storagePath']);
                $identity = $entry['identity'];
                $storagePath = $entry['storagePath'];
                $adapterName = $entry['adapter'] !== ''
                    ? $entry['adapter']
                    : (string) ($settings->storage['default'] ?? 'local');

                if ($dryRun) {
                    $this->notify($onItem, $label, 'dry-run', $unitIndex, $totalUnits);

                    continue;
                }

                try {
                    $adapter = $plugin->getStorageManager()->select($adapterName);
                    $adapter->delete($storagePath);
                    $plugin->getExistenceMarkers()->delete($identity);
                    $result['deleted']++;
                    $this->notify($onItem, $label, 'deleted', $unitIndex, $totalUnits);
                } catch (\Throwable $exception) {
                    $result['errors']++;
                    $this->notify($onItem, $label, 'failed', $unitIndex, $totalUnits);
                    Craft::warning(
                        sprintf(
                            'Failed to purge derivative "%s" for orphaned asset %d: %s',
                            $identity,
                            $assetId,
                            $exception->getMessage(),
                        ),
                        __METHOD__,
                    );
                }
            }

            if (!$dryRun) {
                $index->clear((int) $assetId);
            }
        }

        return $result;
    }

    /**
     * Sweep local derivative storage by age, or delete everything.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention; defaults to cleanup.generatedRetentionDays.
     * @param bool $ignoreRetention When true, delete every file regardless of age.
     * @param bool $previewOnly When true, only consider paths under `preview/`.
     * @param null|callable(string $path, string $action, int $index, int $total): void $onItem Progress callback.
     *
     * @return array<string, mixed> Cleanup report with counts and skip reason when applicable.
     */
    public function purgeStorageDerivatives(
        bool $dryRun = false,
        ?int $retentionDays = null,
        bool $ignoreRetention = false,
        bool $previewOnly = false,
        ?callable $onItem = null,
    ): array {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $retentionDays ??= (int) (
            $settings->cleanup['generatedRetentionDays']
            ?? $settings->cleanup['obsoleteRetentionDays']
            ?? 365
        );
        $retentionDays = max(0, $retentionDays);
        $cutoff = $ignoreRetention ? null : time() - ($retentionDays * 86400);
        $allowRemoteScan = (bool) ($settings->cleanup['allowRemoteScan'] ?? false);

        $defaultName = (string) ($settings->storage['default'] ?? 'local');
        $adapterConfig = $settings->storage['adapters'][$defaultName] ?? null;

        $result = [
            'dryRun' => $dryRun,
            'retentionDays' => $ignoreRetention ? null : $retentionDays,
            'cutoff' => $cutoff,
            'ignoreRetention' => $ignoreRetention,
            'previewOnly' => $previewOnly,
            'adapter' => $defaultName,
            'skipped' => false,
            'reason' => null,
            'candidates' => 0,
            'deleted' => 0,
            'skippedFresh' => 0,
            'errors' => 0,
        ];

        if (!is_array($adapterConfig)) {
            $result['skipped'] = true;
            $result['reason'] = sprintf('Default storage adapter "%s" is not configured.', $defaultName);

            return $result;
        }

        $type = (string) ($adapterConfig['type'] ?? 'local');
        if ($type !== 'local') {
            $result['skipped'] = true;
            $result['reason'] = $allowRemoteScan
                ? 'Remote storage listing is not implemented; storage sweep only supports local adapters.'
                : 'Remote storage scan skipped (cleanup.allowRemoteScan is false). Storage sweep only runs on local adapters.';

            return $result;
        }

        $root = PathGuard::canonicalize(
            Craft::getAlias((string) ($adapterConfig['path'] ?? '@webroot/uploads/super-images'))
        );

        $scanRoot = $previewOnly
            ? rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'preview'
            : $root;

        if (!is_dir($scanRoot)) {
            $result['reason'] = sprintf('No storage directory found at %s', $scanRoot);

            return $result;
        }

        /** @var list<array{0: string, 1: string}> $candidates [fullPath, relative] */
        $candidates = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($root))), '/');

            if ($relative === '' || str_contains($relative, '..')) {
                $result['errors']++;
                continue;
            }

            if ($previewOnly && !str_starts_with($relative, 'preview/')) {
                continue;
            }

            if ($cutoff !== null) {
                $mtime = (int) $file->getMTime();
                if ($mtime >= $cutoff) {
                    $result['skippedFresh']++;

                    continue;
                }
            }

            $candidates[] = [$fullPath, $relative];
        }

        $total = count($candidates);
        $result['candidates'] = $total;

        foreach ($candidates as $i => [$fullPath, $relative]) {
            $index = $i + 1;

            if ($dryRun) {
                $this->notify($onItem, $relative, 'dry-run', $index, $total);

                continue;
            }

            if (@unlink($fullPath)) {
                $result['deleted']++;
                $this->notify($onItem, $relative, 'deleted', $index, $total);
            } else {
                $result['errors']++;
                $this->notify($onItem, $relative, 'failed', $index, $total);
            }
        }

        if (!$dryRun) {
            $this->pruneEmptyDirectories($scanRoot);

            if ($ignoreRetention && !$previewOnly && $result['errors'] === 0) {
                $plugin->getAssetDerivativeIndex()->clearAll();
            }
        }

        return $result;
    }

    /**
     * @deprecated Use {@see purgeStorageDerivatives()} with `$ignoreRetention`.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention days.
     *
     * @return array<string, mixed>
     */
    public function purgeAllDerivatives(bool $dryRun = false, ?int $retentionDays = null): array
    {
        return $this->purgeStorageDerivatives($dryRun, $retentionDays, ignoreRetention: $retentionDays === 0);
    }

    /**
     * Invoke an optional progress callback.
     *
     * @param null|callable(string, string, int, int): void $onItem Progress callback.
     * @param string $path Relative path or label.
     * @param string $action Action slug (deleted, dry-run, failed).
     * @param int $index 1-based item index.
     * @param int $total Total items in this run.
     *
     * @return void
     */
    private function notify(?callable $onItem, string $path, string $action, int $index, int $total): void
    {
        if ($onItem === null) {
            return;
        }

        $onItem($path, $action, $index, $total);
    }

    /**
     * Remove empty directories under a root after file deletion.
     *
     * @param string $root Absolute path to the directory to prune.
     *
     * @return void
     */
    private function pruneEmptyDirectories(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $node */
        foreach ($iterator as $node) {
            if (!$node->isDir()) {
                continue;
            }

            $path = $node->getPathname();
            if (@rmdir($path) === false) {
                // Non-empty or permission — leave in place.
            }
        }
    }
}
