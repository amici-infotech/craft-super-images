<?php

namespace amici\SuperImages\jobs;

use amici\SuperImages\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Generates all manifest units for one asset.
 */
class GenerateAssetJob extends BaseJob
{
    public int $assetId;

    public ?string $profile = null;

    public ?string $variant = null;

    public ?string $format = null;

    public bool $force = false;

    public function execute(mixed $queue): void
    {
        $asset = Craft::$app->getAssets()->getAssetById($this->assetId);

        if ($asset === null) {
            Craft::warning(sprintf('GenerateAssetJob: asset #%d not found.', $this->assetId), __METHOD__);

            return;
        }

        $filters = array_filter([
            'profile' => $this->profile,
            'variant' => $this->variant,
            'format' => $this->format,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $units = Plugin::getInstance()->getManifest()->buildForAsset($asset, $filters);
        $total = count($units);

        if ($total === 0) {
            $this->setProgress($queue, 1);

            return;
        }

        $generation = Plugin::getInstance()->getGeneration();

        foreach ($units as $index => $unit) {
            $generation->generate($unit->toGenerationRequest(), $this->force);
            $this->setProgress($queue, ($index + 1) / $total);
        }
    }

    protected function defaultDescription(): ?string
    {
        return sprintf('Super Images: generate asset %d', $this->assetId);
    }
}
