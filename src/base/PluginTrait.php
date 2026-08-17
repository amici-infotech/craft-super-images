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

    private function _registerDefaultRegistries(): void
    {
        $this->getDriverManager()->registerDefaults();
        $this->getEncoderManager()->registerDefaults();
        $this->getOptimizerManager()->registerDefaults();
        $this->getOperationRegistry()->registerDefaults();
        $this->getStorageManager()->registerFromConfig($this->getSettings()->storage);
    }

    public function getConfigurationResolver(): ConfigurationResolver { return $this->get('configurationResolver'); }
    public function getSourceResolver(): SourceResolver { return $this->get('sourceResolver'); }
    public function getGenerationIdentity(): GenerationIdentityService { return $this->get('generationIdentity'); }
    public function getGeneration(): GenerationService { return $this->get('generation'); }
    public function getStoragePathBuilder(): StoragePathBuilder { return $this->get('storagePathBuilder'); }
    public function getManifest(): ManifestService { return $this->get('manifest'); }
    public function getSignedUrls(): SignedUrlService { return $this->get('signedUrls'); }
    public function getGenerationLocks(): GenerationLockService { return $this->get('generationLocks'); }
    public function getDeliveryUrls(): DeliveryUrlService { return $this->get('deliveryUrls'); }
    public function getAutoGenerate(): AutoGenerateService { return $this->get('autoGenerate'); }
    public function getRuntimeGeneration(): RuntimeGenerationService { return $this->get('runtimeGeneration'); }
    public function getBinaryResolver(): BinaryResolver { return $this->get('binaryResolver'); }
    public function getDiagnostics(): DiagnosticsService { return $this->get('diagnostics'); }
    public function getCleanup(): CleanupService { return $this->get('cleanup'); }
    public function getAssetDerivativeIndex(): AssetDerivativeIndex { return $this->get('assetDerivativeIndex'); }
    public function getPlayground(): PlaygroundService { return $this->get('playground'); }
    public function getDriverManager(): DriverManager { return $this->get('driverManager'); }
    public function getEncoderManager(): EncoderManager { return $this->get('encoderManager'); }
    public function getOptimizerManager(): OptimizerManager { return $this->get('optimizerManager'); }
    public function getStorageManager(): StorageManager { return $this->get('storageManager'); }
    public function getOperationRegistry(): OperationRegistry { return $this->get('operationRegistry'); }
    public function getOperationPipeline(): OperationPipeline { return $this->get('operationPipeline'); }
    public function getTemporaryFiles(): TemporaryFileManager { return $this->get('temporaryFiles'); }
    public function getProcessRunner(): ProcessRunner { return $this->get('processRunner'); }
    public function getExistenceMarkers(): ExistenceMarkerStore { return $this->get('existenceMarkers'); }
    public function getDerivativeExistence(): DerivativeExistenceService { return $this->get('derivativeExistence'); }

    public static function t(string $message, array $params = []): string
    {
        return Craft::t('super-images', $message, $params);
    }
}
