<?php
/**
 * Allows third parties to register encoders.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\events;

use amici\SuperImages\contracts\EncoderInterface;
use yii\base\Event;

/**
 * Register Encoders Event
 */
class RegisterEncodersEvent extends Event
{
    /** @var list<EncoderInterface> */
    public array $encoders = [];
}
