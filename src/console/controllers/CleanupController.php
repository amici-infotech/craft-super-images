<?php
/**
 * Console cleanup command for Super Images preview artifacts.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\Plugin;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\Json;

/**
 * Cleans Super Images preview storage.
 *
 *     php craft super-images/cleanup
 *     php craft super-images/cleanup --dry-run=1
 *     php craft super-images/cleanup --dry-run=0 --force=1
 *     php craft super-images/cleanup --previews-only
 */
class CleanupController extends Controller
{
    /**
     * Dry-run when 1 (default). Set 0 with --force to delete.
     *
     * @var int
     */
    public int $dryRun = 1;

    /**
     * Restrict to preview/ namespace cleanup (always true for now).
     *
     * @var int
     */
    public int $previewsOnly = 1;

    /**
     * Required with --dry-run=0 to actually delete.
     *
     * @var int
     */
    public int $force = 0;

    /**
     * Override `cleanup.previewRetentionDays` from config.
     *
     * @var int|null
     */
    public ?int $retentionDays = null;

    /**
     * Returns the list of options available for this command.
     *
     * @param string $actionID The action ID of the controller.
     *
     * @return list<string> Option property names.
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'dryRun',
            'previewsOnly',
            'force',
            'retentionDays',
        ]);
    }

    /**
     * Returns option name aliases.
     *
     * @return array<string, string> Alias map.
     */
    public function optionAliases(): array
    {
        return [
            'd' => 'dryRun',
            'f' => 'force',
        ];
    }

    /**
     * Removes stale preview artifacts and prints a JSON summary.
     *
     * @return int Console exit code.
     */
    public function actionIndex(): int
    {
        $dryRun = $this->dryRun !== 0;

        if (!$dryRun && $this->force === 0) {
            $this->stderr(
                "Refusing to delete without --force when --dry-run=0.\n",
                Console::FG_RED,
            );

            return ExitCode::DATAERR;
        }

        if ($this->previewsOnly === 0) {
            $this->stderr(
                "Only preview cleanup is implemented. Use --previews-only (default).\n",
                Console::FG_YELLOW,
            );
        }

        $result = Plugin::getInstance()->getCleanup()->cleanupPreviews(
            dryRun: $dryRun,
            retentionDays: $this->retentionDays,
        );

        $this->stdout(Json::encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if (($result['skipped'] ?? false) === true) {
            $this->stdout((string) ($result['reason'] ?? 'Skipped') . "\n", Console::FG_YELLOW);
        }

        if (($result['errors'] ?? 0) > 0) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
