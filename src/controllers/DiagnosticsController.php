<?php
/**
 * Control Panel diagnostics for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\Plugin;
use craft\web\Controller;
use yii\web\Response;

/**
 * Diagnostics Controller
 */
class DiagnosticsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:diagnostics');

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('super-images/diagnostics/index', [
            'checks' => $plugin->getDiagnostics()->runDoctor(),
            'summary' => $plugin->getDiagnostics()->dashboardSummary(),
            'binaries' => $plugin->getBinaryResolver()->inventory(),
        ]);
    }
}
