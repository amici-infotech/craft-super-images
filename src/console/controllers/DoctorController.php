<?php
/**
 * Console doctor command for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\Plugin;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Runs Super Images doctor checks.
 *
 *     php craft super-images/doctor
 */
class DoctorController extends Controller
{
    public function actionIndex(): int
    {
        $checks = Plugin::getInstance()->getDiagnostics()->runDoctor();
        $exit = ExitCode::OK;

        foreach ($checks as $check) {
            $status = strtoupper($check['status']);
            $color = match ($check['status']) {
                'pass' => Console::FG_GREEN,
                'warn' => Console::FG_YELLOW,
                'fail' => Console::FG_RED,
                default => (static function (string $status): never {
                    throw new \UnhandledMatchError($status);
                })($check['status']),
            };

            if ($check['status'] === 'fail') {
                $exit = ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout(str_pad($status, 4), $color, Console::BOLD);
            $this->stdout('  ' . $check['label'] . "\n");
            $this->stdout('      ' . $check['detail'] . "\n\n", Console::FG_GREY);
        }

        $summary = Plugin::getInstance()->getDiagnostics()->dashboardSummary()['doctor'];
        $this->stdout(sprintf(
            "Summary: %d pass · %d warn · %d fail\n",
            $summary['pass'],
            $summary['warn'],
            $summary['fail'],
        ), Console::FG_CYAN);

        return $exit;
    }
}
