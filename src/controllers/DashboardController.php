<?php
/**
 * Control Panel dashboard for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Dashboard Controller
 */
class DashboardController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:view');

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('super-images/dashboard/index', [
            'summary' => $plugin->getDiagnostics()->dashboardSummary(),
            'settings' => $plugin->getSettings(),
        ]);
    }
}
