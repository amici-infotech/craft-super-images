<?php
/**
 * Console status command for Super Images runtime state.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\Plugin;
use Craft;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\Json;

/**
 * Status Controller
 *
 * Reports Super Images runtime status as JSON.
 *
 *     php craft super-images/status
 */
class StatusController extends Controller
{
    /**
     * Prints enabled state, delivery mode, driver selection, and queue depth.
     *
     * @return int Console exit code.
     */
    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $driverManager = $plugin->getDriverManager();
        $selected = $driverManager->select();

        $queuePending = null;
        if (Craft::$app->getDb()->getSchema()->getTableSchema('{{%queue}}') !== null) {
            $queuePending = (int) Craft::$app->getDb()
                ->createCommand("SELECT COUNT(*) FROM {{%queue}} WHERE [[fail]] = 0")
                ->queryScalar();
        }

        $output = [
            'enabled' => $settings->enabled,
            'generateBeforePageLoad' => Plugin::getInstance()->getDeliveryUrls()->generatesBeforePageLoad(),
            'storageDefault' => $settings->storage['default'] ?? 'local',
            'autoGenerate' => $settings->autoGenerate,
            'runtime' => [
                'enabled' => $settings->runtime['enabled'] ?? true,
                'urlTtl' => $settings->runtime['urlTtl'] ?? 3600,
            ],
            'drivers' => [
                'selected' => $selected->name(),
                'formats' => $selected->capabilities()->formats,
            ],
            'queuePending' => $queuePending,
        ];

        $this->stdout(Json::encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
