<?php
/**
 * Console config dump command for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\console\controllers;

use amici\SuperImages\Plugin;
use craft\elements\Asset;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;
use yii\helpers\Json;

/**
 * Config Controller
 *
 * Dumps effective Super Images configuration as JSON.
 *
 *     php craft super-images/config
 *     php craft super-images/config --asset=123
 */
class ConfigController extends Controller
{
    /**
     * Optional asset ID to include manifest sample units in the output.
     *
     * @var int|null
     */
    public ?int $asset = null;

    /**
     * Returns the list of options available for this command.
     *
     * @param string $actionID The action ID of the controller.
     *
     * @return list<string> Option property names.
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['asset']);
    }

    /**
     * Prints effective plugin configuration (and optional asset manifest sample).
     *
     * @return int Console exit code.
     */
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
