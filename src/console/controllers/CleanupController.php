<?php
/**
 * Console cleanup command for Super Images preview and generated derivatives.
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
 * Cleans up Super Images derivatives: stale Playground previews (default),
 * a single asset's derivatives, orphaned derivatives whose Craft asset no
 * longer exists, or every generated derivative.
 *
 * Always dry-runs first. Deleting requires both `--dry-run=0` and `--force=1`.
 *
 *     # Preview cleanup (default mode) — safe, short retention (2 days).
 *     php craft super-images/cleanup
 *     php craft super-images/cleanup --dry-run=0 --force=1
 *     php craft super-images/cleanup --retention-days=7 --dry-run=0 --force=1
 *
 *     # One asset's derivatives (e.g. before a manual re-upload).
 *     php craft super-images/cleanup --asset=123 --dry-run=0 --force=1
 *
 *     # Derivatives whose Craft asset was hard-deleted (bypassing Craft's
 *     # delete hook). Respects cleanup.generatedRetentionDays (1 year by default).
 *     php craft super-images/cleanup --orphaned=1 --dry-run=0 --force=1
 *     php craft super-images/cleanup --orphaned=1 --retention-days=0 --dry-run=0 --force=1
 *
 *     # Nuclear option: every generated derivative except previews. Use after
 *     # a profile/geometry config change makes existing output obsolete.
 *     php craft super-images/cleanup --all=1 --dry-run=0 --force=1
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
     * Required with --dry-run=0 to actually delete.
     *
     * @var int
     */
    public int $force = 0;

    /**
     * Purge derivatives for a single Craft asset ID.
     *
     * @var int|null
     */
    public ?int $asset = null;

    /**
     * Purge derivatives whose Craft asset no longer exists.
     *
     * @var bool
     */
    public bool $orphaned = false;

    /**
     * Purge every generated derivative (excluding Playground previews).
     *
     * @var bool
     */
    public bool $all = false;

    /**
     * Kept for backwards compatibility; preview cleanup is the default mode
     * and this flag is a no-op unless one of --asset/--orphaned/--all is set.
     *
     * @var int
     */
    public int $previewsOnly = 1;

    /**
     * Override retention days for the selected mode:
     * `cleanup.previewRetentionDays` for the default preview mode, or
     * `cleanup.generatedRetentionDays` for `--orphaned`/`--all`.
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
            'force',
            'asset',
            'orphaned',
            'all',
            'previewsOnly',
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
            'a' => 'asset',
        ];
    }

    /**
     * Runs the selected cleanup mode and prints a JSON summary.
     *
     * Mode precedence when multiple flags are set: `--asset` › `--orphaned` › `--all` › preview (default).
     *
     * @return int Console exit code.
     */
    public function actionIndex(): int
    {
        $dryRun = $this->dryRun !== 0;

        if (!$dryRun && $this->force === 0) {
            $this->stderr(
                "Refusing to delete without --force=1 when --dry-run=0.\n",
                Console::FG_RED,
            );

            return ExitCode::DATAERR;
        }

        $cleanup = Plugin::getInstance()->getCleanup();

        [$mode, $result] = match (true) {
            $this->asset !== null => ['asset', $cleanup->purgeAssetDerivatives($this->asset, $dryRun)],
            $this->orphaned => ['orphaned', $cleanup->purgeOrphanedDerivatives($dryRun, $this->retentionDays)],
            $this->all => ['all', $cleanup->purgeAllDerivatives($dryRun, $this->retentionDays)],
            default => ['previews', $cleanup->cleanupPreviews($dryRun, $this->retentionDays)],
        };

        $this->stdout(sprintf("Mode: %s\n", $mode), Console::FG_CYAN);
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
