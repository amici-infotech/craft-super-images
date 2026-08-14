<?php
/**
 * Allows third parties to register operations.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\events;

use amici\SuperImages\contracts\OperationInterface;
use yii\base\Event;

/**
 * Register Operations Event
 *
 * Populate `$operations` as name => class-string.
 */
class RegisterOperationsEvent extends Event
{
    /** @var array<string, class-string<OperationInterface>> */
    public array $operations = [];
}
