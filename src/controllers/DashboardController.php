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
 *
 * Renders the Super Images operational overview in the Control Panel.
 */
class DashboardController extends Controller
{
    /**
     * Whether anonymous requests are allowed.
     *
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the Super Images dashboard.
     *
     * @return Response The rendered CP template response.
     */
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
