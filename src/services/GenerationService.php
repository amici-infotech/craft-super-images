<?php
/**
 * Single entry point for derivative image generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\AbstractDriver;
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
 * Orchestrates the full derivative pipeline: planning, source loading, operations,
 * encoding, optimization, and storage. CLI, queue, runtime, and Twig integrations
 * must call this service rather than invoking drivers or encoders directly.
 */
class GenerationService extends Component
{
    /**
     * Event fired before a derivative is generated (including skip checks).
     */
    public const EVENT_BEFORE_GENERATE = 'beforeGenerate';

    /**
     * Event fired after generation completes or is skipped because output already exists.
     */
    public const EVENT_AFTER_GENERATE = 'afterGenerate';

    /**
     * Event fired immediately before the encoded image is written to storage.
     */
    public const EVENT_BEFORE_ENCODE = 'beforeEncode';

    /**
     * Event fired immediately after encoding and before optimization.
     */
    public const EVENT_AFTER_ENCODE = 'afterEncode';

    /**
     * Plan a derivative without processing, encoding, or storage I/O.
     *
     * Resolves configuration, builds the generation definition, calculates identity,
     * and returns storage metadata suitable for manifests and URL planning.
     *
     * @param GenerationRequest $request The generation request with source, profile, variant, and format.
     *
     * @return array{
     *     identity: string,
     *     storagePath: string,
     *     definition: GenerationDefinition,
     *     config: EffectiveConfig,
     *     driverName: string,
     *     storageUrl: string,
     * }
     *
     * @throws SourceException When the request does not include exactly one source.
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
     *
     * Skips processing when output already exists unless `$force` is true. Loads the
     * source, applies operations, encodes, optionally optimizes, writes to storage,
     * and records existence markers for remote adapters.
     *
     * @param GenerationRequest $request The generation request with source, profile, variant, and format.
     * @param bool $force When true, regenerate even if the derivative already exists in storage.
     *
     * @return GenerationResult The generation outcome including URL, dimensions, and diagnostics.
     *
     * @throws SourceException When the request does not include exactly one source.
     * @throws ProcessingException When encoded output is empty or has invalid dimensions.
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

            $sourcePixels = $handle->width * $handle->height;
            if ($sourcePixels > $config->maxSourcePixels) {
                throw new ProcessingException(sprintf(
                    'Source image exceeds maximum allowed pixels (%d > %d).',
                    $sourcePixels,
                    $config->maxSourcePixels,
                ));
            }

            if ($driver instanceof AbstractDriver) {
                $driver->setAllowUpscale($config->allowUpscale);
            }

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
                $markerMetadata = [
                    'path' => $storagePath,
                    'format' => $definition->format,
                    'adapter' => $adapter->name(),
                ];

                if ($request->assetId !== null) {
                    $markerMetadata['assetId'] = $request->assetId;
                }

                $plugin->getExistenceMarkers()->write($identity, $markerMetadata);
            }

            if (!$request->preview && $request->assetId !== null) {
                $plugin->getAssetDerivativeIndex()->record(
                    $request->assetId,
                    $identity,
                    $storageObject->path,
                    $adapter->name(),
                );
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

    /**
     * Build the storage namespace segment for preview generations.
     *
     * Preview derivatives are stored under `preview/{YYYYMMDD}/` so they can be
     * cleaned up independently of production output.
     *
     * @param GenerationRequest $request The generation request; preview flag controls namespace.
     *
     * @return string|null The namespace prefix, or null for non-preview requests.
     */
    private function previewNamespace(GenerationRequest $request): ?string
    {
        if (!$request->preview) {
            return null;
        }

        return 'preview/' . date('Ymd');
    }

    /**
     * Ensure the generation request specifies exactly one source.
     *
     * @param GenerationRequest $request The generation request to validate.
     *
     * @return void
     *
     * @throws SourceException When zero or multiple sources are provided.
     */
    private function validateRequest(GenerationRequest $request): void
    {
        if ($request->sourceCount() !== 1) {
            throw new SourceException('Generation request must include exactly one of assetId, localPath, or remoteUrl.');
        }
    }

    /**
     * Validate that encoded output has usable dimensions and payload.
     *
     * @param EncodedImage $encoded The encoded image produced by the encoder/optimizer pipeline.
     *
     * @return void
     *
     * @throws ProcessingException When size, dimensions, or payload are invalid.
     */
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

    /**
     * Map an output format string to its MIME type.
     *
     * @param string $format The output format (e.g. webp, jpg, avif).
     *
     * @return string The corresponding MIME type, or application/octet-stream for unknown formats.
     */
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

    /**
     * Normalize a format key for optimizer/encoder config lookup.
     *
     * @param string $format The output format string.
     *
     * @return string The normalized key (jpg becomes jpeg).
     */
    private function normalizeFormatKey(string $format): string
    {
        $format = strtolower($format);

        return $format === 'jpg' ? 'jpeg' : $format;
    }
}
