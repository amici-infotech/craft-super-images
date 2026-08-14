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
 *
 * Append encoder instances to `$encoders` during registration.
 */
class RegisterEncodersEvent extends Event
{
    /**
     * Encoder instances to register.
     *
     * @var list<EncoderInterface>
     */
    public array $encoders = [];
}
