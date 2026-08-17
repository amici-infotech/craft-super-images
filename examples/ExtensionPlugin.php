<?php
/**
 * Minimal Craft plugin showing how to register Super Images extensions.
 *
 * Copy into your plugin namespace and call registerSuperImagesExtensions() from init().
 */

namespace myagency\superimages\examples;

use amici\SuperImages\events\RegisterEncodersEvent;
use amici\SuperImages\events\RegisterOperationsEvent;
use amici\SuperImages\events\RegisterOptimizersEvent;
use amici\SuperImages\events\RegisterStorageAdaptersEvent;
use amici\SuperImages\registries\EncoderManager;
use amici\SuperImages\registries\OperationRegistry;
use amici\SuperImages\registries\OptimizerManager;
use amici\SuperImages\registries\StorageManager;
use craft\base\Plugin;
use yii\base\Event;

/**
 * Extension Plugin (reference only)
 *
 * Demonstrates wiring storage adapters, encoders, optimizers, and operations
 * from a single Craft plugin init() hook. Rename the namespace and merge into
 * your real plugin class.
 */
class ExtensionPlugin extends Plugin
{
    /**
     * Boots the plugin and registers Super Images extension points.
     *
     * @return void
     */
    public function init(): void
    {
        parent::init();
        $this->registerSuperImagesExtensions();
    }

    /**
     * Registers all Super Images extension listeners.
     *
     * Each listener runs during Super Images boot before generation. Craft plugin
     * init order is sufficient — you do not need a custom bootstrap priority.
     *
     * @return void
     */
    private function registerSuperImagesExtensions(): void
    {
        Event::on(
            StorageManager::class,
            StorageManager::EVENT_REGISTER_STORAGE_ADAPTERS,
            static function (RegisterStorageAdaptersEvent $event): void {
                // Config-driven: set 'type' => 'acme' in config/super-images.php adapters block.
                $event->types['acme'] = static fn(string $name, array $config) => new storage\ExampleStorageAdapter($name, $config);

                // Or register a ready-made instance (overrides config for that handle):
                // $event->adapters['acme'] = new storage\ExampleStorageAdapter('acme', []);
            },
        );

        Event::on(
            EncoderManager::class,
            EncoderManager::EVENT_REGISTER_ENCODERS,
            static function (RegisterEncodersEvent $event): void {
                // Replaces the native encoder for WebP while this plugin is loaded.
                $event->encoders[] = new encoders\ExampleWebpEncoder();
            },
        );

        Event::on(
            OptimizerManager::class,
            OptimizerManager::EVENT_REGISTER_OPTIMIZERS,
            static function (RegisterOptimizersEvent $event): void {
                // name() must match the tool string in config, e.g. optimizers.jpeg = 'example-jpeg'.
                $event->optimizers[] = new optimizers\ExampleOptimizer();
            },
        );

        Event::on(
            OperationRegistry::class,
            OperationRegistry::EVENT_REGISTER_OPERATIONS,
            static function (RegisterOperationsEvent $event): void {
                $event->operations['tint'] = operations\ExampleTintOperation::class;
            },
        );
    }
}
