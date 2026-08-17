<?php
/**
 * Shared plugin service registration trait.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\base;

use amici\SuperImages\operations\OperationPipeline;
use amici\SuperImages\registries\DriverManager;
use amici\SuperImages\registries\EncoderManager;
use amici\SuperImages\registries\OperationRegistry;
use amici\SuperImages\registries\OptimizerManager;
use amici\SuperImages\registries\StorageManager;
use amici\SuperImages\services\AssetDerivativeIndex;
use amici\SuperImages\services\AutoGenerateService;
use amici\SuperImages\services\BinaryResolver;
use amici\SuperImages\services\CleanupService;
use amici\SuperImages\services\ConfigurationResolver;
use amici\SuperImages\services\DeliveryUrlService;
use amici\SuperImages\services\DerivativeExistenceService;
use amici\SuperImages\services\DiagnosticsService;
use amici\SuperImages\services\GenerationIdentityService;
use amici\SuperImages\services\GenerationLockService;
use amici\SuperImages\services\GenerationService;
use amici\SuperImages\services\ManifestService;
use amici\SuperImages\services\PlaygroundService;
use amici\SuperImages\services\RuntimeGenerationService;
use amici\SuperImages\services\SignedUrlService;
use amici\SuperImages\services\SourceResolver;
use amici\SuperImages\services\StoragePathBuilder;
use amici\SuperImages\storage\ExistenceMarkerStore;
use amici\SuperImages\support\ProcessRunner;
use amici\SuperImages\support\TemporaryFileManager;
use Craft;

/**
 * Plugin Trait
 *
 * Registers engine services and exposes typed accessors.
 *
 * @property-read ConfigurationResolver $configurationResolver
 * @property-read SourceResolver $sourceResolver
 * @property-read GenerationIdentityService $generationIdentity
 * @property-read GenerationService $generation
 * @property-read StoragePathBuilder $storagePathBuilder
 * @property-read ManifestService $manifest
 * @property-read SignedUrlService $signedUrls
 * @property-read GenerationLockService $generationLocks
 * @property-read DeliveryUrlService $deliveryUrls
 * @property-read AutoGenerateService $autoGenerate
 * @property-read RuntimeGenerationService $runtimeGeneration
 * @property-read BinaryResolver $binaryResolver
 * @property-read DiagnosticsService $diagnostics
 * @property-read CleanupService $cleanup
 * @property-read AssetDerivativeIndex $assetDerivativeIndex
 * @property-read PlaygroundService $playground
 * @property-read DriverManager $driverManager
 * @property-read EncoderManager $encoderManager
 * @property-read OptimizerManager $optimizerManager
 * @property-read StorageManager $storageManager
 * @property-read OperationRegistry $operationRegistry
 * @property-read OperationPipeline $operationPipeline
 * @property-read TemporaryFileManager $temporaryFiles
 * @property-read ProcessRunner $processRunner
 * @property-read DerivativeExistenceService $derivativeExistence
 * @property-read ExistenceMarkerStore $existenceMarkers
 */
trait PluginTrait
{
    /**
     * Registers service components used by the plugin accessors.
     *
     * Components registered:
     * - `configurationResolver` — merges profile/variant/format config for a request
     * - `sourceResolver` — resolves Craft assets, local paths, and remote URLs
     * - `generationIdentity` — builds stable cache keys for derivatives
     * - `storagePathBuilder` — constructs storage object keys
     * - `generation` — orchestrates eager derivative generation
     * - `manifest` — expands profiles into manifest units for an asset
     * - `signedUrls` — signs and verifies lazy runtime URLs
     * - `generationLocks` — prevents duplicate concurrent generation
     * - `deliveryUrls` — resolves storage vs runtime delivery URLs
     * - `autoGenerate` — enqueues generation on asset save
     * - `runtimeGeneration` — handles signed lazy generation requests
     * - `binaryResolver` — locates external optimizer binaries
     * - `diagnostics` — doctor checks and dashboard summaries
     * - `cleanup` — removes stale preview artifacts
     * - `assetDerivativeIndex` — per-asset derivative index for cleanup
     * - `playground` — CP playground generation helper
     * - `driverManager` — image driver registry
     * - `encoderManager` — encoder registry
     * - `optimizerManager` — post-encode optimizer registry
     * - `storageManager` — storage adapter registry
     * - `operationRegistry` — transform operation registry
     * - `operationPipeline` — executes ordered operations on a handle
     * - `temporaryFiles` — manages temp files during processing
     * - `processRunner` — runs external CLI processes safely
     * - `existenceMarkers` — tracks generated object existence
     *
     * @return void
     */
    private function _setPluginComponents(): void
    {
        $this->setComponents([
            'configurationResolver' => ConfigurationResolver::class,
            'sourceResolver' => SourceResolver::class,
            'generationIdentity' => GenerationIdentityService::class,
            'storagePathBuilder' => StoragePathBuilder::class,
            'generation' => GenerationService::class,
            'manifest' => ManifestService::class,
            'signedUrls' => SignedUrlService::class,
            'generationLocks' => GenerationLockService::class,
            'deliveryUrls' => DeliveryUrlService::class,
            'autoGenerate' => AutoGenerateService::class,
            'runtimeGeneration' => RuntimeGenerationService::class,
            'binaryResolver' => BinaryResolver::class,
            'diagnostics' => DiagnosticsService::class,
            'cleanup' => CleanupService::class,
            'assetDerivativeIndex' => AssetDerivativeIndex::class,
            'playground' => PlaygroundService::class,
            'driverManager' => DriverManager::class,
            'encoderManager' => EncoderManager::class,
            'optimizerManager' => OptimizerManager::class,
            'storageManager' => StorageManager::class,
            'operationRegistry' => OperationRegistry::class,
            'operationPipeline' => OperationPipeline::class,
            'temporaryFiles' => TemporaryFileManager::class,
            'processRunner' => ProcessRunner::class,
            'existenceMarkers' => ExistenceMarkerStore::class,
            'derivativeExistence' => DerivativeExistenceService::class,
        ]);
    }

    /**
     * Registers default drivers, encoders, optimizers, operations, and storage adapters.
     *
     * Storage adapters are loaded from the plugin settings `storage` config.
     *
     * @return void
     */
    private function _registerDefaultRegistries(): void
    {
        $this->getDriverManager()->registerDefaults();
        $this->getEncoderManager()->registerDefaults();
        $this->getOptimizerManager()->registerDefaults();
        $this->getOperationRegistry()->registerDefaults();
        $this->getStorageManager()->registerFromConfig($this->getSettings()->storage);
    }

    /**
     * Returns the configuration resolver service.
     *
     * @return ConfigurationResolver
     */
    public function getConfigurationResolver(): ConfigurationResolver
    {
        return $this->get('configurationResolver');
    }

    /**
     * Returns the source resolver service.
     *
     * @return SourceResolver
     */
    public function getSourceResolver(): SourceResolver
    {
        return $this->get('sourceResolver');
    }

    /**
     * Returns the generation identity service.
     *
     * @return GenerationIdentityService
     */
    public function getGenerationIdentity(): GenerationIdentityService
    {
        return $this->get('generationIdentity');
    }

    /**
     * Returns the generation orchestration service.
     *
     * @return GenerationService
     */
    public function getGeneration(): GenerationService
    {
        return $this->get('generation');
    }

    /**
     * Returns the storage path builder service.
     *
     * @return StoragePathBuilder
     */
    public function getStoragePathBuilder(): StoragePathBuilder
    {
        return $this->get('storagePathBuilder');
    }

    /**
     * Returns the manifest service.
     *
     * @return ManifestService
     */
    public function getManifest(): ManifestService
    {
        return $this->get('manifest');
    }

    /**
     * Returns the signed URL service.
     *
     * @return SignedUrlService
     */
    public function getSignedUrls(): SignedUrlService
    {
        return $this->get('signedUrls');
    }

    /**
     * Returns the generation lock service.
     *
     * @return GenerationLockService
     */
    public function getGenerationLocks(): GenerationLockService
    {
        return $this->get('generationLocks');
    }

    /**
     * Returns the delivery URL planning service.
     *
     * @return DeliveryUrlService
     */
    public function getDeliveryUrls(): DeliveryUrlService
    {
        return $this->get('deliveryUrls');
    }

    /**
     * Returns the auto-generate service.
     *
     * @return AutoGenerateService
     */
    public function getAutoGenerate(): AutoGenerateService
    {
        return $this->get('autoGenerate');
    }

    /**
     * Returns the runtime generation service.
     *
     * @return RuntimeGenerationService
     */
    public function getRuntimeGeneration(): RuntimeGenerationService
    {
        return $this->get('runtimeGeneration');
    }

    /**
     * Returns the external binary resolver service.
     *
     * @return BinaryResolver
     */
    public function getBinaryResolver(): BinaryResolver
    {
        return $this->get('binaryResolver');
    }

    /**
     * Returns the diagnostics service.
     *
     * @return DiagnosticsService
     */
    public function getDiagnostics(): DiagnosticsService
    {
        return $this->get('diagnostics');
    }

    /**
     * Returns the cleanup service.
     *
     * @return CleanupService
     */
    public function getCleanup(): CleanupService
    {
        return $this->get('cleanup');
    }

    /**
     * Returns the per-asset derivative index service.
     *
     * @return AssetDerivativeIndex
     */
    public function getAssetDerivativeIndex(): AssetDerivativeIndex
    {
        return $this->get('assetDerivativeIndex');
    }

    /**
     * Returns the playground service.
     *
     * @return PlaygroundService
     */
    public function getPlayground(): PlaygroundService
    {
        return $this->get('playground');
    }

    /**
     * Returns the driver manager registry.
     *
     * @return DriverManager
     */
    public function getDriverManager(): DriverManager
    {
        return $this->get('driverManager');
    }

    /**
     * Returns the encoder manager registry.
     *
     * @return EncoderManager
     */
    public function getEncoderManager(): EncoderManager
    {
        return $this->get('encoderManager');
    }

    /**
     * Returns the optimizer manager registry.
     *
     * @return OptimizerManager
     */
    public function getOptimizerManager(): OptimizerManager
    {
        return $this->get('optimizerManager');
    }

    /**
     * Returns the storage manager registry.
     *
     * @return StorageManager
     */
    public function getStorageManager(): StorageManager
    {
        return $this->get('storageManager');
    }

    /**
     * Returns the operation registry.
     *
     * @return OperationRegistry
     */
    public function getOperationRegistry(): OperationRegistry
    {
        return $this->get('operationRegistry');
    }

    /**
     * Returns the operation pipeline executor.
     *
     * @return OperationPipeline
     */
    public function getOperationPipeline(): OperationPipeline
    {
        return $this->get('operationPipeline');
    }

    /**
     * Returns the temporary file manager.
     *
     * @return TemporaryFileManager
     */
    public function getTemporaryFiles(): TemporaryFileManager
    {
        return $this->get('temporaryFiles');
    }

    /**
     * Returns the external process runner.
     *
     * @return ProcessRunner
     */
    public function getProcessRunner(): ProcessRunner
    {
        return $this->get('processRunner');
    }

    /**
     * Returns the existence marker store.
     *
     * @return ExistenceMarkerStore
     */
    public function getExistenceMarkers(): ExistenceMarkerStore
    {
        return $this->get('existenceMarkers');
    }

    /**
     * Returns the derivative existence helper (markers / index before remote HEAD).
     *
     * @return DerivativeExistenceService
     */
    public function getDerivativeExistence(): DerivativeExistenceService
    {
        return $this->get('derivativeExistence');
    }

    /**
     * Translates a message using the `super-images` category.
     *
     * @param string $message The message to translate.
     * @param array<string, mixed> $params Optional translation parameters.
     *
     * @return string The translated message.
     */
    public static function t(string $message, array $params = []): string
    {
        return Craft::t('super-images', $message, $params);
    }
}
