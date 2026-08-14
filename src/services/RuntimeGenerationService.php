<?php

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\InvalidConfigurationException;
use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\models\EffectiveConfig;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\Plugin;
use yii\base\Component;

/**
 * Handles signed runtime generation requests.
 */
final class RuntimeGenerationService extends Component
{
    /**
     * @param array<string, scalar> $params Verified signed parameters.
     */
    public function handle(array $params): string
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!($settings->runtime['enabled'] ?? true)) {
            throw new InvalidConfigurationException('Runtime generation is disabled.');
        }

        $request = $this->buildRequest($params);
        $planned = $plugin->getGeneration()->plan($request);
        /** @var EffectiveConfig $config */
        $config = $planned['config'];

        $this->enforceRuntimeLimits($config, $settings->runtime);

        $identity = $planned['identity'];
        $locks = $plugin->getGenerationLocks();

        if (!$locks->acquire($identity)) {
            $locks->waitAndCheck($identity);

            $adapter = $plugin->getStorageManager()->select($planned['definition']->storageAdapter);
            if ($adapter->exists($planned['storagePath']) || $plugin->getExistenceMarkers()->exists($identity)) {
                return $planned['storageUrl'];
            }

            if (!$locks->acquire($identity)) {
                throw new SuperImagesException('Generation is already in progress.');
            }
        }

        try {
            $adapter = $plugin->getStorageManager()->select($planned['definition']->storageAdapter);
            if (
                $adapter->exists($planned['storagePath'])
                || $plugin->getExistenceMarkers()->exists($identity)
            ) {
                return $planned['storageUrl'];
            }

            $result = $plugin->getGeneration()->generate($request, force: false);

            return $result->url;
        } finally {
            $locks->release($identity);
        }
    }

    /**
     * @param array<string, scalar> $params
     */
    private function buildRequest(array $params): GenerationRequest
    {
        return new GenerationRequest(
            assetId: isset($params['assetId']) ? (int) $params['assetId'] : null,
            localPath: isset($params['localPath']) ? (string) $params['localPath'] : null,
            remoteUrl: isset($params['remoteUrl']) ? (string) $params['remoteUrl'] : null,
            profile: (string) $params['profile'],
            variant: (string) $params['variant'],
            format: (string) $params['format'],
        );
    }

    /**
     * @param array<string, mixed> $runtimeSettings
     */
    private function enforceRuntimeLimits(EffectiveConfig $config, array $runtimeSettings): void
    {
        $maxWidth = (int) ($runtimeSettings['maxWidth'] ?? 4096);
        $maxHeight = (int) ($runtimeSettings['maxHeight'] ?? 4096);
        $maxPixels = (int) ($runtimeSettings['maxPixels'] ?? 20_000_000);

        foreach ($config->operations as $operation) {
            if (!$operation instanceof OperationDefinition) {
                continue;
            }

            $width = isset($operation->options['width']) ? (int) $operation->options['width'] : null;
            $height = isset($operation->options['height']) ? (int) $operation->options['height'] : null;

            if ($width !== null && $width > $maxWidth) {
                throw new SuperImagesException('Requested width exceeds runtime limit.');
            }

            if ($height !== null && $height > $maxHeight) {
                throw new SuperImagesException('Requested height exceeds runtime limit.');
            }

            if ($width !== null && $height !== null && ($width * $height) > $maxPixels) {
                throw new SuperImagesException('Requested pixel count exceeds runtime limit.');
            }
        }
    }
}
