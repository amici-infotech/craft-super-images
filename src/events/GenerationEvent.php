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
 *
 * Dispatched before and after derivative generation.
 */
class GenerationEvent extends Event
{
    /**
     * The incoming generation request.
     *
     * @var GenerationRequest
     */
    public GenerationRequest $request;

    /**
     * Resolved generation definition, populated during processing.
     *
     * @var GenerationDefinition|null
     */
    public ?GenerationDefinition $definition = null;

    /**
     * Generation result, populated after a successful run.
     *
     * @var GenerationResult|null
     */
    public ?GenerationResult $result = null;

    /**
     * Stable identity hash for the derivative being generated.
     *
     * @var string|null
     */
    public ?string $identity = null;
}
