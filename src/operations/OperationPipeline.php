<?php
/**
 * Applies normalized operations in deterministic order.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\operations;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\exceptions\InvalidOperationException;
use amici\SuperImages\exceptions\UnsupportedOperationException;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\Plugin;
use yii\base\Component;

/**
 * Operation Pipeline
 *
 * Resolves operation definitions from the registry and applies them sequentially to an image handle.
 * Throws when a definition is invalid or the active driver does not support a requested operation.
 */
class OperationPipeline extends Component
{
    /**
     * Applies each operation definition to the image handle in order.
     *
     * @param ImageHandle $handle The image to transform.
     * @param ImageDriverInterface $driver The active image driver.
     * @param list<OperationDefinition> $definitions Normalized operations to apply.
     *
     * @return ImageHandle The image handle after all operations have been applied.
     *
     * @throws UnsupportedOperationException When the driver does not support an operation.
     */
    public function apply(ImageHandle $handle, ImageDriverInterface $driver, array $definitions): ImageHandle
    {
        $registry = Plugin::getInstance()->getOperationRegistry();

        foreach ($definitions as $definition) {
            $operation = $registry->create($definition);

            if (!$operation->supports($handle, $driver)) {
                throw new UnsupportedOperationException(
                    sprintf('Operation "%s" is not supported by driver "%s".', $operation->name(), $driver->name()),
                );
            }

            $handle = $operation->apply($handle, $driver);
        }

        return $handle;
    }

    /**
     * Converts raw operation arrays into normalized {@see OperationDefinition} instances.
     *
     * @param list<array<string, mixed>> $rawOperations Operation arrays from profile or request config.
     *
     * @return list<OperationDefinition>
     *
     * @throws InvalidOperationException When an entry is not an array.
     */
    public function normalizeDefinitions(array $rawOperations): array
    {
        $definitions = [];

        foreach ($rawOperations as $raw) {
            if (!is_array($raw)) {
                throw new InvalidOperationException('Operation definition must be an array.');
            }

            $definitions[] = OperationDefinition::fromArray($raw);
        }

        return $definitions;
    }
}
