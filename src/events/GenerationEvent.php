<?php
/**
 * Fired around derivative generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\events;

use amici\SuperImages\models\GenerationDefinition;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\GenerationResult;
use yii\base\Event;

/**
 * Generation Event
 */
class GenerationEvent extends Event
{
    public GenerationRequest $request;

    public ?GenerationDefinition $definition = null;

    public ?GenerationResult $result = null;

    public ?string $identity = null;
}
