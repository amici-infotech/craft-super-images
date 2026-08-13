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
use amici\SuperImages\services\ConfigurationResolver;
use amici\SuperImages\services\GenerationIdentityService;
use amici\SuperImages\services\GenerationService;
use amici\SuperImages\services\SourceResolver;
use amici\SuperImages\services\StoragePathBuilder;
use amici\SuperImages\storage\ExistenceMarkerStore;
use amici\SuperImages\support\ProcessRunner;
use amici\SuperImages\support\TemporaryFileManager;
use Craft;

/**
 * Plugin Trait
 *
 * Registers Phase 1 engine services and exposes typed accessors.
 *
 * @property-read ConfigurationResolver $configurationResolver
 * @property-read SourceResolver $sourceResolver
 * @property-read GenerationIdentityService $generationIdentity
 * @property-read GenerationService $generation
 * @property-read StoragePathBuilder $storagePathBuilder
 * @property-read DriverManager $driverManager
 * @property-read EncoderManager $encoderManager
 * @property-read OptimizerManager $optimizerManager
 * @property-read StorageManager $storageManager
 * @property-read OperationRegistry $operationRegistry
 * @property-read OperationPipeline $operationPipeline
 * @property-read TemporaryFileManager $temporaryFiles
 * @property-read ProcessRunner $processRunner
 * @property-read ExistenceMarkerStore $existenceMarkers
 */
trait PluginTrait
{
    /**
     * Registers service components used by the plugin accessors.
     */
    private function _setPluginComponents(): void
    {
        $this->setComponents([
            'configurationResolver' => ConfigurationResolver::class,
            'sourceResolver' => SourceResolver::class,
            'generationIdentity' => GenerationIdentityService::class,
            'storagePathBuilder' => StoragePathBuilder::class,
            'generation' => GenerationService::class,
            'driverManager' => DriverManager::class,
            'encoderManager' => EncoderManager::class,
            'optimizerManager' => OptimizerManager::class,
            'storageManager' => StorageManager::class,
            'operationRegistry' => OperationRegistry::class,
            'operationPipeline' => OperationPipeline::class,
            'temporaryFiles' => TemporaryFileManager::class,
            'processRunner' => ProcessRunner::class,
            'existenceMarkers' => ExistenceMarkerStore::class,
        ]);
    }

    /**
     * Registers default drivers, encoders, optimizers, operations, and storage adapters.
     */
    private function _registerDefaultRegistries(): void
    {
        $this->getDriverManager()->registerDefaults();
        $this->getEncoderManager()->registerDefaults();
        $this->getOptimizerManager()->registerDefaults();
        $this->getOperationRegistry()->registerDefaults();
        $this->getStorageManager()->registerFromConfig($this->getSettings()->storage);
    }

    public function getConfigurationResolver(): ConfigurationResolver
    {
        return $this->get('configurationResolver');
    }

    public function getSourceResolver(): SourceResolver
    {
        return $this->get('sourceResolver');
    }

    public function getGenerationIdentity(): GenerationIdentityService
    {
        return $this->get('generationIdentity');
    }

    public function getGeneration(): GenerationService
    {
        return $this->get('generation');
    }

    public function getStoragePathBuilder(): StoragePathBuilder
    {
        return $this->get('storagePathBuilder');
    }

    public function getDriverManager(): DriverManager
    {
        return $this->get('driverManager');
    }

    public function getEncoderManager(): EncoderManager
    {
        return $this->get('encoderManager');
    }

    public function getOptimizerManager(): OptimizerManager
    {
        return $this->get('optimizerManager');
    }

    public function getStorageManager(): StorageManager
    {
        return $this->get('storageManager');
    }

    public function getOperationRegistry(): OperationRegistry
    {
        return $this->get('operationRegistry');
    }

    public function getOperationPipeline(): OperationPipeline
    {
        return $this->get('operationPipeline');
    }

    public function getTemporaryFiles(): TemporaryFileManager
    {
        return $this->get('temporaryFiles');
    }

    public function getProcessRunner(): ProcessRunner
    {
        return $this->get('processRunner');
    }

    public function getExistenceMarkers(): ExistenceMarkerStore
    {
        return $this->get('existenceMarkers');
    }

    public static function t(string $message, array $params = []): string
    {
        return Craft::t('super-images', $message, $params);
    }
}
