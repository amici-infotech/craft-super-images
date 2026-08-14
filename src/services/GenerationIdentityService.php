<?php
/**
 * Calculates deterministic generation identity hashes.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\models\GenerationDefinition;
use yii\base\Component;

/**
 * Generation Identity Service
 *
 * Produces SHA-256 identity hashes from generation definitions so storage paths,
 * existence markers, and skip logic remain stable across requests.
 */
final class GenerationIdentityService extends Component
{
    /**
     * Calculate the deterministic identity hash for a generation definition.
     *
     * @param GenerationDefinition $definition The immutable generation definition.
     * @param string $driverName The selected image driver name included in the hash.
     *
     * @return string SHA-256 hex digest identifying this derivative uniquely.
     */
    public function calculate(GenerationDefinition $definition, string $driverName): string
    {
        $payload = $definition->toIdentityPayload();
        $payload['driverName'] = $driverName;

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
