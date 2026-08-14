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
 */
class RegisterDriversEvent extends Event
{
    /** @var list<ImageDriverInterface> */
    public array $drivers = [];
}
