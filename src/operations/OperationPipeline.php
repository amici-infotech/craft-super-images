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
 */
class OperationPipeline extends Component
{
    /**
     * @param list<OperationDefinition> $definitions
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
     * @param list<array<string, mixed>> $rawOperations
     * @return list<OperationDefinition>
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
