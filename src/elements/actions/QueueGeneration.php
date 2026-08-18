<?php
/**
 * Queue Super Images generation for selected assets.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\elements\actions;

use amici\SuperImages\jobs\GenerateAssetJob;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;

/**
 * Queue Generation
 *
 * Enqueues {@see GenerateAssetJob} for each selected image asset.
 */
class QueueGeneration extends ImageAssetElementAction
{
    /**
     * Whether to regenerate derivatives that already exist.
     *
     * @var bool
     */
    public bool $force = false;

    /**
     * Returns the action display name.
     *
     * @return string
     */
    public static function displayName(): string
    {
        return Craft::t('super-images', 'Generate Transforms (Super Images)');
    }

    /**
     * Returns the gear-menu label.
     *
     * @return string
     */
    public function getTriggerLabel(): string
    {
        return static::displayName();
    }

    /**
     * Enqueues generation jobs for the selected image assets.
     *
     * @param ElementQueryInterface $query Selected asset query.
     *
     * @return bool True when at least one asset was queued.
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isEnabled()) {
            $this->setMessage(Craft::t('super-images', 'Super Images is disabled.'));

            return false;
        }

        $queued = 0;

        foreach (Db::each($query) as $element) {
            if (!$element instanceof Asset || $element->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            Craft::$app->getQueue()->push(new GenerateAssetJob([
                'assetId' => (int) $element->id,
                'force' => $this->force,
            ]));
            $queued++;
        }

        if ($queued === 0) {
            $this->setMessage(Craft::t('super-images', 'No image assets were queued.'));

            return false;
        }

        $this->setMessage(Craft::t(
            'super-images',
            '{count, number} {count, plural, =1{asset} other{assets}} queued for Super Images generation.',
            ['count' => $queued],
        ));

        return true;
    }
}
