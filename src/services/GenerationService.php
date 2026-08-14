<?php

namespace amici\SuperImages\services;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\events\GenerationEvent;
use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\SourceException;
use amici\SuperImages\models\EffectiveConfig;
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
    public const EVENT_BEFORE_GENERATE = 'beforeGenerate';
    public const EVENT_AFTER_GENERATE = 'afterGenerate';
    public const EVENT_BEFORE_ENCODE = 'beforeEncode';
    public const EVENT_AFTER_ENCODE = 'afterEncode';

    /**
     * Plan a derivative without processing, encoding, or storage I/O.
     *
     * @return array{
     *     identity: string,
     *     storagePath: string,
     *     definition: GenerationDefinition,
     *     config: EffectiveConfig,
     *     driverName: string,
     *     storageUrl: string,
     * }
     */
    public function plan(GenerationRequest $request): array
    {
        $plugin = Plugin::getInstance();
        $this->validateRequest($request);

        $sourceIdentity = $plugin->getSourceResolver()->resolveIdentity($request);
        $config = $plugin->getConfigurationResolver()->resolve($request);
        $driver = $plugin->getDriverManager()->select($config->driver);

        $definition = $plugin->getConfigurationResolver()->buildDefinition(
            $request,
            $config,
            $sourceIdentity,
            $driver->name(),
        );

        $identity = $plugin->getGenerationIdentity()->calculate($definition, $driver->name());
        $storagePath = $plugin->getStoragePathBuilder()->build(
            $identity,
            $definition->format,
            $definition->profile,
            $definition->variant,
            $this->previewNamespace($request),
        );

        $adapter = $plugin->getStorageManager()->select($definition->storageAdapter);

        return [
            'identity' => $identity,
            'storagePath' => $storagePath,
            'definition' => $definition,
            'config' => $config,
            'driverName' => $driver->name(),
            'storageUrl' => $adapter->url($storagePath),
        ];
    }

    /**
     * Generate one derivative for the given request.
     */
    public function generate(GenerationRequest $request, bool $force = false): GenerationResult
    {
        $plugin = Plugin::getInstance();
        $started = microtime(true);
        $handle = null;
        /** @var ImageDriverInterface|null $driver */
        $driver = null;

        try {
            $planned = $this->plan($request);
            $identity = $planned['identity'];
            $storagePath = $planned['storagePath'];
            /** @var GenerationDefinition $definition */
            $definition = $planned['definition'];
            /** @var EffectiveConfig $config */
            $config = $planned['config'];
            $storageUrl = $planned['storageUrl'];

            $beforeEvent = new GenerationEvent([
                'request' => $request,
                'definition' => $definition,
                'identity' => $identity,
            ]);
            $this->trigger(self::EVENT_BEFORE_GENERATE, $beforeEvent);

            if (!$force) {
                $adapter = $plugin->getStorageManager()->select($definition->storageAdapter);
                $objectExists = $adapter->exists($storagePath);
                $markerExists = $plugin->getExistenceMarkers()->exists($identity);

                if ($objectExists || $markerExists) {
                    $result = new GenerationResult(
                        success: true,
                        identity: $identity,
                        storagePath: $storagePath,
                        url: $storageUrl,
                        format: $definition->format,
                        width: 0,
                        height: 0,
                        size: 0,
                        mime: $this->mimeFromFormat($definition->format),
                        durationMs: (microtime(true) - $started) * 1000,
                        diagnostics: [
                            'skipped' => true,
                            'driver' => $planned['driverName'],
                            'profile' => $definition->profile,
                            'variant' => $definition->variant,
                            'preview' => $request->preview,
                        ],
                    );

                    $this->trigger(self::EVENT_AFTER_GENERATE, new GenerationEvent([
                        'request' => $request,
                        'definition' => $definition,
                        'identity' => $identity,
                        'result' => $result,
                    ]));

                    return $result;
                }
            }

            $source = $plugin->getSourceResolver()->resolve($request);
            $driver = $plugin->getDriverManager()->select($definition->driverPreference);

            $handle = $driver->load($source);
            $handle = $plugin->getOperationPipeline()->apply($handle, $driver, $definition->operations);

            $this->trigger(self::EVENT_BEFORE_ENCODE, new GenerationEvent([
                'request' => $request,
                'definition' => $definition,
                'identity' => $identity,
            ]));

            $encoder = $plugin->getEncoderManager()->select($definition->format);
            $encoded = $encoder->encode($handle, $definition->format, $definition->encodeOptions, $driver);

            $this->trigger(self::EVENT_AFTER_ENCODE, new GenerationEvent([
                'request' => $request,
                'definition' => $definition,
                'identity' => $identity,
            ]));

            $optimizer = $plugin->getOptimizerManager()->select(
                $definition->format,
                $definition->optimizerOptions,
                $config->optimizersEnabled,
            );
            $formatKey = $this->normalizeFormatKey($definition->format);
            $rawTool = $definition->optimizerOptions[$definition->format]
                ?? $definition->optimizerOptions[$formatKey]
                ?? null;
            [$optimizerTool, $optimizerBinary] = $plugin->getOptimizerManager()->normalizeToolConfig($rawTool);
            $encoded = $optimizer->optimize($encoded, $definition->format, array_filter([
                'tool' => $optimizerTool,
                'binary' => $optimizerBinary,
                'quality' => $definition->encodeOptions->quality,
            ], static fn(mixed $value): bool => $value !== null && $value !== ''));

            $this->validateEncodedOutput($encoded);

            $adapter = $plugin->getStorageManager()->select($definition->storageAdapter);
            $writeOptions = new StorageWriteOptions(
                contentType: $encoded->mime,
                public: true,
            );

            $storageObject = $encoded->hasPath()
                ? $adapter->writeFile($storagePath, (string) $encoded->path, $writeOptions)
                : $adapter->write($storagePath, (string) $encoded->bytes, $writeOptions);

            if ($adapter->capabilities()->remote && !$request->preview) {
                $plugin->getExistenceMarkers()->write($identity, [
                    'path' => $storagePath,
                    'format' => $definition->format,
                    'adapter' => $adapter->name(),
                ]);
            }

            $result = new GenerationResult(
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
                    'preview' => $request->preview,
                    'optimizer' => $optimizer->name(),
                ],
            );

            $this->trigger(self::EVENT_AFTER_GENERATE, new GenerationEvent([
                'request' => $request,
                'definition' => $definition,
                'identity' => $identity,
                'result' => $result,
            ]));

            return $result;
        } finally {
            if ($handle instanceof ImageHandle && $driver instanceof ImageDriverInterface) {
                $driver->destroy($handle);
            }

            $plugin->getTemporaryFiles()->cleanup();
        }
    }

    private function previewNamespace(GenerationRequest $request): ?string
    {
        if (!$request->preview) {
            return null;
        }

        return 'preview/' . date('Ymd');
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

    private function mimeFromFormat(string $format): string
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }

    private function normalizeFormatKey(string $format): string
    {
        $format = strtolower($format);

        return $format === 'jpg' ? 'jpeg' : $format;
    }
}
