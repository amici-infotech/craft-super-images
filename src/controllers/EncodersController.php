<?php
/**
 * Control Panel encoders & optimizers capability page.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Encoders Controller
 */
class EncodersController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:view');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $driverRows = [];
        foreach ($plugin->getDriverManager()->all() as $driver) {
            $driverRows[] = [
                'name' => $driver->name(),
                'available' => $driver->isAvailable(),
                'formats' => $driver->capabilities()->formats,
                'operations' => $driver->capabilities()->operations,
            ];
        }

        return $this->renderTemplate('super-images/encoders/index', [
            'settings' => $settings,
            'drivers' => $driverRows,
            'binaries' => $plugin->getBinaryResolver()->inventory(),
            'operations' => $plugin->getOperationRegistry()->names(),
        ]);
    }
}
