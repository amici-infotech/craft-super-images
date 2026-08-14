<?php
/**
 * Operational doctor checks and dashboard summaries.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\Plugin;
use amici\SuperImages\support\UbuntuInstallHints;
use Craft;
use yii\base\Component;

/**
 * Diagnostics Service
 */
class DiagnosticsService extends Component
{
    public const GROUP_CORE = 'core';
    public const GROUP_DRIVERS = 'drivers';
    public const GROUP_OPTIMIZERS = 'optimizers';
    public const GROUP_STORAGE = 'storage';
    public const GROUP_DELIVERY = 'delivery';
    public const GROUP_QUEUE = 'queue';

    /**
     * Ordered group labels for doctor output.
     *
     * @return array<string, string>
     */
    public function doctorGroups(): array
    {
        return [
            self::GROUP_CORE => 'Core',
            self::GROUP_DRIVERS => 'Drivers',
            self::GROUP_OPTIMIZERS => 'Optimizers',
            self::GROUP_STORAGE => 'Storage & paths',
            self::GROUP_DELIVERY => 'Delivery',
            self::GROUP_QUEUE => 'Queue',
        ];
    }

    /**
     * Run doctor checks.
     *
     * @return list<array{id: string, group: string, status: 'pass'|'warn'|'fail', label: string, detail: string, solution: ?string}>
     */
    public function runDoctor(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $checks = [];

        $checks[] = $this->check(
            'enabled',
            self::GROUP_CORE,
            $settings->enabled ? 'pass' : 'fail',
            'Plugin enabled',
            $settings->enabled
                ? 'Super Images is enabled.'
                : 'Super Images is disabled in config.',
            $settings->enabled
                ? null
                : 'Set `enabled => true` in `config/super-images.php`, then clear caches.',
        );

        $selectedName = null;
        try {
            $selectedName = $plugin->getDriverManager()->select($settings->driver)->name();
        } catch (\Throwable) {
            $selectedName = null;
        }

        foreach (['gd', 'imagick', 'libvips'] as $driverName) {
            $driver = null;
            foreach ($plugin->getDriverManager()->all() as $candidate) {
                if ($candidate->name() === $driverName) {
                    $driver = $candidate;
                    break;
                }
            }

            $available = $driver !== null && $driver->isAvailable();
            $label = strtoupper($driverName) === 'GD' ? 'GD' : ucfirst($driverName);
            $detail = $available ? 'Available' : 'Not available on this host';
            if ($available && $selectedName === $driverName) {
                $detail .= ' · selected';
            }

            $checks[] = $this->check(
                'driver-' . $driverName,
                self::GROUP_DRIVERS,
                $available ? 'pass' : 'warn',
                $label,
                $detail,
                $available ? null : $this->formatInstallHint(UbuntuInstallHints::forDriver($driverName)),
            );
        }

        try {
            $selected = $plugin->getDriverManager()->select($settings->driver);
            $formats = $selected->capabilities()->formats;
            $checks[] = $this->check(
                'formats',
                self::GROUP_DRIVERS,
                $formats !== [] ? 'pass' : 'fail',
                'Encode formats',
                $formats !== []
                    ? sprintf('%s → %s', $selected->name(), implode(', ', $formats))
                    : sprintf('%s reports no formats', $selected->name()),
                $formats !== []
                    ? null
                    : 'Install/enable an image driver (php-gd, php-imagick, or libvips) and set `driver` in config.',
            );
        } catch (\Throwable $exception) {
            $checks[] = $this->check(
                'formats',
                self::GROUP_DRIVERS,
                'fail',
                'Encode formats',
                $exception->getMessage(),
                'Install at least one driver (`sudo apt-get install -y php-gd`) and restart PHP-FPM. Prefer `driver => \'auto\'`.',
            );
        }

        $optimizersEnabled = (bool) ($settings->optimizers['enabled'] ?? true);
        if (!$optimizersEnabled) {
            $checks[] = $this->check(
                'optimizers-enabled',
                self::GROUP_OPTIMIZERS,
                'pass',
                'Optimizers',
                'Disabled in config (native encode only).',
            );
        } else {
            $inventory = $plugin->getBinaryResolver()->inventory();
            $formatTools = [];
            foreach (['jpeg', 'png', 'webp', 'avif'] as $format) {
                [$tool] = $plugin->getOptimizerManager()->normalizeToolConfig(
                    $settings->optimizers[$format] ?? null,
                );
                if ($tool !== null) {
                    $formatTools[$tool] = $format;
                }
            }

            foreach ($inventory as $tool => $row) {
                $configured = $row['configured'] ?? null;
                $resolved = $row['resolved'] ?? null;
                $available = (bool) ($row['available'] ?? false);
                $assigned = $formatTools[$tool] ?? null;

                if ($available) {
                    $detail = (string) $resolved;
                    if (
                        is_string($configured)
                        && $configured !== ''
                        && $configured !== $resolved
                        && $configured !== $tool
                    ) {
                        $detail .= sprintf(' (config: %s)', $configured);
                    }
                    if ($assigned !== null) {
                        $detail .= sprintf(' · used for %s', $assigned);
                    }
                    $checks[] = $this->check(
                        'binary-' . $tool,
                        self::GROUP_OPTIMIZERS,
                        'pass',
                        $tool,
                        $detail,
                    );
                } else {
                    $detail = 'Not found';
                    if (
                        is_string($configured)
                        && $configured !== ''
                        && $configured !== $tool
                    ) {
                        $detail .= sprintf(' (config: %s)', $configured);
                    }
                    if ($assigned !== null) {
                        $detail .= sprintf(' · configured for %s', $assigned);
                    }

                    $solution = $this->formatInstallHint(UbuntuInstallHints::forBinary($tool));
                    if ($assigned !== null && $solution !== null) {
                        $solution .= sprintf(
                            ' Then set `optimizers.binaries[\'%s\']` (or SUPER_IMAGES_%s) to the binary path, e.g. /usr/bin/%s.',
                            $tool,
                            strtoupper($tool),
                            $tool,
                        );
                    } elseif ($assigned === null && $solution !== null) {
                        $solution .= ' Optional until assigned to a format in `optimizers`.';
                    }

                    $checks[] = $this->check(
                        'binary-' . $tool,
                        self::GROUP_OPTIMIZERS,
                        'warn',
                        $tool,
                        $detail,
                        $solution,
                    );
                }
            }
        }

        $storageDefault = (string) ($settings->storage['default'] ?? 'local');
        $adapterConfig = $settings->storage['adapters'][$storageDefault] ?? null;
        if (!is_array($adapterConfig)) {
            $checks[] = $this->check(
                'storage-writable',
                self::GROUP_STORAGE,
                'fail',
                'Derivative storage',
                sprintf('Default adapter "%s" is not configured.', $storageDefault),
                'Add the adapter under `storage.adapters` in `config/super-images.php`, or change `storage.default`.',
            );
        } else {
            $type = (string) ($adapterConfig['type'] ?? 'local');
            if ($type !== 'local') {
                $checks[] = $this->check(
                    'storage-writable',
                    self::GROUP_STORAGE,
                    'pass',
                    'Derivative storage',
                    sprintf('Default adapter "%s" (%s) — local write check skipped.', $storageDefault, $type),
                );
            } else {
                $root = (string) Craft::getAlias((string) ($adapterConfig['path'] ?? '@webroot/uploads/super-images'));
                $writable = $this->ensureWritableDirectory($root);
                $checks[] = $this->check(
                    'storage-writable',
                    self::GROUP_STORAGE,
                    $writable ? 'pass' : 'fail',
                    'Derivative storage',
                    $writable
                        ? $root
                        : sprintf('Not writable: %s', $root),
                    $writable
                        ? null
                        : sprintf('Create the directory and grant write access to the PHP user, e.g. `sudo mkdir -p %s && sudo chown -R www-data:www-data %s`.', $root, $root),
                );
            }
        }

        $markerConfig = $settings->storage['markers'] ?? [];
        $markersEnabled = (bool) ($markerConfig['enabled'] ?? true);
        $markerPath = (string) Craft::getAlias((string) ($markerConfig['path'] ?? '@storage/super-images/markers'));
        if (!$markersEnabled) {
            $checks[] = $this->check(
                'markers-path',
                self::GROUP_STORAGE,
                'pass',
                'Existence markers',
                'Disabled in config.',
            );
        } else {
            $writable = $this->ensureWritableDirectory($markerPath);
            $checks[] = $this->check(
                'markers-path',
                self::GROUP_STORAGE,
                $writable ? 'pass' : 'fail',
                'Existence markers',
                $writable
                    ? $markerPath
                    : sprintf('Not writable: %s', $markerPath),
                $writable
                    ? null
                    : sprintf('Ensure Craft storage is writable: `sudo chown -R www-data:www-data %s`.', dirname($markerPath)),
            );
        }

        $tempPath = Craft::$app->getPath()->getTempPath();
        $tempWritable = is_dir($tempPath) && is_writable($tempPath);
        $checks[] = $this->check(
            'temp-writable',
            self::GROUP_STORAGE,
            $tempWritable ? 'pass' : 'fail',
            'Temp directory',
            $tempWritable
                ? $tempPath
                : sprintf('Not writable: %s', $tempPath),
            $tempWritable
                ? null
                : sprintf('Fix permissions on Craft runtime temp: `sudo chown -R www-data:www-data %s`.', $tempPath),
        );

        $deliveryMode = (string) ($settings->delivery['mode'] ?? 'lazy');
        $runtimeEnabled = (bool) ($settings->runtime['enabled'] ?? true);
        $signingSecret = $settings->runtime['signingSecret'] ?? null;
        $hasSigning = is_string($signingSecret) && $signingSecret !== ''
            || Craft::$app->getConfig()->getGeneral()->securityKey !== '';

        $checks[] = $this->check(
            'delivery-mode',
            self::GROUP_DELIVERY,
            in_array($deliveryMode, ['eager', 'lazy', 'hybrid'], true) ? 'pass' : 'fail',
            'Mode',
            $deliveryMode,
            in_array($deliveryMode, ['eager', 'lazy', 'hybrid'], true)
                ? null
                : 'Set `delivery.mode` to `lazy`, `eager`, or `hybrid` in `config/super-images.php`.',
        );

        if (in_array($deliveryMode, ['lazy', 'hybrid'], true)) {
            if (!$runtimeEnabled) {
                $checks[] = $this->check(
                    'runtime-signing',
                    self::GROUP_DELIVERY,
                    'fail',
                    'Runtime signing',
                    sprintf('Required for "%s" mode, but runtime generation is disabled.', $deliveryMode),
                    'Set `runtime.enabled => true`, or switch `delivery.mode` to `eager` and pre-generate via CLI/queue.',
                );
            } elseif (!$hasSigning) {
                $checks[] = $this->check(
                    'runtime-signing',
                    self::GROUP_DELIVERY,
                    'fail',
                    'Runtime signing',
                    'No signing secret or Craft security key available.',
                    'Set `SUPER_IMAGES_SIGNING_SECRET` / `runtime.signingSecret`, or ensure Craft `securityKey` is configured.',
                );
            } else {
                $source = is_string($signingSecret) && $signingSecret !== ''
                    ? 'config signingSecret'
                    : 'Craft securityKey';
                $checks[] = $this->check(
                    'runtime-signing',
                    self::GROUP_DELIVERY,
                    'pass',
                    'Runtime signing',
                    sprintf('Ready (%s)', $source),
                );
            }
        } else {
            $checks[] = $this->check(
                'runtime-signing',
                self::GROUP_DELIVERY,
                'pass',
                'Runtime signing',
                'Not required for eager mode.',
            );
        }

        $queue = $this->queueCounts();
        if ($queue['available'] === false) {
            $checks[] = $this->check(
                'queue-counts',
                self::GROUP_QUEUE,
                'warn',
                'Craft queue',
                'Queue table is not available.',
                'Run pending Craft migrations (`php craft migrate/all`) so the queue table exists.',
            );
        } else {
            $status = $queue['failed'] > 0 ? 'warn' : 'pass';
            $checks[] = $this->check(
                'queue-counts',
                self::GROUP_QUEUE,
                $status,
                'Craft queue',
                sprintf(
                    'pending %d · failed %d · reserved %d',
                    $queue['pending'],
                    $queue['failed'],
                    $queue['reserved'],
                ),
                $queue['failed'] > 0
                    ? 'Open Utilities → Queue, inspect failed Super Images jobs, fix the error, then retry or delete them. Keep `php craft queue/listen` running in production.'
                    : null,
            );
        }

        return $checks;
    }

    /**
     * Grouped doctor report for CLI/CP rendering.
     *
     * @return array{
     *     groups: list<array{id: string, label: string, checks: list<array{id: string, group: string, status: 'pass'|'warn'|'fail', label: string, detail: string, solution: ?string}>}>,
     *     summary: array{pass: int, warn: int, fail: int, total: int}
     * }
     */
    public function doctorReport(): array
    {
        $checks = $this->runDoctor();
        $labels = $this->doctorGroups();
        $grouped = [];

        foreach (array_keys($labels) as $groupId) {
            $grouped[$groupId] = [];
        }

        foreach ($checks as $check) {
            $groupId = $check['group'] ?? self::GROUP_CORE;
            if (!isset($grouped[$groupId])) {
                $grouped[$groupId] = [];
            }
            $grouped[$groupId][] = $check;
        }

        $pass = 0;
        $warn = 0;
        $fail = 0;
        foreach ($checks as $check) {
            match ($check['status']) {
                'pass' => $pass++,
                'warn' => $warn++,
                'fail' => $fail++,
                default => (static function (string $status): never {
                    throw new \UnhandledMatchError($status);
                })($check['status']),
            };
        }

        $groups = [];
        foreach ($grouped as $groupId => $groupChecks) {
            if ($groupChecks === []) {
                continue;
            }

            $groups[] = [
                'id' => $groupId,
                'label' => $labels[$groupId] ?? ucfirst($groupId),
                'checks' => $groupChecks,
            ];
        }

        return [
            'groups' => $groups,
            'summary' => [
                'pass' => $pass,
                'warn' => $warn,
                'fail' => $fail,
                'total' => count($checks),
            ],
        ];
    }

    /**
     * Compact summary for the CP dashboard.
     *
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $report = $this->doctorReport();
        $checks = [];
        foreach ($report['groups'] as $group) {
            foreach ($group['checks'] as $check) {
                $checks[] = $check;
            }
        }

        $selectedDriver = null;
        try {
            $selectedDriver = $plugin->getDriverManager()->select($settings->driver)->name();
        } catch (\Throwable) {
            $selectedDriver = null;
        }

        return [
            'enabled' => $settings->enabled,
            'deliveryMode' => $settings->delivery['mode'] ?? 'lazy',
            'defaultProfile' => $settings->defaultProfile,
            'defaultFormat' => $settings->defaultFormat,
            'driver' => $settings->driver,
            'selectedDriver' => $selectedDriver,
            'storageDefault' => $settings->storage['default'] ?? 'local',
            'profileCount' => count($settings->profiles),
            'doctor' => [
                'pass' => $report['summary']['pass'],
                'warn' => $report['summary']['warn'],
                'fail' => $report['summary']['fail'],
                'checks' => $checks,
                'groups' => $report['groups'],
            ],
            'queue' => $this->queueCounts(),
            'binaries' => $plugin->getBinaryResolver()->inventory(),
        ];
    }

    /**
     * @return array{available: bool, pending: int, failed: int, reserved: int}
     */
    private function queueCounts(): array
    {
        $schema = Craft::$app->getDb()->getSchema()->getTableSchema('{{%queue}}');
        if ($schema === null) {
            return [
                'available' => false,
                'pending' => 0,
                'failed' => 0,
                'reserved' => 0,
            ];
        }

        $db = Craft::$app->getDb();

        return [
            'available' => true,
            'pending' => (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%queue}} WHERE [[fail]] = 0 AND [[dateReserved]] IS NULL'
            )->queryScalar(),
            'failed' => (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%queue}} WHERE [[fail]] = 1'
            )->queryScalar(),
            'reserved' => (int) $db->createCommand(
                'SELECT COUNT(*) FROM {{%queue}} WHERE [[fail]] = 0 AND [[dateReserved]] IS NOT NULL'
            )->queryScalar(),
        ];
    }

    /**
     * @param array{package: string, command: string, notes: string}|null $hint
     */
    private function formatInstallHint(?array $hint): ?string
    {
        if ($hint === null) {
            return null;
        }

        $parts = [$hint['command']];
        if ($hint['notes'] !== '') {
            $parts[] = $hint['notes'];
        }

        return implode(' — ', $parts);
    }

    /**
     * @return array{id: string, group: string, status: 'pass'|'warn'|'fail', label: string, detail: string, solution: ?string}
     */
    private function check(
        string $id,
        string $group,
        string $status,
        string $label,
        string $detail,
        ?string $solution = null,
    ): array {
        $normalized = match ($status) {
            'pass', 'warn', 'fail' => $status,
            default => (static function (string $value): never {
                throw new \UnhandledMatchError($value);
            })($status),
        };

        return [
            'id' => $id,
            'group' => $group,
            'status' => $normalized,
            'label' => $label,
            'detail' => $detail,
            'solution' => $solution,
        ];
    }

    private function ensureWritableDirectory(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return false;
        }

        return is_writable($path);
    }
}
