<?php
/**
 * Orchestrates the canonical Phase 1 image-generation pipeline.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\SourceException;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\GenerationDefinition;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\GenerationResult;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\StorageWriteOptions;
use amici\SuperImages\Plugin;
use yii\base\Component;

/**
 * Generation Service
 *
 * Single entry point for derivative generation. CLI/queue/runtime/Twig must call this.
 */
class GenerationService extends Component
{
    /**
     * Generate one derivative for the given request.
     */
    public function generate(GenerationRequest $request): GenerationResult
    {
        $plugin = Plugin::getInstance();
        $started = microtime(true);
        $handle = null;
        /** @var ImageDriverInterface|null $driver */
        $driver = null;

        try {
            $this->validateRequest($request);

            $source = $plugin->getSourceResolver()->resolve($request);
            $config = $plugin->getConfigurationResolver()->resolve($request);
            $driver = $plugin->getDriverManager()->select($config->driver);

            $definition = $plugin->getConfigurationResolver()->buildDefinition(
                $request,
                $config,
                $source->identity,
                $driver->name(),
            );

            $identity = $plugin->getGenerationIdentity()->calculate($definition, $driver->name());
            $storagePath = $plugin->getStoragePathBuilder()->build(
                $identity,
                $definition->format,
                $definition->profile,
                $definition->variant,
            );

            $handle = $driver->load($source);
            $handle = $plugin->getOperationPipeline()->apply($handle, $driver, $definition->operations);

            $encoder = $plugin->getEncoderManager()->select($definition->format);
            $encoded = $encoder->encode($handle, $definition->format, $definition->encodeOptions, $driver);

            $optimizer = $plugin->getOptimizerManager()->select(
                $definition->format,
                $definition->optimizerOptions,
                $config->optimizersEnabled,
            );
            $optimizerTool = $definition->optimizerOptions[$definition->format]
                ?? $definition->optimizerOptions[$this->normalizeFormatKey($definition->format)]
                ?? null;
            $encoded = $optimizer->optimize($encoded, $definition->format, [
                'tool' => $optimizerTool,
                'quality' => $definition->encodeOptions->quality,
            ]);

            $this->validateEncodedOutput($encoded);

            $adapter = $plugin->getStorageManager()->select($definition->storageAdapter);
            $writeOptions = new StorageWriteOptions(
                contentType: $encoded->mime,
                public: true,
            );

            $storageObject = $encoded->hasPath()
                ? $adapter->writeFile($storagePath, (string) $encoded->path, $writeOptions)
                : $adapter->write($storagePath, (string) $encoded->bytes, $writeOptions);

            // Markers are for remote/CDN storage existence checks — never webroot image mirrors.
            if ($adapter->capabilities()->remote) {
                $plugin->getExistenceMarkers()->write($identity, [
                    'path' => $storagePath,
                    'format' => $definition->format,
                    'adapter' => $adapter->name(),
                ]);
            }

            return new GenerationResult(
                success: true,
                identity: $identity,
                storagePath: $storageObject->path,
                url: $storageObject->url,
                format: $definition->format,
                width: $encoded->width,
                height: $encoded->height,
                size: $storageObject->size,
                mime: $encoded->mime,
                durationMs: (microtime(true) - $started) * 1000,
                diagnostics: [
                    'driver' => $driver->name(),
                    'profile' => $definition->profile,
                    'variant' => $definition->variant,
                ],
            );
        } finally {
            if ($handle instanceof ImageHandle && $driver instanceof ImageDriverInterface) {
                $driver->destroy($handle);
            }

            $plugin->getTemporaryFiles()->cleanup();
        }
    }

    private function validateRequest(GenerationRequest $request): void
    {
        if ($request->sourceCount() !== 1) {
            throw new SourceException('Generation request must include exactly one of assetId, localPath, or remoteUrl.');
        }
    }

    private function validateEncodedOutput(EncodedImage $encoded): void
    {
        if ($encoded->size <= 0) {
            throw new ProcessingException('Encoded output is empty.');
        }

        if ($encoded->width <= 0 || $encoded->height <= 0) {
            throw new ProcessingException('Encoded output has invalid dimensions.');
        }

        if (!$encoded->hasBytes() && !$encoded->hasPath()) {
            throw new ProcessingException('Encoded output has no readable payload.');
        }
    }

    private function normalizeFormatKey(string $format): string
    {
        $format = strtolower($format);

        return $format === 'jpg' ? 'jpeg' : $format;
    }
}
