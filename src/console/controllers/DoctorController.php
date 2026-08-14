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
 * Doctor Controller
 *
 * Runs Super Images doctor checks.
 *
 *     php craft super-images/doctor
 *     php craft super-images/doctor --json=1
 */
class DoctorController extends Controller
{
    /**
     * Emit machine-readable JSON instead of formatted text.
     *
     * @var int
     */
    public int $json = 0;

    /**
     * Returns the list of options available for this command.
     *
     * @param string $actionID The action ID of the controller.
     *
     * @return list<string> Option property names.
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    /**
     * Runs doctor checks and prints a formatted or JSON report.
     *
     * @return int Console exit code; non-zero when any check fails.
     */
    public function actionIndex(): int
    {
        $diagnostics = Plugin::getInstance()->getDiagnostics();
        $report = $diagnostics->doctorReport();

        if ($this->json) {
            $this->stdout(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

            return $report['summary']['fail'] > 0
                ? ExitCode::UNSPECIFIED_ERROR
                : ExitCode::OK;
        }

        $this->renderHeader();
        $labelWidth = $this->maxLabelWidth($report['groups']);

        foreach ($report['groups'] as $group) {
            $this->renderGroupHeading($group['label']);

            foreach ($group['checks'] as $check) {
                $this->renderCheck($check, $labelWidth);
            }

            $this->stdout("\n");
        }

        $this->renderSummary($report['summary']);

        return $report['summary']['fail'] > 0
            ? ExitCode::UNSPECIFIED_ERROR
            : ExitCode::OK;
    }

    /**
     * Prints the doctor report header.
     *
     * @return void
     */
    private function renderHeader(): void
    {
        $this->stdout("\n");
        $this->stdout('Super Images · Doctor', Console::BOLD);
        $this->stdout("\n");
        $this->stdout(str_repeat('═', 42) . "\n\n", Console::FG_GREY);
    }

    /**
     * Prints a check group heading.
     *
     * @param string $label Group label.
     *
     * @return void
     */
    private function renderGroupHeading(string $label): void
    {
        $this->stdout($label, Console::BOLD);
        $this->stdout("\n");
        $this->stdout(str_repeat('─', max(12, mb_strlen($label))) . "\n", Console::FG_GREY);
    }

    /**
     * Prints a single doctor check row with optional fix hint.
     *
     * @param array{id: string, group: string, status: string, label: string, detail: string, solution?: ?string} $check Check definition.
     * @param int $labelWidth Column width for check labels.
     *
     * @return void
     */
    private function renderCheck(array $check, int $labelWidth): void
    {
        [$glyph, $color] = match ($check['status']) {
            'pass' => ['✓', Console::FG_GREEN],
            'warn' => ['!', Console::FG_YELLOW],
            'fail' => ['✗', Console::FG_RED],
            default => (static function (string $status): never {
                throw new \UnhandledMatchError($status);
            })($check['status']),
        };

        $this->stdout('  ');
        $this->stdout($glyph, $color, Console::BOLD);
        $this->stdout('  ');
        $this->stdout(str_pad($check['label'], $labelWidth));
        $this->stdout('  ', Console::FG_GREY);
        $this->stdout($check['detail'] . "\n", Console::FG_GREY);

        $solution = $check['solution'] ?? null;
        if (is_string($solution) && $solution !== '' && in_array($check['status'], ['warn', 'fail'], true)) {
            $this->stdout(str_repeat(' ', $labelWidth + 6));
            $this->stdout('→ Fix: ', Console::FG_CYAN, Console::BOLD);
            $this->stdout($solution . "\n", Console::FG_CYAN);
        }
    }

    /**
     * Prints the pass/warn/fail summary footer.
     *
     * @param array{pass: int, warn: int, fail: int, total: int} $summary Result counts.
     *
     * @return void
     */
    private function renderSummary(array $summary): void
    {
        $this->stdout(str_repeat('─', 42) . "\n", Console::FG_GREY);
        $this->stdout('Result  ', Console::BOLD);

        $parts = [];
        $parts[] = [$summary['pass'] . ' passed', Console::FG_GREEN];
        $parts[] = [$summary['warn'] . ' warnings', Console::FG_YELLOW];
        $parts[] = [$summary['fail'] . ' failed', $summary['fail'] > 0 ? Console::FG_RED : Console::FG_GREY];

        foreach ($parts as $index => [$text, $color]) {
            if ($index > 0) {
                $this->stdout(' · ', Console::FG_GREY);
            }
            $this->stdout($text, $color);
        }

        $this->stdout("\n\n");
    }

    /**
     * Computes the label column width for aligned check output.
     *
     * @param list<array{id: string, label: string, checks: list<array{label: string}>}> $groups Doctor report groups.
     *
     * @return int Label column width (capped at 22).
     */
    private function maxLabelWidth(array $groups): int
    {
        $width = 8;

        foreach ($groups as $group) {
            foreach ($group['checks'] as $check) {
                $width = max($width, mb_strlen($check['label']));
            }
        }

        return min($width, 22);
    }
}
