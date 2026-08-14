<?php
/**
 * Operational doctor checks and dashboard summaries.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\Plugin;
use Craft;
use yii\base\Component;

/**
 * Diagnostics Service
 */
class DiagnosticsService extends Component
{
    /**
     * Run doctor checks.
     *
     * @return list<array{id: string, status: 'pass'|'warn'|'fail', label: string, detail: string}>
     */
    public function runDoctor(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $checks = [];

        $checks[] = $this->check(
            'enabled',
            $settings->enabled ? 'pass' : 'fail',
            'Plugin enabled',
            $settings->enabled
                ? 'Super Images is enabled.'
                : 'Super Images is disabled in config.',
        );

        foreach (['gd', 'imagick', 'libvips'] as $driverName) {
            $driver = null;
            foreach ($plugin->getDriverManager()->all() as $candidate) {
                if ($candidate->name() === $driverName) {
                    $driver = $candidate;
                    break;
                }
            }

            $available = $driver !== null && $driver->isAvailable();
            $checks[] = $this->check(
                'driver-' . $driverName,
                $available ? 'pass' : 'warn',
                sprintf('%s driver', ucfirst($driverName)),
                $available
                    ? sprintf('%s is available.', ucfirst($driverName))
                    : sprintf('%s is not available on this host.', ucfirst($driverName)),
            );
        }

        try {
            $selected = $plugin->getDriverManager()->select($settings->driver);
            $formats = $selected->capabilities()->formats;
            $checks[] = $this->check(
                'formats',
                $formats !== [] ? 'pass' : 'fail',
                'Selected driver formats',
                sprintf(
                    'Driver "%s" reports: %s',
                    $selected->name(),
                    $formats !== [] ? implode(', ', $formats) : '(none)',
                ),
            );
        } catch (\Throwable $exception) {
            $checks[] = $this->check(
                'formats',
                'fail',
                'Selected driver formats',
                $exception->getMessage(),
            );
        }

        $inventory = $plugin->getBinaryResolver()->inventory();
        $availableBinaries = array_filter($inventory, static fn(array $row): bool => $row['available']);
        $missingBinaries = array_filter($inventory, static fn(array $row): bool => !$row['available']);
        $optimizersEnabled = (bool) ($settings->optimizers['enabled'] ?? true);

        if (!$optimizersEnabled) {
            $checks[] = $this->check(
                'optimizer-binaries',
                'pass',
                'Optimizer binaries',
                'Optimizers are disabled in config.',
            );
        } elseif ($availableBinaries === []) {
            $checks[] = $this->check(
                'optimizer-binaries',
                'warn',
                'Optimizer binaries',
                'No configured optimizer binaries were found on PATH.',
            );
        } else {
            $detail = sprintf(
                '%d available (%s)',
                count($availableBinaries),
                implode(', ', array_keys($availableBinaries)),
            );
            if ($missingBinaries !== []) {
                $detail .= sprintf('; missing: %s', implode(', ', array_keys($missingBinaries)));
            }
            $checks[] = $this->check(
                'optimizer-binaries',
                $missingBinaries === [] ? 'pass' : 'warn',
                'Optimizer binaries',
                $detail,
            );
        }

        $storageDefault = (string) ($settings->storage['default'] ?? 'local');
        $adapterConfig = $settings->storage['adapters'][$storageDefault] ?? null;
        if (!is_array($adapterConfig)) {
            $checks[] = $this->check(
                'storage-writable',
                'fail',
                'Local storage writable',
                sprintf('Default storage adapter "%s" is not configured.', $storageDefault),
            );
        } else {
            $type = (string) ($adapterConfig['type'] ?? 'local');
            if ($type !== 'local') {
                $checks[] = $this->check(
                    'storage-writable',
                    'pass',
                    'Local storage writable',
                    sprintf('Default adapter "%s" is remote (%s); local write check skipped.', $storageDefault, $type),
                );
            } else {
                $root = (string) Craft::getAlias((string) ($adapterConfig['path'] ?? '@webroot/uploads/super-images'));
                $writable = $this->ensureWritableDirectory($root);
                $checks[] = $this->check(
                    'storage-writable',
                    $writable ? 'pass' : 'fail',
                    'Local storage writable',
                    $writable
                        ? sprintf('Writable: %s', $root)
                        : sprintf('Not writable: %s', $root),
                );
            }
        }

        $markerConfig = $settings->storage['markers'] ?? [];
        $markersEnabled = (bool) ($markerConfig['enabled'] ?? true);
        $markerPath = (string) Craft::getAlias((string) ($markerConfig['path'] ?? '@storage/super-images/markers'));
        if (!$markersEnabled) {
            $checks[] = $this->check(
                'markers-path',
                'pass',
                'Markers path',
                'Existence markers are disabled.',
            );
        } else {
            $writable = $this->ensureWritableDirectory($markerPath);
            $checks[] = $this->check(
                'markers-path',
                $writable ? 'pass' : 'fail',
                'Markers path',
                $writable
                    ? sprintf('Writable: %s', $markerPath)
                    : sprintf('Not writable: %s', $markerPath),
            );
        }

        $tempPath = Craft::$app->getPath()->getTempPath();
        $tempWritable = is_dir($tempPath) && is_writable($tempPath);
        $checks[] = $this->check(
            'temp-writable',
            $tempWritable ? 'pass' : 'fail',
            'Temp directory writable',
            $tempWritable
                ? sprintf('Writable: %s', $tempPath)
                : sprintf('Not writable: %s', $tempPath),
        );

        $deliveryMode = (string) ($settings->delivery['mode'] ?? 'lazy');
        $runtimeEnabled = (bool) ($settings->runtime['enabled'] ?? true);
        $signingSecret = $settings->runtime['signingSecret'] ?? null;
        $hasSigning = is_string($signingSecret) && $signingSecret !== ''
            || Craft::$app->getConfig()->getGeneral()->securityKey !== '';

        if (in_array($deliveryMode, ['lazy', 'hybrid'], true)) {
            if (!$runtimeEnabled) {
                $checks[] = $this->check(
                    'runtime-signing',
                    'fail',
                    'Runtime signing',
                    sprintf('Delivery mode is "%s" but runtime generation is disabled.', $deliveryMode),
                );
            } elseif (!$hasSigning) {
                $checks[] = $this->check(
                    'runtime-signing',
                    'fail',
                    'Runtime signing',
                    'No signing secret or Craft security key is available for signed URLs.',
                );
            } else {
                $checks[] = $this->check(
                    'runtime-signing',
                    'pass',
                    'Runtime signing',
                    sprintf('Delivery mode "%s" with runtime signing available.', $deliveryMode),
                );
            }
        } else {
            $checks[] = $this->check(
                'runtime-signing',
                'pass',
                'Runtime signing',
                sprintf('Delivery mode is "%s" (eager storage URLs).', $deliveryMode),
            );
        }

        $checks[] = $this->check(
            'delivery-mode',
            in_array($deliveryMode, ['eager', 'lazy', 'hybrid'], true) ? 'pass' : 'fail',
            'Delivery mode',
            sprintf('Configured delivery mode: %s', $deliveryMode),
        );

        $queue = $this->queueCounts();
        if ($queue['available'] === false) {
            $checks[] = $this->check(
                'queue-counts',
                'warn',
                'Queue counts',
                'Queue table is not available.',
            );
        } else {
            $status = $queue['failed'] > 0 ? 'warn' : 'pass';
            $checks[] = $this->check(
                'queue-counts',
                $status,
                'Queue counts',
                sprintf('Pending: %d · Failed: %d · Reserved: %d', $queue['pending'], $queue['failed'], $queue['reserved']),
            );
        }

        return $checks;
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
        $checks = $this->runDoctor();

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
                'pass' => $pass,
                'warn' => $warn,
                'fail' => $fail,
                'checks' => $checks,
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
     * @return array{id: string, status: 'pass'|'warn'|'fail', label: string, detail: string}
     */
    private function check(string $id, string $status, string $label, string $detail): array
    {
        $normalized = match ($status) {
            'pass', 'warn', 'fail' => $status,
            default => (static function (string $value): never {
                throw new \UnhandledMatchError($value);
            })($status),
        };

        return [
            'id' => $id,
            'status' => $normalized,
            'label' => $label,
            'detail' => $detail,
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
