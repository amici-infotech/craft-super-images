<?php
/**
 * Allows third parties to register optimizers.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\events;

use amici\SuperImages\contracts\OptimizerInterface;
use yii\base\Event;

/**
 * Register Optimizers Event
 */
class RegisterOptimizersEvent extends Event
{
    /** @var list<OptimizerInterface> */
    public array $optimizers = [];
}
