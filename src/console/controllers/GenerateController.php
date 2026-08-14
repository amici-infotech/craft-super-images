<?php

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\jobs\GenerateAssetJob;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Eager generation via CLI.
 */
class GenerateController extends Controller
{
    public ?int $asset = null;

    public ?string $volume = null;

    public ?string $profile = null;

    public ?string $variant = null;

    public ?string $format = null;

    public bool $dryRun = false;

    public bool $queue = false;

    public bool $force = false;

    public int $limit = 0;

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

    public function optionAliases(): array
    {
        return [
            'd' => 'dryRun',
            'q' => 'queue',
            'f' => 'force',
        ];
    }

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

        $generated = 0;
        $skipped = 0;
        $failed = 0;
        $enqueued = 0;

        $plugin = Plugin::getInstance();
        $manifest = $plugin->getManifest();
        $generation = $plugin->getGeneration();

        foreach ($assets as $asset) {
            $units = $manifest->buildForAsset($asset, $filters);

            if ($units === []) {
                continue;
            }

            $this->stdout(sprintf(
                "Asset #%d %s (%d units)\n",
                $asset->id,
                $asset->getFilename(),
                count($units),
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
                $this->stdout("  [queued] GenerateAssetJob\n");

                continue;
            }

            foreach ($units as $unit) {
                $label = sprintf('%s/%s.%s', $unit->profile, $unit->variant, $unit->format);

                if ($this->dryRun) {
                    $this->stdout(sprintf("  [dry-run] %s → %s\n", $label, $unit->publicUrl));

                    continue;
                }

                try {
                    $result = $generation->generate($unit->toGenerationRequest(), $this->force);

                    if (($result->diagnostics['skipped'] ?? false) === true) {
                        $skipped++;
                        $this->stdout(sprintf("  [skipped] %s\n", $label));
                    } else {
                        $generated++;
                        $this->stdout(sprintf("  [generated] %s → %s\n", $label, $result->url), Console::FG_GREEN);
                    }
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->stderr(sprintf("  [failed] %s — %s\n", $label, $exception->getMessage()), Console::FG_RED);
                }
            }
        }

        if ($this->dryRun) {
            $this->stdout("\nDry-run complete.\n");
        } else {
            $this->stdout(sprintf(
                "\nSummary: generated=%d skipped=%d failed=%d queued=%d\n",
                $generated,
                $skipped,
                $failed,
                $enqueued,
            ));
        }

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
