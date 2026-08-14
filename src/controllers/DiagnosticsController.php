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
 *
 * Renders doctor checks, summary data, and binary inventory in the CP.
 */
class DiagnosticsController extends Controller
{
    /**
     * Whether anonymous requests are allowed.
     *
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the diagnostics page.
     *
     * @return Response The rendered CP template response.
     */
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
