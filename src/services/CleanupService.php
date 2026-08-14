<?php
/**
 * Conservative cleanup for Playground preview artifacts.
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
 * Scans and optionally deletes expired preview derivatives under the `preview/`
 * storage prefix, purges indexed derivatives for a specific or orphaned Craft
 * asset, and (when explicitly requested) sweeps every generated derivative.
 * Never touches originals.
 */
class CleanupService extends Component
{
    /** Storage path prefix for preview artifacts; only paths under this prefix are eligible. */
    private const PREVIEW_PREFIX = 'preview/';

    /** Maximum number of path entries returned in cleanup results. */
    private const LIST_LIMIT = 100;

    /**
     * Scan and optionally delete expired preview derivatives.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention; defaults to cleanup.previewRetentionDays from settings.
     *
     * @return array<string, mixed> Cleanup report with counts, paths, and skip reason when applicable.
     */
    public function cleanupPreviews(bool $dryRun = true, ?int $retentionDays = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $retentionDays ??= (int) ($settings->cleanup['previewRetentionDays'] ?? 2);
        $retentionDays = max(0, $retentionDays);
        $cutoff = time() - ($retentionDays * 86400);
        $allowRemoteScan = (bool) ($settings->cleanup['allowRemoteScan'] ?? false);

        $defaultName = (string) ($settings->storage['default'] ?? 'local');
        $adapterConfig = $settings->storage['adapters'][$defaultName] ?? null;

        $result = [
            'dryRun' => $dryRun,
            'retentionDays' => $retentionDays,
            'cutoff' => $cutoff,
            'adapter' => $defaultName,
            'skipped' => false,
            'reason' => null,
            'candidates' => 0,
            'deleted' => 0,
            'skippedFresh' => 0,
            'errors' => 0,
            'paths' => [],
            'pathsTruncated' => false,
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
                ? 'Remote storage listing is not implemented; preview cleanup only supports local adapters.'
                : 'Remote storage scan skipped (cleanup.allowRemoteScan is false). Preview cleanup only runs on local adapters.';

            return $result;
        }

        $root = PathGuard::canonicalize(
            Craft::getAlias((string) ($adapterConfig['path'] ?? '@webroot/uploads/super-images'))
        );
        $previewRoot = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'preview';

        if (!is_dir($previewRoot)) {
            $result['reason'] = sprintf('No preview directory found at %s', $previewRoot);

            return $result;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($previewRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($root))), '/');

            if (!$this->isSafePreviewPath($relative)) {
                $result['errors']++;
                continue;
            }

            $mtime = (int) $file->getMTime();
            if ($mtime >= $cutoff) {
                $result['skippedFresh']++;
                continue;
            }

            $result['candidates']++;
            $this->appendPath($result, $relative, $dryRun ? 'candidate' : 'deleted');

            if ($dryRun) {
                continue;
            }

            if (@unlink($fullPath)) {
                $result['deleted']++;
            } else {
                $result['errors']++;
            }
        }

        if (!$dryRun) {
            $this->pruneEmptyDirectories($previewRoot);
        }

        return $result;
    }

    /**
     * Delete all indexed derivatives for a Craft asset.
     *
     * Reads the per-asset derivative index, deletes storage objects and existence
     * markers, then clears the index when not in dry-run mode.
     *
     * @param int $assetId Craft asset element ID.
     * @param bool $dryRun When true, report candidates without deleting files.
     *
     * @return array<string, mixed> Cleanup report with candidates, deleted, errors, and paths.
     */
    public function purgeAssetDerivatives(int $assetId, bool $dryRun = false): array
    {
        $plugin = Plugin::getInstance();
        $entries = $plugin->getAssetDerivativeIndex()->entries($assetId);

        $result = [
            'dryRun' => $dryRun,
            'assetId' => $assetId,
            'candidates' => count($entries),
            'deleted' => 0,
            'errors' => 0,
            'paths' => [],
            'pathsTruncated' => false,
        ];

        foreach ($entries as $entry) {
            $identity = $entry['identity'];
            $storagePath = $entry['storagePath'];
            $adapterName = $entry['adapter'] !== ''
                ? $entry['adapter']
                : (string) ($plugin->getSettings()->storage['default'] ?? 'local');

            $this->appendPath($result, $storagePath, $dryRun ? 'candidate' : 'deleted');

            if ($dryRun) {
                continue;
            }

            try {
                $adapter = $plugin->getStorageManager()->select($adapterName);
                $adapter->delete($storagePath);
                $plugin->getExistenceMarkers()->delete($identity);
                $result['deleted']++;
            } catch (\Throwable $exception) {
                $result['errors']++;
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
     * Iterates every indexed asset ID, checks whether the Craft element still
     * exists (including trashed/disabled), and purges the index's derivatives
     * for any asset that is genuinely gone. This is the fix for indexed
     * derivatives surviving hard deletes that bypass the
     * {@see \craft\elements\Asset::EVENT_BEFORE_DELETE} hook (e.g. direct
     * database removal, or an index written before the hook was added).
     *
     * Respects `cleanup.generatedRetentionDays` (default 365) so a derivative
     * is never removed before it has aged past the configured retention window,
     * even when its asset is orphaned — set `$retentionDays` to `0` to disable
     * this safety net for a single run.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention; defaults to cleanup.generatedRetentionDays from settings.
     *
     * @return array<string, mixed> Aggregated cleanup report across all orphaned assets.
     */
    public function purgeOrphanedDerivatives(bool $dryRun = true, ?int $retentionDays = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $retentionDays ??= (int) ($settings->cleanup['generatedRetentionDays'] ?? 365);
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
            'paths' => [],
            'pathsTruncated' => false,
        ];

        if ($assetIds === []) {
            return $result;
        }

        $existingIds = array_map('intval', Asset::find()
            ->id($assetIds)
            ->status(null)
            ->trashed(null)
            ->ids());

        foreach ($assetIds as $assetId) {
            if (in_array($assetId, $existingIds, true)) {
                continue;
            }

            $updatedAt = $index->updatedAt($assetId);
            if ($updatedAt !== null && $updatedAt >= $cutoff) {
                $result['assetsSkippedFresh']++;

                continue;
            }

            $result['assetsOrphaned']++;
            $assetReport = $this->purgeAssetDerivatives($assetId, $dryRun);

            $result['candidates'] += $assetReport['candidates'];
            $result['deleted'] += $assetReport['deleted'];
            $result['errors'] += $assetReport['errors'];

            foreach ($assetReport['paths'] as $entry) {
                $this->appendPath($result, sprintf('asset#%d: %s', $assetId, $entry['path']), $entry['action']);
            }
            if ($assetReport['pathsTruncated']) {
                $result['pathsTruncated'] = true;
            }
        }

        return $result;
    }

    /**
     * Purge every generated derivative under the default local storage adapter,
     * except Playground previews (use {@see cleanupPreviews()} for those).
     *
     * This is the "start clean" hammer for after a profile/geometry config
     * change makes existing derivatives obsolete. Respects
     * `cleanup.generatedRetentionDays` via mtime, same as
     * {@see purgeOrphanedDerivatives()}. Only supports local adapters; run
     * per-asset purges for remote storage instead.
     *
     * @param bool $dryRun When true, report candidates without deleting files.
     * @param int|null $retentionDays Override retention; defaults to cleanup.generatedRetentionDays from settings.
     *
     * @return array<string, mixed> Cleanup report with counts, paths, and skip reason when applicable.
     */
    public function purgeAllDerivatives(bool $dryRun = true, ?int $retentionDays = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $retentionDays ??= (int) ($settings->cleanup['generatedRetentionDays'] ?? 365);
        $retentionDays = max(0, $retentionDays);
        $cutoff = time() - ($retentionDays * 86400);

        $defaultName = (string) ($settings->storage['default'] ?? 'local');
        $adapterConfig = $settings->storage['adapters'][$defaultName] ?? null;

        $result = [
            'dryRun' => $dryRun,
            'retentionDays' => $retentionDays,
            'cutoff' => $cutoff,
            'adapter' => $defaultName,
            'skipped' => false,
            'reason' => null,
            'candidates' => 0,
            'deleted' => 0,
            'skippedFresh' => 0,
            'errors' => 0,
            'paths' => [],
            'pathsTruncated' => false,
        ];

        if (!is_array($adapterConfig)) {
            $result['skipped'] = true;
            $result['reason'] = sprintf('Default storage adapter "%s" is not configured.', $defaultName);

            return $result;
        }

        $type = (string) ($adapterConfig['type'] ?? 'local');
        if ($type !== 'local') {
            $result['skipped'] = true;
            $result['reason'] = 'Full derivative sweep only supports local adapters. Purge remote derivatives per-asset instead.';

            return $result;
        }

        $root = PathGuard::canonicalize(
            Craft::getAlias((string) ($adapterConfig['path'] ?? '@webroot/uploads/super-images'))
        );

        if (!is_dir($root)) {
            $result['reason'] = sprintf('No storage directory found at %s', $root);

            return $result;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fullPath = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($fullPath, strlen($root))), '/');

            if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, self::PREVIEW_PREFIX)) {
                continue;
            }

            $mtime = (int) $file->getMTime();
            if ($mtime >= $cutoff) {
                $result['skippedFresh']++;

                continue;
            }

            $result['candidates']++;
            $this->appendPath($result, $relative, $dryRun ? 'candidate' : 'deleted');

            if ($dryRun) {
                continue;
            }

            if (@unlink($fullPath)) {
                $result['deleted']++;
            } else {
                $result['errors']++;
            }
        }

        if (!$dryRun) {
            $this->pruneEmptyDirectories($root);

            if ($result['errors'] === 0) {
                $plugin->getAssetDerivativeIndex()->clearAll();
            }
        }

        return $result;
    }

    /**
     * Validate that a relative storage path is safe for preview cleanup.
     *
     * @param string $relative Path relative to the storage root.
     *
     * @return bool True when the path starts with preview/ and contains no traversal segments.
     */
    private function isSafePreviewPath(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return false;
        }

        return str_starts_with($relative, self::PREVIEW_PREFIX);
    }

    /**
     * Append a path entry to the cleanup result, respecting LIST_LIMIT.
     *
     * @param array<string, mixed> $result Cleanup result array passed by reference.
     * @param string $path Relative storage path.
     * @param string $action Action label (candidate or deleted).
     *
     * @return void
     */
    private function appendPath(array &$result, string $path, string $action): void
    {
        if (count($result['paths']) >= self::LIST_LIMIT) {
            $result['pathsTruncated'] = true;

            return;
        }

        $result['paths'][] = [
            'path' => $path,
            'action' => $action,
        ];
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
