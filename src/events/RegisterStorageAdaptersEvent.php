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
 * - `$adapters`: ready instances keyed by handle (overrides config for that handle)
 * - `$configs`: optional config snapshots keyed by handle
 * - `$types`: factories for config `type` values, so third parties can use
 *   `'type' => 'gcs'` in `storage.adapters` without shipping an instance up front
 *
 * @var array<string, callable(string $name, array<string, mixed> $config): StorageAdapterInterface> $types
 */
class RegisterStorageAdaptersEvent extends Event
{
    /**
     * Storage adapter instances keyed by handle.
     *
     * @var array<string, StorageAdapterInterface>
     */
    public array $adapters = [];

    /**
     * Optional adapter configuration keyed by handle.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $configs = [];

    /**
     * Type factories keyed by `type` string from config (e.g. `gcs`, `azure`).
     *
     * @var array<string, callable(string, array<string, mixed>): StorageAdapterInterface>
     */
    public array $types = [];
}
