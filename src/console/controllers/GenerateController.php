<?php
/**
 * Console generate command for Super Images eager generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\jobs\GenerateAssetJob;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Generate Controller
 *
 * Eager generation via CLI.
 *
 *     php craft super-images/generate --asset=123
 *     php craft super-images/generate --volume=images --profile=hero
 *     php craft super-images/generate --volume=images --queue --force
 */
class GenerateController extends Controller
{
    /**
     * Generate derivatives for a single asset ID.
     *
     * @var int|null
     */
    public ?int $asset = null;

    /**
     * Generate derivatives for all image assets in a volume handle.
     *
     * @var string|null
     */
    public ?string $volume = null;

    /**
     * Restrict generation to a profile handle.
     *
     * @var string|null
     */
    public ?string $profile = null;

    /**
     * Restrict generation to a variant handle.
     *
     * @var string|null
     */
    public ?string $variant = null;

    /**
     * Restrict generation to an output format.
     *
     * @var string|null
     */
    public ?string $format = null;

    /**
     * List planned units without generating.
     *
     * @var bool
     */
    public bool $dryRun = false;

    /**
     * Enqueue {@see GenerateAssetJob} jobs instead of generating inline.
     *
     * @var bool
     */
    public bool $queue = false;

    /**
     * Regenerate even when derivatives already exist.
     *
     * @var bool
     */
    public bool $force = false;

    /**
     * Maximum number of assets to process (0 = no limit).
     *
     * @var int
     */
    public int $limit = 0;

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
            'asset',
            'volume',
            'profile',
            'variant',
            'format',
            'dryRun',
            'queue',
            'force',
            'limit',
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
            'q' => 'queue',
            'f' => 'force',
        ];
    }

    /**
     * Generates manifest units for matching assets synchronously or via queue.
     *
     * Progress totals are estimated from profile × variant × format counts — we do
     * not run a full `plan()` pass over every derivative before work starts.
     *
     * @return int Console exit code; non-zero only for command-level failures.
     */
    public function actionIndex(): int
    {
        if (!Plugin::getInstance()->isEnabled()) {
            $this->stderr(
                "Super Images is disabled (`enabled => false`). Enable it in config/super-images.php to generate.\n",
                Console::FG_RED,
            );

            return ExitCode::CONFIG;
        }

        if (!$this->asset && !$this->volume) {
            $this->stderr("Provide --asset=ID and/or --volume=handle.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $query = Asset::find()->kind('image')->status(null);

        if ($this->asset) {
            $query->id($this->asset);
        }

        if ($this->volume) {
            $query->volume($this->volume);
        }

        if ($this->limit > 0) {
            $query->limit($this->limit);
        }

        // Fast counts only — no element hydration and no plan() yet.
        $totalAssets = (int) (clone $query)->count();
        if ($totalAssets === 0) {
            $this->stdout("No matching assets found.\n");

            return ExitCode::OK;
        }

        $filters = array_filter([
            'profile' => $this->profile,
            'variant' => $this->variant,
            'format' => $this->format,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $plugin = Plugin::getInstance();
        $manifest = $plugin->getManifest();
        $generation = $plugin->getGeneration();

        $unitsPerAsset = $manifest->estimateUnitsPerAsset($this->volume, $filters);
        $estimatedUnits = $totalAssets * $unitsPerAsset;

        $this->stdout(sprintf(
            "Generating ~%d unit%s across %d asset%s (%d unit%s/asset estimated).\n\n",
            $estimatedUnits,
            $estimatedUnits === 1 ? '' : 's',
            $totalAssets,
            $totalAssets === 1 ? '' : 's',
            $unitsPerAsset,
            $unitsPerAsset === 1 ? '' : 's',
        ), Console::FG_CYAN);

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $enqueued = 0;
        $unitIndex = 0;
        $actualUnits = 0;
        $assetPosition = 0;
        $startedAt = microtime(true);
        /** @var list<array{label: string, error: string}> $failures */
        $failures = [];

        // Batch hydrate assets so we never hold 2k+ elements in memory at once.
        foreach ($query->batch(50) as $assets) {
            foreach ($assets as $asset) {
                if (!$asset instanceof Asset) {
                    continue;
                }

                $assetPosition++;

                if ($this->queue && !$this->dryRun) {
                    Craft::$app->getQueue()->push(new GenerateAssetJob([
                        'assetId' => (int) $asset->id,
                        'profile' => $this->profile,
                        'variant' => $this->variant,
                        'format' => $this->format,
                        'force' => $this->force,
                    ]));
                    $enqueued++;
                    $unitIndex += $unitsPerAsset;
                    $actualUnits += $unitsPerAsset;
                    $this->stdout(sprintf(
                        "[asset %d/%d] #%d %s — queued (~%d units)\n",
                        $assetPosition,
                        $totalAssets,
                        $asset->id,
                        $asset->getFilename(),
                        $unitsPerAsset,
                    ), Console::FG_CYAN);

                    continue;
                }

                $units = $manifest->buildForAsset($asset, $filters);
                if ($units === []) {
                    continue;
                }

                $actualUnits += count($units);

                $this->stdout(sprintf(
                    "[asset %d/%d] #%d %s (%d unit%s)\n",
                    $assetPosition,
                    $totalAssets,
                    $asset->id,
                    $asset->getFilename(),
                    count($units),
                    count($units) === 1 ? '' : 's',
                ), Console::FG_CYAN);

                if ($this->dryRun) {
                    foreach ($units as $unit) {
                        $unitIndex++;
                        $label = sprintf('%s/%s.%s', $unit->profile, $unit->variant, $unit->format);
                        $this->stdout(sprintf(
                            "  [%d/~%d] [dry-run] %s → %s\n",
                            $unitIndex,
                            $estimatedUnits,
                            $label,
                            $unit->publicUrl,
                        ));
                    }

                    continue;
                }

                $generation->generateUnits($units, $this->force, function ($unit, $result) use (
                    &$unitIndex,
                    &$generated,
                    &$skipped,
                    &$failed,
                    &$failures,
                    $estimatedUnits,
                    $asset,
                ): void {
                    $unitIndex++;
                    $label = sprintf('%s/%s.%s', $unit->profile, $unit->variant, $unit->format);
                    $progress = sprintf('[%d/~%d]', $unitIndex, $estimatedUnits);

                    if (($result->diagnostics['skipped'] ?? false) === true) {
                        $skipped++;
                        $this->stdout(sprintf(
                            "  %s [already exists] %s → %s\n",
                            $progress,
                            $label,
                            $result->url,
                        ), Console::FG_GREEN);

                        return;
                    }

                    if (($result->diagnostics['failed'] ?? false) === true || !$result->success) {
                        $failed++;
                        $failures[] = [
                            'label' => sprintf('#%d %s — %s', $asset->id, $asset->getFilename(), $label),
                            'error' => (string) ($result->diagnostics['error'] ?? 'unknown error'),
                        ];

                        return;
                    }

                    $generated++;
                    $this->stdout(sprintf(
                        "  %s [generated] %s → %s\n",
                        $progress,
                        $label,
                        $result->url,
                    ), Console::FG_GREEN);
                });
            }
        }

        $elapsed = microtime(true) - $startedAt;

        if ($this->dryRun) {
            $this->stdout(sprintf("\nDry-run complete (%d unit%s planned).\n", $actualUnits, $actualUnits === 1 ? '' : 's'));
        } else {
            $this->stdout(sprintf(
                "\nSummary: generated=%d already_exists=%d failed=%d queued=%d units=%d (%.1fs)\n",
                $generated,
                $skipped,
                $failed,
                $enqueued,
                $actualUnits,
                $elapsed,
            ));

            if ($failures !== []) {
                $this->stdout("\nFailures:\n", Console::FG_YELLOW);
                foreach ($failures as $failure) {
                    $this->stdout(sprintf(
                        "  • %s — %s\n",
                        $failure['label'],
                        $failure['error'],
                    ), Console::FG_YELLOW);
                }
            }
        }

        return ExitCode::OK;
    }
}
