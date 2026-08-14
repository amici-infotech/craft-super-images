<?php
/**
 * Allows third parties to register storage adapters.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\events;

use amici\SuperImages\contracts\StorageAdapterInterface;
use yii\base\Event;

/**
 * Register Storage Adapters Event
 *
 * Populate `$adapters` as name => adapter (optional config in `$configs`).
 */
class RegisterStorageAdaptersEvent extends Event
{
    /** @var array<string, StorageAdapterInterface> */
    public array $adapters = [];

    /** @var array<string, array<string, mixed>> */
    public array $configs = [];
}
