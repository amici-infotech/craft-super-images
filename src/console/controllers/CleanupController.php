<?php
/**
 * Console cleanup command for Super Images derivatives.
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
 * Cleans up Super Images derivatives.
 *
 * Default: delete every transform older than retention
 * (`cleanup.generatedRetentionDays`). Runs for real unless `--dry-run=1`.
 *
 *     php craft super-images/cleanup
 *     php craft super-images/cleanup --dry-run=1
 *     php craft super-images/cleanup --retention-days=7
 *     php craft super-images/cleanup --all=1
 *     php craft super-images/cleanup --asset=123
 *     php craft super-images/cleanup --orphaned=1
 */
class CleanupController extends Controller
{
    /**
     * When true, list matches without deleting.
     *
     * @var bool
     */
    public bool $dryRun = false;

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
     * Delete every derivative immediately, ignoring retention.
     *
     * @var bool
     */
    public bool $all = false;

    /**
     * Temporary retention override (days) for aged / orphaned sweeps.
     * Ignored when `--all=1`.
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
            'asset',
            'orphaned',
            'all',
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
            'a' => 'asset',
        ];
    }

    /**
     * Runs the selected cleanup mode with generate-style progress output.
     *
     * Mode precedence: `--asset` › `--orphaned` › `--all` › aged (default).
     *
     * @return int Console exit code.
     */
    public function actionIndex(): int
    {
        $cleanup = Plugin::getInstance()->getCleanup();
        $startedAt = microtime(true);
        $onItem = $this->progressPrinter();

        if ($this->asset !== null) {
            $this->stdout(sprintf(
                "%s derivatives for asset #%d…\n\n",
                $this->dryRun ? 'Dry-run: listing' : 'Cleaning',
                $this->asset,
            ), Console::FG_CYAN);

            $result = $cleanup->purgeAssetDerivatives($this->asset, $this->dryRun, $onItem);
            $this->printSummary($result, $startedAt);

            return ($result['errors'] ?? 0) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
        }

        if ($this->orphaned) {
            $retention = $this->retentionLabel();
            $this->stdout(sprintf(
                "%s orphaned derivatives%s…\n\n",
                $this->dryRun ? 'Dry-run: listing' : 'Cleaning',
                $retention,
            ), Console::FG_CYAN);

            $result = $cleanup->purgeOrphanedDerivatives($this->dryRun, $this->retentionDays, $onItem);
            $this->printSummary($result, $startedAt);

            return ($result['errors'] ?? 0) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
        }

        if ($this->all) {
            $this->stdout(sprintf(
                "%s all derivatives (no retention check)…\n\n",
                $this->dryRun ? 'Dry-run: listing' : 'Cleaning',
            ), Console::FG_CYAN);

            $result = $cleanup->purgeStorageDerivatives($this->dryRun, ignoreRetention: true, onItem: $onItem);
        } else {
            $retention = $this->retentionLabel();
            $this->stdout(sprintf(
                "%s aged transforms%s…\n\n",
                $this->dryRun ? 'Dry-run: listing' : 'Cleaning',
                $retention,
            ), Console::FG_CYAN);

            $result = $cleanup->purgeStorageDerivatives($this->dryRun, $this->retentionDays, ignoreRetention: false, onItem: $onItem);
        }

        if (($result['skipped'] ?? false) === true) {
            $this->stdout((string) ($result['reason'] ?? 'Skipped') . "\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        if (($result['reason'] ?? null) !== null && ($result['candidates'] ?? 0) === 0 && ($result['skippedFresh'] ?? 0) === 0) {
            $this->stdout((string) $result['reason'] . "\n", Console::FG_YELLOW);
        }

        $this->printSummary($result, $startedAt);

        return ($result['errors'] ?? 0) > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Builds a progress line printer matching generate CLI style.
     *
     * @return callable(string, string, int, int): void
     */
    private function progressPrinter(): callable
    {
        return function (string $path, string $action, int $index, int $total): void {
            $progress = sprintf('[%d/%d]', $index, $total);
            $line = sprintf('  %s [%s] %s', $progress, $action, $path);

            match ($action) {
                'deleted' => $this->stdout($line . "\n", Console::FG_GREEN),
                'failed' => $this->stderr($line . "\n", Console::FG_RED),
                default => $this->stdout($line . "\n"),
            };
        };
    }

    /**
     * Human-readable retention suffix for the intro line.
     *
     * @return string Empty string or " (retention: N days)".
     */
    private function retentionLabel(): string
    {
        if ($this->retentionDays !== null) {
            return sprintf(' (retention: %d day%s)', $this->retentionDays, $this->retentionDays === 1 ? '' : 's');
        }

        $settings = Plugin::getInstance()->getSettings();
        $days = (int) (
            $settings->cleanup['generatedRetentionDays']
            ?? $settings->cleanup['obsoleteRetentionDays']
            ?? 365
        );

        return sprintf(' (retention: %d day%s)', $days, $days === 1 ? '' : 's');
    }

    /**
     * Prints a generate-style summary line.
     *
     * @param array<string, mixed> $result Cleanup report.
     * @param float $startedAt microtime(true) when the run started.
     *
     * @return void
     */
    private function printSummary(array $result, float $startedAt): void
    {
        $elapsed = microtime(true) - $startedAt;
        $candidates = (int) ($result['candidates'] ?? 0);

        if ($candidates === 0 && ($result['errors'] ?? 0) === 0) {
            $fresh = (int) ($result['skippedFresh'] ?? 0);
            $this->stdout(sprintf(
                "\nNothing to clean%s (%.1fs).\n",
                $fresh > 0 ? sprintf(' — %d file%s still within retention', $fresh, $fresh === 1 ? '' : 's') : '',
                $elapsed,
            ), Console::FG_CYAN);

            return;
        }

        $parts = [];

        if ($this->dryRun) {
            $parts[] = sprintf('matched=%d', $candidates);
        } else {
            $parts[] = sprintf('deleted=%d', (int) ($result['deleted'] ?? 0));
        }

        if (isset($result['skippedFresh'])) {
            $parts[] = sprintf('kept=%d', (int) $result['skippedFresh']);
        }

        if (isset($result['assetsOrphaned'])) {
            $parts[] = sprintf('assets=%d', (int) $result['assetsOrphaned']);
        }

        $parts[] = sprintf('failed=%d', (int) ($result['errors'] ?? 0));
        $parts[] = sprintf('(%.1fs)', $elapsed);

        $this->stdout("\nSummary: " . implode(' ', $parts) . "\n");
    }
}
