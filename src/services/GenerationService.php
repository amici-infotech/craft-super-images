<?php
/**
 * Single entry point for derivative image generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\contracts\StorageAdapterInterface;
use amici\SuperImages\drivers\AbstractDriver;
use amici\SuperImages\events\GenerationEvent;
use amici\SuperImages\exceptions\ProcessingException;
use amici\SuperImages\exceptions\SourceException;
use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\jobs\OptimizeDerivativeJob;
use amici\SuperImages\models\EffectiveConfig;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\GenerationDefinition;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\GenerationResult;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\ManifestUnit;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\models\SourceImage;
use amici\SuperImages\models\StorageWriteOptions;
use amici\SuperImages\Plugin;
use amici\SuperImages\storage\LocalStorageAdapter;
use Craft;
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
        $pathMeta = $this->resolvePathMeta($request, $plugin);
        $storagePath = $plugin->getStoragePathBuilder()->build(
            $identity,
            $definition->format,
            $definition->profile,
            $definition->variant,
            $this->previewNamespace($request),
            $pathMeta['assetId'],
            $pathMeta['basename'],
            $pathMeta['folderHash'],
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
     * Generate many planned units, reusing one resolved source per asset.
     *
     * Fast path for CLI/queue bulk generation: one source resolve, deferred temp
     * cleanup, and a single derivative-index write per asset.
     *
     * @param list<ManifestUnit> $units Planned generation units (typically one asset).
     * @param bool $force When true, regenerate even if derivatives already exist.
     *
     * @return list<GenerationResult> Results in the same order as `$units`.
     */
    public function generateUnits(array $units, bool $force = false): array
    {
        if ($units === []) {
            return [];
        }

        $this->assertPluginEnabled();

        $plugin = Plugin::getInstance();
        $results = [];
        $indexWrites = [];

        $groups = [];
        foreach ($units as $index => $unit) {
            $key = $unit->assetId !== null
                ? 'asset:' . $unit->assetId
                : ($unit->localPath !== null ? 'local:' . $unit->localPath : 'remote:' . ($unit->remoteUrl ?? ''));
            $groups[$key][] = [$index, $unit];
        }

        try {
            foreach ($groups as $group) {
                $assetIdForCache = null;

                try {
                    foreach ($group as [$index, $unit]) {
                        $request = $unit->toGenerationRequest();
                        $assetIdForCache = $request->assetId ?? $assetIdForCache;

                        try {
                            // SourceResolver caches the resolved file for this asset until
                            // clearSourceCache() — no per-unit getCopyOfFile().
                            $result = $this->generateInternal(
                                $request,
                                $force,
                                cleanup: false,
                                recordIndex: false,
                                sharedSource: null,
                            );
                        } catch (\Throwable $exception) {
                            $results[$index] = new GenerationResult(
                                success: false,
                                identity: $unit->identity,
                                storagePath: $unit->storagePath,
                                url: $unit->publicUrl,
                                format: $unit->format,
                                width: 0,
                                height: 0,
                                size: 0,
                                mime: '',
                                durationMs: 0,
                                diagnostics: [
                                    'failed' => true,
                                    'error' => $exception->getMessage(),
                                    'profile' => $unit->profile,
                                    'variant' => $unit->variant,
                                ],
                            );

                            continue;
                        }

                        if (
                            ($result->diagnostics['skipped'] ?? false) !== true
                            && $request->assetId !== null
                            && !$request->preview
                        ) {
                            $indexWrites[$request->assetId][] = [
                                'identity' => $result->identity,
                                'storagePath' => $result->storagePath,
                                'adapter' => (string) ($result->diagnostics['adapter'] ?? 'local'),
                            ];
                        }

                        $results[$index] = $result;
                    }
                } finally {
                    if ($assetIdForCache !== null) {
                        $plugin->getSourceResolver()->clearSourceCache($assetIdForCache);
                    }
                }
            }

            foreach ($indexWrites as $assetId => $entries) {
                $plugin->getAssetDerivativeIndex()->recordMany((int) $assetId, $entries);
            }
        } finally {
            $plugin->getTemporaryFiles()->cleanup();
        }

        ksort($results);

        return array_values($results);
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
        return $this->generateInternal($request, $force, cleanup: true, recordIndex: true, sharedSource: null);
    }

    /**
     * Core generate implementation shared by single and batch entry points.
     *
     * @param GenerationRequest $request The generation request.
     * @param bool $force When true, regenerate even if output exists.
     * @param bool $cleanup When true, destroy handles and clean temp files before returning.
     * @param bool $recordIndex When true, write the asset derivative index immediately.
     * @param SourceImage|null $sharedSource Optional already-resolved source for batch reuse.
     *
     * @return GenerationResult The generation outcome.
     */
    private function generateInternal(
        GenerationRequest $request,
        bool $force,
        bool $cleanup,
        bool $recordIndex,
        ?SourceImage $sharedSource,
    ): GenerationResult {
        $this->assertPluginEnabled();

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

            $this->trigger(self::EVENT_BEFORE_GENERATE, new GenerationEvent([
                'request' => $request,
                'definition' => $definition,
                'identity' => $identity,
            ]));

            if (!$force) {
                $adapter = $plugin->getStorageManager()->select($definition->storageAdapter);
                $objectExists = $adapter->exists($storagePath);
                $markerExists = $plugin->getExistenceMarkers()->exists($identity);

                if ($objectExists || $markerExists) {
                    [$width, $height, $size] = $this->resolveExistingDerivativeMeta(
                        $adapter,
                        $storagePath,
                        $identity,
                        $objectExists,
                        $markerExists,
                    );

                    $result = new GenerationResult(
                        success: true,
                        identity: $identity,
                        storagePath: $storagePath,
                        url: $storageUrl,
                        format: $definition->format,
                        width: $width,
                        height: $height,
                        size: $size,
                        mime: $this->mimeFromFormat($definition->format),
                        durationMs: (microtime(true) - $started) * 1000,
                        diagnostics: [
                            'skipped' => true,
                            'driver' => $planned['driverName'],
                            'profile' => $definition->profile,
                            'variant' => $definition->variant,
                            'preview' => $request->preview,
                            'adapter' => $adapter->name(),
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

            $source = $sharedSource ?? $plugin->getSourceResolver()->resolve($request);
            $driver = $plugin->getDriverManager()->select($definition->driverPreference);
            $handle = $driver->load($source);

            $sourcePixels = $handle->width * $handle->height;
            if ($sourcePixels > $config->maxSourcePixels && $config->maxSourcePixels > 0) {
                // Soft-cap: downscale huge sources to the safety budget instead of aborting.
                // (runtime.maxPixels is unrelated — that only gates signed lazy-generate URLs.)
                $scale = sqrt($config->maxSourcePixels / $sourcePixels);
                $maxWidth = max(1, (int) floor($handle->width * $scale));
                $maxHeight = max(1, (int) floor($handle->height * $scale));

                $handle = $plugin->getOperationPipeline()->apply($handle, $driver, [
                    new OperationDefinition('fit', [
                        'width' => $maxWidth,
                        'height' => $maxHeight,
                    ]),
                ]);
            }

            if ($driver instanceof AbstractDriver) {
                $driver->setAllowUpscale($config->allowUpscale);
                $driver->setSharpness($config->sharpness);
            }

            $handle = $plugin->getOperationPipeline()->apply($handle, $driver, $definition->operations);

            $this->trigger(self::EVENT_BEFORE_ENCODE, new GenerationEvent([
                'request' => $request,
                'definition' => $definition,
                'identity' => $identity,
            ]));

            $formatKey = $this->normalizeFormatKey($definition->format);
            $rawTool = $definition->optimizerOptions[$definition->format]
                ?? $definition->optimizerOptions[$formatKey]
                ?? null;
            [$optimizerTool, $optimizerBinary, $optimizerArguments] = $plugin->getOptimizerManager()->normalizeToolConfig($rawTool);
            $encoderArguments = $plugin->getOptimizerManager()->normalizeArguments(
                $definition->encodeOptions->extra['arguments']
                    ?? $definition->encodeOptions->extra['args']
                    ?? null,
            );
            $cliArguments = $optimizerArguments !== [] ? $optimizerArguments : $encoderArguments;

            // Only route through PNG→cwebp/avifenc when the binary is actually callable.
            // Otherwise we used to write a PNG and rename it .webp (~MB files).
            $externalEncoder = null;
            $deferPostOptimize = false;
            if (
                $config->optimizersEnabled
                && in_array($formatKey, ['webp', 'avif'], true)
                && in_array($optimizerTool, ['cwebp', 'avifenc'], true)
                && $plugin->getBinaryResolver()->isAvailable((string) $optimizerTool, $optimizerBinary)
            ) {
                $externalEncoder = $optimizerTool;
            }

            if ($externalEncoder !== null) {
                $pngEncoder = $plugin->getEncoderManager()->select('png');
                $encoded = $pngEncoder->encode(
                    $handle,
                    'png',
                    new EncodeOptions(
                        quality: 100,
                        stripMetadata: $definition->encodeOptions->stripMetadata,
                        extra: ['pngCompression' => 1],
                    ),
                    $driver,
                );

                $this->trigger(self::EVENT_AFTER_ENCODE, new GenerationEvent([
                    'request' => $request,
                    'definition' => $definition,
                    'identity' => $identity,
                ]));

                $optimizer = $plugin->getOptimizerManager()->select(
                    $definition->format,
                    $definition->optimizerOptions,
                    true,
                );
                $encoded = $optimizer->optimize(
                    $encoded,
                    $definition->format,
                    $this->buildOptimizerOptions(
                        $externalEncoder,
                        $optimizerBinary,
                        $definition->encodeOptions->quality,
                        $definition->encodeOptions->extra['effort']
                            ?? $definition->encodeOptions->extra['method']
                            ?? null,
                        $cliArguments,
                    ),
                );

                if (!$this->encodedMatchesFormat($encoded, $definition->format)) {
                    // Binary missing/failed — fall back to native WebP/AVIF encode (never ship PNG).
                    $encoder = $plugin->getEncoderManager()->select($definition->format);
                    $encoded = $encoder->encode($handle, $definition->format, $definition->encodeOptions, $driver);
                } else {
                    $encoded = $encoded->withFormat(
                        $definition->format,
                        $this->mimeFromFormat($definition->format),
                    );
                }
            } else {
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
                // Never run cwebp/avifenc as a "post-optimizer" on already-lossy output.
                // Post-optimizers (jpegoptim, etc.) may be deferred to the queue.
                if (
                    $config->optimizersEnabled
                    && $optimizerTool !== null
                    && $optimizerTool !== ''
                    && !$plugin->getOptimizerManager()->isExternalEncoder($optimizerTool)
                    && $optimizer->name() !== 'null'
                ) {
                    if ($plugin->getOptimizerManager()->shouldDeferPostOptimize($definition->optimizerOptions)) {
                        $deferPostOptimize = true;
                    } else {
                        $encoded = $optimizer->optimize(
                            $encoded,
                            $definition->format,
                            $this->buildOptimizerOptions(
                                $optimizerTool,
                                $optimizerBinary,
                                $definition->encodeOptions->quality,
                                null,
                                $cliArguments,
                            ),
                        );
                    }
                }
            }

            $this->validateEncodedOutput($encoded);

            $adapter = $plugin->getStorageManager()->select($definition->storageAdapter);
            $writeOptions = new StorageWriteOptions(
                contentType: $encoded->mime,
                public: true,
            );

            $storageObject = $encoded->hasPath()
                ? $adapter->writeFile($storagePath, (string) $encoded->path, $writeOptions)
                : $adapter->write($storagePath, (string) $encoded->bytes, $writeOptions);

            if ($deferPostOptimize && !$request->preview) {
                $resolvedBinary = $plugin->getBinaryResolver()->resolve(
                    (string) $optimizerTool,
                    $optimizerBinary,
                );
                $this->enqueuePostOptimize(
                    storageAdapter: $definition->storageAdapter,
                    storagePath: $storageObject->path,
                    format: $definition->format,
                    mime: $encoded->mime,
                    tool: (string) $optimizerTool,
                    binary: $resolvedBinary,
                    quality: $definition->encodeOptions->quality,
                    arguments: $cliArguments,
                );
            }

            if ($adapter->capabilities()->remote && !$request->preview) {
                $markerMetadata = [
                    'path' => $storagePath,
                    'format' => $definition->format,
                    'adapter' => $adapter->name(),
                    'width' => $encoded->width,
                    'height' => $encoded->height,
                    'size' => $storageObject->size,
                ];

                if ($request->assetId !== null) {
                    $markerMetadata['assetId'] = $request->assetId;
                }

                $plugin->getExistenceMarkers()->write($identity, $markerMetadata);
            }

            if ($recordIndex && !$request->preview && $request->assetId !== null) {
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
                    'optimizer' => $deferPostOptimize ? 'deferred' : $optimizer->name(),
                    'adapter' => $adapter->name(),
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

            if ($cleanup) {
                $plugin->getTemporaryFiles()->cleanup();
                if ($request->assetId !== null) {
                    $plugin->getSourceResolver()->clearSourceCache($request->assetId);
                }
            }
        }
    }

    /**
     * Resolve basename / folder-hash path segments for storage layout.
     *
     * Uses lightweight asset metadata only — never copies or downloads the file.
     *
     * @param GenerationRequest $request The generation request.
     * @param Plugin $plugin Plugin instance.
     *
     * @return array{assetId: int|null, basename: string|null, folderHash: string|null}
     */
    private function resolvePathMeta(GenerationRequest $request, Plugin $plugin): array
    {
        if ($request->assetId !== null) {
            try {
                $meta = $plugin->getSourceResolver()->assetPathMeta($request->assetId);

                return [
                    'assetId' => $request->assetId,
                    'basename' => $meta['basename'],
                    'folderHash' => $meta['folderHash'],
                ];
            } catch (\Throwable) {
                return [
                    'assetId' => $request->assetId,
                    'basename' => null,
                    'folderHash' => null,
                ];
            }
        }

        if ($request->localPath !== null) {
            return [
                'assetId' => null,
                'basename' => pathinfo($request->localPath, PATHINFO_FILENAME) ?: null,
                'folderHash' => null,
            ];
        }

        if ($request->remoteUrl !== null) {
            $path = parse_url($request->remoteUrl, PHP_URL_PATH);

            return [
                'assetId' => null,
                'basename' => is_string($path) ? (pathinfo($path, PATHINFO_FILENAME) ?: null) : null,
                'folderHash' => null,
            ];
        }

        return [
            'assetId' => null,
            'basename' => null,
            'folderHash' => null,
        ];
    }

    /**
     * Build the storage namespace segment for preview generations.
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
     * Queue a post-encode optimizer job that overwrites the stored derivative in place.
     *
     * @param string $storageAdapter Adapter handle.
     * @param string $storagePath Relative storage path.
     * @param string $format Format slug.
     * @param string $mime Content type.
     * @param string $tool Optimizer tool slug.
     * @param string|null $binary Optional binary path.
     * @param int|null $quality Optional quality hint.
     * @param list<string> $arguments Custom CLI arguments.
     *
     * @return void
     */
    private function enqueuePostOptimize(
        string $storageAdapter,
        string $storagePath,
        string $format,
        string $mime,
        string $tool,
        ?string $binary,
        ?int $quality,
        array $arguments,
    ): void {
        Craft::$app->getQueue()->push(new OptimizeDerivativeJob([
            'storageAdapter' => $storageAdapter,
            'storagePath' => $storagePath,
            'format' => $format,
            'mime' => $mime,
            'tool' => $tool,
            'binary' => $binary,
            'quality' => $quality,
            'arguments' => $arguments,
        ]));
    }

    /**
     * Build options passed to {@see BinaryOptimizer::optimize()}.
     *
     * @param string|null $tool Optimizer tool slug.
     * @param string|null $binary Optional absolute binary path.
     * @param int|null $quality Encode quality for tools that accept it.
     * @param int|string|null $effort Compression effort / method for cwebp-style tools.
     * @param list<string> $arguments Custom CLI arguments (may include tokens).
     *
     * @return array<string, mixed>
     */
    private function buildOptimizerOptions(
        ?string $tool,
        ?string $binary,
        ?int $quality,
        int|string|null $effort,
        array $arguments,
    ): array {
        $options = array_filter([
            'tool' => $tool,
            'binary' => $binary,
            'quality' => $quality,
            'effort' => $effort,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        if ($arguments !== []) {
            $options['arguments'] = $arguments;
        }

        return $options;
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
     * Whether encoded payload bytes match the expected output format.
     *
     * Used after PNG→cwebp/avifenc so a failed binary never ships a PNG as `.webp`.
     *
     * @param EncodedImage $encoded Encoded or optimized payload.
     * @param string $format Expected format slug (webp, avif, …).
     *
     * @return bool True when magic bytes / mime match the format.
     */
    private function encodedMatchesFormat(EncodedImage $encoded, string $format): bool
    {
        $format = $this->normalizeFormatKey($format);
        $expectedMime = $this->mimeFromFormat($format);

        $header = '';
        if ($encoded->hasPath() && is_readable((string) $encoded->path)) {
            $header = (string) file_get_contents((string) $encoded->path, false, null, 0, 16);
        } elseif ($encoded->hasBytes()) {
            $header = substr((string) $encoded->bytes, 0, 16);
        }

        if ($header === '') {
            return false;
        }

        return match ($format) {
            'webp' => str_starts_with($header, 'RIFF') && str_contains($header, 'WEBP'),
            'avif' => str_contains($header, 'ftyp'),
            'png' => str_starts_with($header, "\x89PNG"),
            'jpeg' => str_starts_with($header, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($header, 'GIF8'),
            default => $encoded->mime === $expectedMime,
        };
    }

    /**
     * Resolve width/height/size for a derivative that already exists (cache hit).
     *
     * Prefers local filesystem image headers; falls back to existence-marker metadata.
     *
     * @param StorageAdapterInterface $adapter Selected storage adapter.
     * @param string $storagePath Relative storage path.
     * @param string $identity Derivative identity key.
     * @param bool $objectExists Whether the storage object exists.
     * @param bool $markerExists Whether an existence marker exists.
     *
     * @return array{0: int, 1: int, 2: int} Tuple of [width, height, size].
     */
    private function resolveExistingDerivativeMeta(
        StorageAdapterInterface $adapter,
        string $storagePath,
        string $identity,
        bool $objectExists,
        bool $markerExists,
    ): array {
        $width = 0;
        $height = 0;
        $size = 0;

        if ($objectExists && $adapter instanceof LocalStorageAdapter) {
            $meta = $adapter->imageMeta($storagePath);
            if ($meta !== null) {
                $width = $meta['width'];
                $height = $meta['height'];
                $size = $meta['size'];
            }
        }

        if (($width <= 0 || $height <= 0 || $size <= 0) && $markerExists) {
            $payload = Plugin::getInstance()->getExistenceMarkers()->read($identity);
            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            if ($width <= 0) {
                $width = (int) ($metadata['width'] ?? 0);
            }
            if ($height <= 0) {
                $height = (int) ($metadata['height'] ?? 0);
            }
            if ($size <= 0) {
                $size = (int) ($metadata['size'] ?? 0);
            }
        }

        return [$width, $height, $size];
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

    /**
     * Rejects generation when the master `enabled` switch is off.
     *
     * @throws SuperImagesException When Super Images is disabled.
     */
    private function assertPluginEnabled(): void
    {
        if (!Plugin::getInstance()->isEnabled()) {
            throw new SuperImagesException(
                'Super Images is disabled (`enabled => false` in config/super-images.php).',
            );
        }
    }
}
