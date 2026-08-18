<?php
/**
 * Base element action helpers for image assets in the Assets index.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\elements\actions;

use Craft;
use craft\base\ElementAction;

/**
 * Image Asset Element Action
 *
 * Shared trigger wiring for bulk actions that only apply to image assets.
 */
abstract class ImageAssetElementAction extends ElementAction
{
    /**
     * Registers the bulk image-only element action trigger.
     *
     * @return string|null Always null; JS is registered directly.
     */
    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn($type) => <<<JS
(() => {
    new Craft.ElementActionTrigger({
        type: $type,
        bulk: true,
        validateSelection: (selectedItems) => {
            for (let i = 0; i < selectedItems.length; i++) {
                const element = selectedItems.eq(i).find('.element');

                if (Garnish.hasAttr(element, 'data-is-folder')) {
                    return false;
                }

                if (element.data('kind') !== 'image') {
                    return false;
                }
            }

            return true;
        },
    });
})();
JS, [static::class]);

        return null;
    }
}
