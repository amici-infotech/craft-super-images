<?php

namespace amici\SuperImages\services;

use amici\SuperImages\jobs\GenerateAssetJob;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use craft\helpers\ElementHelper;
use yii\base\Component;

/**
 * Auto-enqueues eager generation after asset saves.
 */
final class AutoGenerateService extends Component
{
    public function handleAfterSave(Asset $asset, bool $isNew): void
    {
        if (!$this->shouldEnqueue($asset, $isNew)) {
            return;
        }

        $this->enqueue($asset);
    }

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

    private function isImportOrMaintenance(): bool
    {
        if (Craft::$app->getIsUpdating()) {
            return true;
        }

        if (Craft::$app->getUpdates()->getIsCraftUpdatePending()) {
            return true;
        }

        return Craft::$app->getUpdates()->getIsPluginUpdatePending('super-images');
    }

    private function assetFileChanged(Asset $asset): bool
    {
        $dirty = $asset->getDirtyAttributes();

        return in_array('filename', $dirty, true)
            || in_array('kind', $dirty, true)
            || in_array('size', $dirty, true)
            || in_array('width', $dirty, true)
            || in_array('height', $dirty, true);
    }

    private function focalPointChanged(Asset $asset): bool
    {
        return in_array('focalPoint', $asset->getDirtyAttributes(), true);
    }
}
