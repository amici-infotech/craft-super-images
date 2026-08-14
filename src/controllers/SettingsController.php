<?php
/**
 * Control Panel settings overview for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Settings Controller
 *
 * Read-only overview — PHP config (`config/super-images.php`) is the source of truth.
 */
class SettingsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:manage-settings');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        return $this->renderTemplate('super-images/settings/index', [
            'settings' => $settings,
            'binaries' => $plugin->getBinaryResolver()->inventory(),
            'drivers' => $plugin->getDriverManager()->all(),
        ]);
    }
}
