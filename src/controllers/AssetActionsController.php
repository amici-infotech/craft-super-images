<?php
/**
 * CP actions for single-asset generate/clear from the asset detail page.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\jobs\GenerateAssetJob;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use yii\web\Response;

/**
 * Asset Actions Controller
 *
 * Handles AJAX requests from the asset detail page action menu.
 */
class AssetActionsController extends Controller
{
    /**
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Enqueues generation for a single asset.
     *
     * @return Response JSON response.
     */
    public function actionQueueGeneration(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('super-images:view');

        $assetId = (int) Craft::$app->getRequest()->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->kind('image')->status(null)->one();

        if ($asset === null) {
            return $this->asFailure('Asset not found.');
        }

        Craft::$app->getQueue()->push(new GenerateAssetJob([
            'assetId' => $assetId,
        ]));

        return $this->asSuccess(Craft::t(
            'super-images',
            'Queued transform generation for "{filename}".',
            ['filename' => $asset->getFilename()],
        ));
    }

    /**
     * Clears derivatives for a single asset.
     *
     * @return Response JSON response.
     */
    public function actionClearDerivatives(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('super-images:view');

        $assetId = (int) Craft::$app->getRequest()->getRequiredBodyParam('assetId');
        $asset = Asset::find()->id($assetId)->kind('image')->status(null)->one();

        if ($asset === null) {
            return $this->asFailure('Asset not found.');
        }

        $result = Plugin::getInstance()->getCleanup()->purgeAssetDerivatives($assetId);

        if (($result['errors'] ?? 0) > 0) {
            return $this->asFailure(Craft::t(
                'super-images',
                'Failed to clear some derivatives for "{filename}".',
                ['filename' => $asset->getFilename()],
            ));
        }

        return $this->asSuccess(Craft::t(
            'super-images',
            'Cleared {count, number} {count, plural, =1{derivative} other{derivatives}} for "{filename}".',
            [
                'count' => $result['deleted'] ?? 0,
                'filename' => $asset->getFilename(),
            ],
        ));
    }
}
