<?php
/**
 * Clear Super Images derivatives for selected assets.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\elements\actions;

use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Db;

/**
 * Clear Derivatives
 *
 * Deletes indexed Super Images derivatives for each selected image asset.
 */
class ClearDerivatives extends ImageAssetElementAction
{
    /**
     * Returns the action display name.
     *
     * @return string
     */
    public static function displayName(): string
    {
        return Craft::t('super-images', 'Clear Cache (Super Images)');
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
     * @inheritdoc
     */
    public static function isDestructive(): bool
    {
        return true;
    }

    /**
     * Returns the confirmation prompt shown before clearing derivatives.
     *
     * @return string|null
     */
    public function getConfirmationMessage(): ?string
    {
        return Craft::t(
            'super-images',
            'Are you sure you want to clear Super Images derivatives for the selected assets?',
        );
    }

    /**
     * Purges derivatives for the selected image assets.
     *
     * @param ElementQueryInterface $query Selected asset query.
     *
     * @return bool True when at least one asset was cleared.
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isEnabled()) {
            $this->setMessage(Craft::t('super-images', 'Super Images is disabled.'));

            return false;
        }

        $cleanup = $plugin->getCleanup();
        $cleared = 0;
        $failed = 0;

        foreach (Db::each($query) as $element) {
            if (!$element instanceof Asset || $element->kind !== Asset::KIND_IMAGE) {
                continue;
            }

            $result = $cleanup->purgeAssetDerivatives((int) $element->id);

            if (($result['errors'] ?? 0) > 0) {
                $failed++;
                continue;
            }

            $cleared++;
        }

        if ($cleared === 0 && $failed === 0) {
            $this->setMessage(Craft::t('super-images', 'No image assets were cleared.'));

            return false;
        }

        if ($failed > 0) {
            $this->setMessage(Craft::t(
                'super-images',
                'Cleared derivatives for {cleared, number} {cleared, plural, =1{asset} other{assets}} with {failed, number} {failed, plural, =1{failure} other{failures}}.',
                [
                    'cleared' => $cleared,
                    'failed' => $failed,
                ],
            ));

            return $cleared > 0;
        }

        $this->setMessage(Craft::t(
            'super-images',
            'Cleared Super Images derivatives for {count, number} {count, plural, =1{asset} other{assets}}.',
            ['count' => $cleared],
        ));

        return true;
    }
}
