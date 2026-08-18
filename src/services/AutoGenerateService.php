<?php
/**
 * Auto-enqueues eager generation after asset saves.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\jobs\GenerateAssetJob;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use craft\helpers\ElementHelper;
use yii\base\Component;

/**
 * Auto Generate Service
 *
 * Listens for asset save events and enqueues or synchronously runs derivative
 * generation when autoGenerate settings and volume rules allow it.
 */
final class AutoGenerateService extends Component
{
    /**
     * File/focal-point change flags captured during BEFORE_SAVE (dirty attrs are cleared by AFTER_SAVE).
     *
     * @var array<int, array{file: bool, focal: bool}>
     */
    private static array $pendingSaveFlags = [];

    /**
     * Capture file/focal-point change flags before the asset is saved.
     *
     * @param Asset $asset The asset about to be saved.
     *
     * @return void
     */
    public function registerSaveFlags(Asset $asset): void
    {
        if (!$asset->id) {
            return;
        }

        $dirty = $asset->getDirtyAttributes();
        $fileChanged = $this->detectPendingFileChange($asset, $dirty);
        $focalChanged = in_array('focalPoint', $dirty, true);

        if (!$fileChanged && !$focalChanged) {
            return;
        }

        self::$pendingSaveFlags[(int) $asset->id] = [
            'file' => $fileChanged,
            'focal' => $focalChanged,
        ];
    }

    /**
     * Handle an asset after-save event and enqueue generation when appropriate.
     *
     * @param Asset $asset The saved asset element.
     * @param bool $isNew True when the asset was newly created.
     *
     * @return void
     */
    public function handleAfterSave(Asset $asset, bool $isNew): void
    {
        try {
            if (!$this->shouldEnqueue($asset, $isNew)) {
                return;
            }

            if (!$isNew && $this->assetFileChanged($asset)) {
                $this->purgeOnReplaceIfEnabled($asset);
            }

            $this->enqueue($asset);
        } finally {
            $this->clearSaveFlags($asset);
        }
    }

    /**
     * Determine whether generation should be enqueued for this asset save.
     *
     * @param Asset $asset The saved asset element.
     * @param bool $isNew True when the asset was newly created.
     *
     * @return bool True when auto-generate rules match this save.
     */
    public function shouldEnqueue(Asset $asset, bool $isNew): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled) {
            return false;
        }

        $auto = $settings->autoGenerate;

        if (!($auto['enabled'] ?? false)) {
            return false;
        }

        if (($auto['disableDuringImport'] ?? true) && $this->isImportOrMaintenance()) {
            return false;
        }

        if ($asset->kind !== Asset::KIND_IMAGE) {
            return false;
        }

        $volumeHandle = $asset->getVolume()->handle;
        $volumeConfig = $settings->volumes[$volumeHandle] ?? [];

        if (array_key_exists('autoGenerate', $volumeConfig) && !$volumeConfig['autoGenerate']) {
            return false;
        }

        if ($isNew) {
            return (bool) ($auto['onUpload'] ?? true);
        }

        if (ElementHelper::isDraftOrRevision($asset)) {
            return false;
        }

        if ($this->assetFileChanged($asset) && ($auto['onReplace'] ?? true)) {
            return true;
        }

        if ($this->focalPointChanged($asset) && ($auto['onFocalPointChange'] ?? true)) {
            return true;
        }

        return false;
    }

    /**
     * Enqueue or synchronously run generation for all manifest units of an asset.
     *
     * @param Asset $asset The asset to generate derivatives for.
     *
     * @return void
     */
    public function enqueue(Asset $asset): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $useQueue = (bool) ($settings->autoGenerate['queue'] ?? true);

        if ($useQueue) {
            Craft::$app->getQueue()->push(new GenerateAssetJob([
                'assetId' => (int) $asset->id,
            ]));

            return;
        }

        $units = Plugin::getInstance()->getManifest()->buildForAsset($asset);
        $generation = Plugin::getInstance()->getGeneration();

        foreach ($units as $unit) {
            $generation->generate($unit->toGenerationRequest());
        }
    }

    /**
     * Check whether Craft is updating, migrating, or has pending Super Images migrations.
     *
     * @return bool True during import/maintenance windows when auto-generate should be suppressed.
     */
    private function isImportOrMaintenance(): bool
    {
        if (Craft::$app->getIsInMaintenanceMode()) {
            return true;
        }

        $updates = Craft::$app->getUpdates();

        if ($updates->getIsCraftUpdatePending()) {
            return true;
        }

        $plugin = Plugin::getInstance();

        return Craft::$app->getPlugins()->isPluginUpdatePending($plugin);
    }

    /**
     * Check whether the asset file metadata changed on this save.
     *
     * @param Asset $asset The saved asset element.
     *
     * @return bool True when filename, kind, size, or dimensions are dirty.
     */
    private function assetFileChanged(Asset $asset): bool
    {
        $pending = $this->pendingSaveFlags($asset);

        if ($pending !== null) {
            return $pending['file'];
        }

        $dirty = $asset->getDirtyAttributes();

        return in_array('filename', $dirty, true)
            || in_array('kind', $dirty, true)
            || in_array('size', $dirty, true)
            || in_array('width', $dirty, true)
            || in_array('height', $dirty, true);
    }

    /**
     * Check whether the asset focal point changed on this save.
     *
     * @param Asset $asset The saved asset element.
     *
     * @return bool True when focalPoint is in the dirty attributes list.
     */
    private function focalPointChanged(Asset $asset): bool
    {
        $pending = $this->pendingSaveFlags($asset);

        if ($pending !== null) {
            return $pending['focal'];
        }

        return in_array('focalPoint', $asset->getDirtyAttributes(), true);
    }

    /**
     * Detect whether the asset file will change on the pending save.
     *
     * @param Asset $asset The asset about to be saved.
     * @param list<string> $dirty Dirty attribute names from before save.
     *
     * @return bool True when a new upload or replace will change the stored file.
     */
    private function detectPendingFileChange(Asset $asset, array $dirty): bool
    {
        if (
            $asset->tempFilePath !== null
            && in_array($asset->getScenario(), [Asset::SCENARIO_CREATE, Asset::SCENARIO_REPLACE], true)
        ) {
            return true;
        }

        return in_array('filename', $dirty, true)
            || in_array('kind', $dirty, true)
            || in_array('size', $dirty, true)
            || in_array('width', $dirty, true)
            || in_array('height', $dirty, true);
    }

    /**
     * Return pending save flags for an asset, if any were captured.
     *
     * @param Asset $asset The saved asset element.
     *
     * @return array{file: bool, focal: bool}|null
     */
    private function pendingSaveFlags(Asset $asset): ?array
    {
        if (!$asset->id) {
            return null;
        }

        return self::$pendingSaveFlags[(int) $asset->id] ?? null;
    }

    /**
     * Clear pending save flags after an after-save handler finishes.
     *
     * @param Asset $asset The saved asset element.
     *
     * @return void
     */
    private function clearSaveFlags(Asset $asset): void
    {
        if (!$asset->id) {
            return;
        }

        unset(self::$pendingSaveFlags[(int) $asset->id]);
    }

    /**
     * Purges indexed derivatives when asset-replace cleanup policy is enabled.
     *
     * Failures are logged and do not block subsequent generation enqueue.
     *
     * @param Asset $asset The asset whose file was replaced.
     *
     * @return void
     */
    private function purgeOnReplaceIfEnabled(Asset $asset): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $cleanupPolicy = $settings->policies['cleanup'] ?? [];

        if (!($cleanupPolicy['onAssetReplace'] ?? true)) {
            return;
        }

        try {
            Plugin::getInstance()->getCleanup()->purgeAssetDerivatives((int) $asset->id);
        } catch (\Throwable $exception) {
            Craft::warning(
                sprintf(
                    'Failed to purge derivatives for replaced asset %d: %s',
                    (int) $asset->id,
                    $exception->getMessage(),
                ),
                __METHOD__,
            );
        }
    }
}
