<?php
/**
 * Allows third parties to register image drivers.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\events;

use amici\SuperImages\contracts\ImageDriverInterface;
use yii\base\Event;

/**
 * Register Drivers Event
 *
 * Append driver instances to `$drivers` during registration.
 */
class RegisterDriversEvent extends Event
{
    /**
     * Driver instances to register.
     *
     * @var list<ImageDriverInterface>
     */
    public array $drivers = [];
}
