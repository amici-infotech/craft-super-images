<?php
/**
 * Queue job for eager asset generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\jobs;

use amici\SuperImages\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Generate Asset Job
 *
 * Generates all manifest units for one asset.
 */
class GenerateAssetJob extends BaseJob
{
    /**
     * Craft asset ID to generate derivatives for.
     *
     * @var int
     */
    public int $assetId;

    /**
     * Optional profile filter.
     *
     * @var string|null
     */
    public ?string $profile = null;

    /**
     * Optional variant filter.
     *
     * @var string|null
     */
    public ?string $variant = null;

    /**
     * Optional format filter.
     *
     * @var string|null
     */
    public ?string $format = null;

    /**
     * Whether to regenerate existing derivatives.
     *
     * @var bool
     */
    public bool $force = false;

    /**
     * Generates all matching manifest units for the asset.
     *
     * @param mixed $queue The queue instance driving this job.
     *
     * @return void
     */
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

        $results = Plugin::getInstance()->getGeneration()->generateUnits($units, $this->force);
        $this->setProgress($queue, 1);

        foreach ($results as $index => $result) {
            if (!$result->success) {
                Craft::warning(sprintf(
                    'GenerateAssetJob: unit %d/%d failed for asset #%d (%s/%s.%s).',
                    $index + 1,
                    $total,
                    $this->assetId,
                    $units[$index]->profile ?? '?',
                    $units[$index]->variant ?? '?',
                    $units[$index]->format ?? '?',
                ), __METHOD__);
            }
        }
    }

    /**
     * Returns the default queue job description shown in the CP.
     *
     * @return string|null Human-readable job description.
     */
    protected function defaultDescription(): ?string
    {
        return sprintf('Super Images: generate asset %d', $this->assetId);
    }
}
