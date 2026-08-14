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
    /**
     * Whether anonymous requests are allowed.
     *
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the read-only settings overview.
     *
     * @return Response The rendered CP template response.
     */
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
