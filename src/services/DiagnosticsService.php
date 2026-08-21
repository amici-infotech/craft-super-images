<?php
/**
 * Operational doctor checks and dashboard summaries.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\Plugin;
use amici\SuperImages\support\LibvipsCliBridge;
use amici\SuperImages\support\UbuntuInstallHints;
use Craft;
use Jcupitt\Vips\Image as VipsImage;
use yii\base\Component;

/**
 * Diagnostics Service
 *
 * Runs environment and configuration health checks for CLI doctor output and the
 * Control Panel dashboard. Covers drivers, optimizers, storage, delivery, and queue state.
 */
class DiagnosticsService extends Component
{
    /** Doctor check group: core plugin settings. */
    public const GROUP_CORE = 'core';

    /** Doctor check group: image driver availability. */
    public const GROUP_DRIVERS = 'drivers';

    /** Doctor check group: post-encode optimizer binaries. */
    public const GROUP_OPTIMIZERS = 'optimizers';

    /** Doctor check group: derivative storage and temp paths. */
    public const GROUP_STORAGE = 'storage';

    /** Doctor check group: before-page-load delivery and runtime signing. */
    public const GROUP_DELIVERY = 'delivery';

    /** Doctor check group: Craft queue job counts. */
    public const GROUP_QUEUE = 'queue';

    /**
     * Ordered group labels for doctor output.
     *
     * @return array<string, string> Map of group ID to human-readable label.
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
     * Run all doctor checks and return a flat list of results.
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
                : 'Disabled: no transforms/generation. Twig falls back to original image URLs.',
            $settings->enabled
                ? null
                : 'Set `enabled => true` in `config/super-images.php` to process images, then clear caches.',
        );

        $selectedName = null;
        try {
            $selectedName = $plugin->getDriverManager()->select($settings->driver)->name();
        } catch (\Throwable) {
            $selectedName = null;
        }

        $gdOk = false;
        $imagickOk = false;
        $libvipsOk = false;
        $anyDriverOk = false;

        foreach (['gd', 'imagick', 'libvips'] as $driverName) {
            $driver = null;
            foreach ($plugin->getDriverManager()->all() as $candidate) {
                if ($candidate->name() === $driverName) {
                    $driver = $candidate;
                    break;
                }
            }

            [$detail, $solution, $usable] = $this->driverCheckParts($driver, $driverName, $selectedName);
            if ($driverName === 'gd') {
                $gdOk = $usable;
            }
            if ($driverName === 'imagick') {
                $imagickOk = $usable;
            }
            if ($driverName === 'libvips') {
                $libvipsOk = $usable;
            }
            if ($usable) {
                $anyDriverOk = true;
            }

            $label = $driverName === 'gd' ? 'GD' : ($driverName === 'libvips' ? 'Libvips' : 'Imagick');
            $status = 'pass';
            if (!$usable) {
                // Preferred driver broken = blocks intended production path → fail.
                // Other missing drivers stay warn (optional fallbacks).
                $preferred = strtolower(trim((string) ($settings->driver ?: 'auto')));
                $status = ($preferred === $driverName) ? 'fail' : 'warn';
            }

            $checks[] = $this->check(
                'driver-' . $driverName,
                self::GROUP_DRIVERS,
                $status,
                $label,
                $detail,
                $solution,
            );
        }

        if (!$anyDriverOk) {
            $checks[] = $this->check(
                'drivers-any',
                self::GROUP_DRIVERS,
                'fail',
                'No usable driver',
                'GD, Imagick, and Libvips are all unavailable in this SAPI (' . PHP_SAPI . ').',
                'Install at least one: php-gd, php-imagick, or libvips + jcupitt/vips + FFI. See docs/drivers.md, then restart PHP-FPM.',
            );
        }

        $preferred = strtolower(trim((string) ($settings->driver ?: 'auto')));
        if (in_array($preferred, ['gd', 'imagick', 'libvips'], true)) {
            $preferredOk = match ($preferred) {
                'gd' => $gdOk,
                'imagick' => $imagickOk,
                'libvips' => $libvipsOk,
                default => false,
            };
            if (!$preferredOk) {
                $checks[] = $this->check(
                    'driver-preference',
                    self::GROUP_DRIVERS,
                    'fail',
                    'Configured driver',
                    sprintf(
                        'config driver => %s is not usable; falling back%s.',
                        $preferred,
                        $selectedName !== null ? ' to ' . $selectedName : '',
                    ),
                    $this->formatInstallHint(UbuntuInstallHints::forDriver($preferred))
                        ?? 'Fix the preferred driver or set driver => auto in config/super-images.php.',
                );
            }
        }

        $checks = array_merge($checks, $this->libvipsRuntimeChecks($selectedName, $preferred));

        if ($imagickOk && $libvipsOk) {
            $checks[] = $this->check(
                'drivers-dual',
                self::GROUP_DRIVERS,
                'pass',
                'Imagick + Libvips',
                'Both usable. Preference: ' . ($settings->driver ?: 'auto') . ' (auto order: libvips → imagick → gd).',
                'Only one driver runs per request. Pin driver => imagick or libvips in config/super-images.php if needed.',
            );
        } else {
            $missing = [];
            if (!$imagickOk) {
                $missing[] = 'imagick';
            }
            if (!$libvipsOk) {
                $missing[] = 'libvips';
            }
            $checks[] = $this->check(
                'drivers-dual',
                self::GROUP_DRIVERS,
                'warn',
                'Imagick + Libvips',
                'Not both usable yet (' . implode(', ', $missing) . ' missing).' . ($gdOk ? ' GD may still run.' : ''),
                $this->formatInstallHint(UbuntuInstallHints::forDriver($missing[0] ?? 'libvips'))
                    ?? 'Install Imagick and/or libvips, enable FFI for libvips, restart PHP-FPM. See docs/drivers.md.',
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
                    : 'Install/enable GD, Imagick, or Libvips and set `driver` in config. See docs/drivers.md.',
            );
        } catch (\Throwable $exception) {
            $checks[] = $this->check(
                'formats',
                self::GROUP_DRIVERS,
                'fail',
                'Encode formats',
                $exception->getMessage(),
                'Install at least one driver and prefer `driver => auto`. Imagick: php-imagick + restart FPM. Libvips: system libs + jcupitt/vips + ffi.enable=true. See docs/drivers.md.',
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

        $beforePageLoad = $plugin->getDeliveryUrls()->generatesBeforePageLoad();
        $runtimeEnabled = (bool) ($settings->runtime['enabled'] ?? true);
        $signingSecret = $settings->runtime['signingSecret'] ?? null;
        $hasSigning = is_string($signingSecret) && $signingSecret !== ''
            || Craft::$app->getConfig()->getGeneral()->securityKey !== '';

        $checks[] = $this->check(
            'delivery-mode',
            self::GROUP_DELIVERY,
            'pass',
            'Generate before page load',
            $beforePageLoad ? 'true (sync during Twig)' : 'false (runtime action URL when missing)',
        );

        if (!$beforePageLoad) {
            if (!$runtimeEnabled) {
                $checks[] = $this->check(
                    'runtime-signing',
                    self::GROUP_DELIVERY,
                    'warn',
                    'Runtime signing',
                    'generateBeforePageLoad is false and runtime is disabled; missing files sync-generate during Twig as fallback.',
                    'Set `runtime.enabled => true` for action URLs, or `delivery.generateBeforePageLoad => true`.',
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
                'Not required when generateBeforePageLoad is true.',
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
     * Compact summary for the CP dashboard widget.
     *
     * @return array<string, mixed> Dashboard payload with settings snapshot, delivery mode, doctor results, queue, and binaries.
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

        $driverUsable = false;
        if ($selectedDriver !== null) {
            foreach ($checks as $check) {
                if (($check['id'] ?? '') === 'driver-' . $selectedDriver) {
                    $driverUsable = ($check['status'] ?? '') === 'pass';
                    break;
                }
            }
            if (!$driverUsable) {
                foreach ($plugin->getDriverManager()->all() as $candidate) {
                    if ($candidate->name() === $selectedDriver) {
                        $driverUsable = $candidate->isAvailable();
                        break;
                    }
                }
            }
        }

        $beforePageLoad = $plugin->getDeliveryUrls()->generatesBeforePageLoad();
        $queue = $this->queueCounts();

        return [
            'enabled' => $settings->enabled,
            'generateBeforePageLoad' => $beforePageLoad,
            'deliveryMode' => $beforePageLoad ? 'Before page load' : 'Runtime',
            'defaultProfile' => $settings->defaultProfile,
            'defaultFormat' => $settings->defaultFormat,
            'driver' => $settings->driver,
            'selectedDriver' => $selectedDriver,
            'driverUsable' => $driverUsable,
            'health' => $this->resolveHealthStatus($report, $queue),
            'storageDefault' => $settings->storage['default'] ?? 'local',
            'profileCount' => count($settings->profiles),
            'doctor' => [
                'pass' => $report['summary']['pass'],
                'warn' => $report['summary']['warn'],
                'fail' => $report['summary']['fail'],
                'checks' => $checks,
                'groups' => $report['groups'],
            ],
            'queue' => $queue,
            'binaries' => $plugin->getBinaryResolver()->inventory(),
        ];
    }

    /**
     * Map doctor results to a dashboard health badge status.
     *
     * Only real failures (and failed queue jobs) flip the badge. Optional
     * warnings — missing unused drivers, dual Imagick+Libvips tip, unused
     * optimizer binaries — stay Healthy. Selected/preferred driver problems
     * are elevated to `fail` in {@see runDoctor()} so they surface here.
     *
     * @param array{summary: array{fail: int}} $report
     * @param array{available: bool, failed: int} $queue
     *
     * @return 'healthy'|'warnings'|'attention'
     */
    private function resolveHealthStatus(array $report, array $queue): string
    {
        if (($report['summary']['fail'] ?? 0) > 0) {
            return 'attention';
        }

        if (($queue['available'] ?? false) && ($queue['failed'] ?? 0) > 0) {
            return 'warnings';
        }

        return 'healthy';
    }

    /**
     * Query Craft queue table counts when available.
     *
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
     * Format an Ubuntu install hint into a single solution string.
     *
     * @param array{package: string, command: string, notes: string}|null $hint Install hint from UbuntuInstallHints.
     *
     * @return string|null Combined command and notes, or null when no hint exists.
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
     * @return array{0: string, 1: ?string, 2: bool} detail, solution, usable
     */
    private function driverCheckParts(?ImageDriverInterface $driver, string $driverName, ?string $selectedName): array
    {
        $usable = $driver !== null && $driver->isAvailable();

        if ($usable) {
            $detail = 'Available · usable in this SAPI (' . PHP_SAPI . ')';
            if ($driverName === 'libvips' && LibvipsCliBridge::shouldIsolate()) {
                $detail .= ' · FPM isolation via vips CLI / PHP worker';
            }
            $solution = null;
        } else {
            $detail = $this->driverUnavailableDetail($driverName);
            $solution = $this->formatInstallHint(UbuntuInstallHints::forDriver($driverName))
                ?? 'Install the matching PHP extension or libvips + FFI. See docs/drivers.md.';
        }

        if ($usable && $selectedName === $driverName) {
            $detail .= ' · selected';
        }

        return [$detail, $solution, $usable];
    }

    private function driverUnavailableDetail(string $name): string
    {
        if ($name === 'imagick') {
            if (!extension_loaded('imagick')) {
                return 'PHP imagick extension not loaded for this SAPI (' . PHP_SAPI . ').';
            }
            if (!class_exists(\Imagick::class)) {
                return 'Imagick class missing (extension loaded but broken).';
            }

            return 'Imagick cannot instantiate (MagickWand mismatch or broken install).';
        }

        if ($name === 'libvips') {
            if (!class_exists(VipsImage::class)) {
                return 'php-vips binding missing (composer require jcupitt/vips).';
            }
            if (!extension_loaded('ffi')) {
                return 'FFI extension not loaded.';
            }
            $ffiEnable = strtolower((string) ini_get('ffi.enable'));
            if (!in_array($ffiEnable, ['1', 'true', 'on', 'yes'], true)) {
                return 'FFI disabled (ffi.enable=' . ($ffiEnable !== '' ? $ffiEnable : 'off') . ').';
            }
            if (LibvipsCliBridge::shouldIsolate() && !LibvipsCliBridge::isCliAvailable()) {
                return 'Under FPM isolation, neither the vips binary nor a PHP CLI worker responded.';
            }

            return 'Native libvips library did not load (libvips.so / dylib missing or unreadable).';
        }

        if ($name === 'gd') {
            if (!extension_loaded('gd')) {
                return 'PHP gd extension not loaded for this SAPI (' . PHP_SAPI . ').';
            }

            return 'GD loaded but imagecreatetruecolor() is missing.';
        }

        return 'Not installed or not usable in this SAPI.';
    }

    /**
     * @return list<array{id: string, group: string, status: 'pass'|'warn'|'fail', label: string, detail: string, solution: ?string}>
     */
    private function libvipsRuntimeChecks(?string $selectedDriver, string $preference): array
    {
        $libvipsInPlay = $preference === 'libvips' || $selectedDriver === 'libvips';
        $hasVipsBinary = LibvipsCliBridge::resolveVipsBinary() !== null;

        $ffiLoaded = extension_loaded('ffi');
        $ffiEnable = strtolower((string) ini_get('ffi.enable'));
        $ffiOn = $ffiLoaded && in_array($ffiEnable, ['1', 'true', 'on', 'yes'], true);

        $isolate = LibvipsCliBridge::shouldIsolate();
        $cliOk = $isolate ? LibvipsCliBridge::isCliAvailable() : true;
        $isolationOk = !$isolate || $cliOk;

        // FPM ffi.enable can be off while isolation still works (vips CLI, or PHP
        // worker started with -d ffi.enable=true). Only fail when Libvips is in
        // play and isolation itself cannot run.
        $ffiStatus = 'pass';
        if (!$ffiOn) {
            $ffiStatus = ($libvipsInPlay && !$isolationOk) ? 'fail' : 'warn';
        }

        $checks = [];
        $checks[] = $this->check(
            'ffi',
            self::GROUP_DRIVERS,
            $ffiStatus,
            'FFI (libvips)',
            $ffiLoaded
                ? 'extension loaded · ffi.enable=' . ($ffiEnable !== '' ? $ffiEnable : 'off')
                    . ($hasVipsBinary && !$ffiOn ? ' · vips CLI available' : '')
                : 'FFI extension not loaded.'
                    . ($hasVipsBinary ? ' · vips CLI available' : ''),
            $ffiOn
                ? 'Required for in-process php-vips (CLI SAPI). Keep zend.max_allowed_stack_size=-1 on PHP 8.3+.'
                : ($isolationOk && $isolate
                    ? 'FPM ffi.enable is off, but isolation can still run via vips CLI / PHP worker. Enable ffi.enable=true for in-process use.'
                    : 'Set ffi.enable=true in the FPM php.ini (install php8.x-ffi if needed), then restart PHP-FPM. See docs/drivers.md.'),
        );

        $checks[] = $this->check(
            'libvips-isolation',
            self::GROUP_DRIVERS,
            $isolationOk ? 'pass' : ($libvipsInPlay ? 'fail' : 'warn'),
            'Libvips FPM isolation',
            $isolate
                ? ($cliOk
                    ? 'Active for SAPI ' . PHP_SAPI . ' (vips CLI and/or PHP worker OK).'
                    : 'Needed for SAPI ' . PHP_SAPI . ' but neither vips binary nor PHP CLI worker responded.')
                : 'Not required for SAPI ' . PHP_SAPI . ' (in-process libvips OK).',
            !$isolationOk
                ? 'Install libvips-tools (`sudo apt-get install -y libvips-tools`), or set SUPER_IMAGES_VIPS_BINARY / SUPER_IMAGES_PHP_BINARY. See docs/drivers.md.'
                : ($isolate
                    ? 'Isolation avoids FFI SIGSEGV under FPM. Override with SUPER_IMAGES_VIPS_ISOLATE=0 only for debugging.'
                    : null),
        );

        return $checks;
    }

    /**
     * Build a normalized doctor check result row.
     *
     * @param string $id Stable check identifier.
     * @param string $group Doctor group constant (GROUP_*).
     * @param string $status Check status: pass, warn, or fail.
     * @param string $label Short human-readable label.
     * @param string $detail Descriptive detail text.
     * @param string|null $solution Optional remediation guidance.
     *
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

    /**
     * Ensure a directory exists and is writable by the PHP process.
     *
     * Creates the directory with mode 0755 when missing.
     *
     * @param string $path Absolute filesystem path to check.
     *
     * @return bool True when the directory exists and is writable.
     */
    private function ensureWritableDirectory(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
            return false;
        }

        return is_writable($path);
    }
}
