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
     * @return int Console exit code; non-zero when any unit fails.
     */
    public function actionIndex(): int
    {
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

        $assets = $query->all();
        if ($assets === []) {
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

        // Build the full unit plan up front so progress totals ([n/total]) are known
        // before any work starts, instead of discovering asset/unit counts as we go.
        $unitsByAsset = [];
        $totalUnits = 0;
        foreach ($assets as $asset) {
            $units = $manifest->buildForAsset($asset, $filters);
            if ($units === []) {
                continue;
            }

            $unitsByAsset[] = [$asset, $units];
            $totalUnits += count($units);
        }

        if ($unitsByAsset === []) {
            $this->stdout("No generation units matched the given filters.\n");

            return ExitCode::OK;
        }

        $totalAssets = count($unitsByAsset);
        $this->stdout(sprintf(
            "Planned %d unit%s across %d asset%s.\n\n",
            $totalUnits,
            $totalUnits === 1 ? '' : 's',
            $totalAssets,
            $totalAssets === 1 ? '' : 's',
        ), Console::FG_CYAN);

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $enqueued = 0;
        $unitIndex = 0;
        $startedAt = microtime(true);

        foreach ($unitsByAsset as $assetPosition => [$asset, $units]) {
            $this->stdout(sprintf(
                "[asset %d/%d] #%d %s (%d unit%s)\n",
                $assetPosition + 1,
                $totalAssets,
                $asset->id,
                $asset->getFilename(),
                count($units),
                count($units) === 1 ? '' : 's',
            ), Console::FG_CYAN);

            if ($this->queue && !$this->dryRun) {
                Craft::$app->getQueue()->push(new GenerateAssetJob([
                    'assetId' => (int) $asset->id,
                    'profile' => $this->profile,
                    'variant' => $this->variant,
                    'format' => $this->format,
                    'force' => $this->force,
                ]));
                $enqueued++;
                $unitIndex += count($units);
                $this->stdout(sprintf("  [%d/%d] queued GenerateAssetJob\n", $unitIndex, $totalUnits));

                continue;
            }

            foreach ($units as $unit) {
                $unitIndex++;
                $label = sprintf('%s/%s.%s', $unit->profile, $unit->variant, $unit->format);
                $progress = sprintf('[%d/%d]', $unitIndex, $totalUnits);

                if ($this->dryRun) {
                    $this->stdout(sprintf("  %s [dry-run] %s → %s\n", $progress, $label, $unit->publicUrl));

                    continue;
                }

                try {
                    $result = $generation->generate($unit->toGenerationRequest(), $this->force);

                    if (($result->diagnostics['skipped'] ?? false) === true) {
                        $skipped++;
                        $this->stdout(sprintf("  %s [skipped] %s\n", $progress, $label));
                    } else {
                        $generated++;
                        $this->stdout(sprintf("  %s [generated] %s → %s\n", $progress, $label, $result->url), Console::FG_GREEN);
                    }
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->stderr(sprintf("  %s [failed] %s — %s\n", $progress, $label, $exception->getMessage()), Console::FG_RED);
                }
            }
        }

        $elapsed = microtime(true) - $startedAt;

        if ($this->dryRun) {
            $this->stdout("\nDry-run complete.\n");
        } else {
            $this->stdout(sprintf(
                "\nSummary: generated=%d skipped=%d failed=%d queued=%d (%.1fs)\n",
                $generated,
                $skipped,
                $failed,
                $enqueued,
                $elapsed,
            ));
        }

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
