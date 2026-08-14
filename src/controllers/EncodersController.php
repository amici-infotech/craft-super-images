<?php
/**
 * Control Panel encoders & optimizers capability page.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\Plugin;
use amici\SuperImages\support\UbuntuInstallHints;
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
            $name = $driver->name();
            $available = $driver->isAvailable();
            $driverRows[] = [
                'name' => $name,
                'available' => $available,
                'formats' => $driver->capabilities()->formats,
                'operations' => $driver->capabilities()->operations,
                'installHint' => $available ? null : UbuntuInstallHints::forDriver($name),
            ];
        }

        $binaryRows = [];
        foreach ($plugin->getBinaryResolver()->inventory() as $tool => $row) {
            $available = (bool) ($row['available'] ?? false);
            $binaryRows[$tool] = array_merge($row, [
                'installHint' => $available ? null : UbuntuInstallHints::forBinary($tool),
            ]);
        }

        return $this->renderTemplate('super-images/encoders/index', [
            'settings' => $settings,
            'drivers' => $driverRows,
            'binaries' => $binaryRows,
            'operations' => $plugin->getOperationRegistry()->names(),
        ]);
    }
}
