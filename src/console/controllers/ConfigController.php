<?php

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\Plugin;
use craft\elements\Asset;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\Json;

/**
 * Dumps effective Super Images configuration.
 */
class ConfigController extends Controller
{
    public ?int $asset = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['asset']);
    }

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $output = [
            'enabled' => $settings->enabled,
            'defaultProfile' => $settings->defaultProfile,
            'defaultFormat' => $settings->defaultFormat,
            'driver' => $settings->driver,
            'delivery' => $settings->delivery,
            'autoGenerate' => $settings->autoGenerate,
            'storage' => [
                'default' => $settings->storage['default'] ?? 'local',
                'adapters' => array_keys($settings->storage['adapters'] ?? []),
            ],
            'profiles' => array_keys($settings->profiles),
        ];

        if ($this->asset) {
            $asset = Asset::find()->id($this->asset)->one();

            if (!$asset instanceof Asset) {
                $this->stderr(sprintf("Asset #%d not found.\n", $this->asset), Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $units = $plugin->getManifest()->buildForAsset($asset);
            $output['asset'] = [
                'id' => $asset->id,
                'filename' => $asset->getFilename(),
                'volume' => $asset->getVolume()->handle,
                'manifestUnitCount' => count($units),
                'sampleUnits' => array_map(static fn($unit) => [
                    'profile' => $unit->profile,
                    'variant' => $unit->variant,
                    'format' => $unit->format,
                    'identity' => $unit->identity,
                    'publicUrl' => $unit->publicUrl,
                ], array_slice($units, 0, 5)),
            ];
        }

        $this->stdout(Json::encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        return ExitCode::OK;
    }
}
