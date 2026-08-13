<?php

namespace amici\SuperImages\services;

use amici\SuperImages\models\GenerationDefinition;
use yii\base\Component;

final class GenerationIdentityService extends Component
{
    public function calculate(GenerationDefinition $definition, string $driverName): string
    {
        $payload = $definition->toIdentityPayload();
        $payload['driverName'] = $driverName;

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
