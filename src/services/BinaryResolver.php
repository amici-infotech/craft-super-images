<?php

namespace amici\SuperImages\services;

use amici\SuperImages\Plugin;
use amici\SuperImages\support\ProcessRunner;
use yii\base\Component;

/**
 * Resolves configured optimizer/encoder binary paths across environments.
 *
 * Prefer absolute paths (or env-driven paths) so Ubuntu hosts can pin
 * `/usr/bin/...` binaries without relying on PATH alone.
 */
final class BinaryResolver extends Component
{
    /**
     * Resolve an executable for a named tool (jpegoptim, cwebp, …).
     *
     * Lookup order:
     * 1. Explicit absolute/relative path passed by the caller
     * 2. optimizers.binaries[tool] from config
     * 3. Tool name on PATH
     */
    public function resolve(string $tool, ?string $explicitPath = null): ?string
    {
        $tool = strtolower(trim($tool));
        if ($tool === '') {
            return null;
        }

        $runner = $this->processRunner();

        if (is_string($explicitPath) && $explicitPath !== '') {
            return $runner->resolveExecutable($explicitPath);
        }

        $configured = $this->configuredPath($tool);
        if ($configured !== null) {
            return $runner->resolveExecutable($configured);
        }

        return $runner->resolveExecutable($tool);
    }

    public function isAvailable(string $tool, ?string $explicitPath = null): bool
    {
        return $this->resolve($tool, $explicitPath) !== null;
    }

    /**
     * @return array<string, array{tool: string, configured: ?string, resolved: ?string, available: bool}>
     */
    public function inventory(): array
    {
        $tools = array_unique(array_merge(
            array_keys($this->binariesConfig()),
            ['jpegoptim', 'oxipng', 'optipng', 'pngquant', 'cwebp', 'avifenc'],
        ));

        $out = [];
        foreach ($tools as $tool) {
            $configured = $this->configuredPath($tool);
            $resolved = $this->resolve($tool);
            $out[$tool] = [
                'tool' => $tool,
                'configured' => $configured,
                'resolved' => $resolved,
                'available' => $resolved !== null,
            ];
        }

        ksort($out);

        return $out;
    }

    private function configuredPath(string $tool): ?string
    {
        $binaries = $this->binariesConfig();
        $value = $binaries[$tool] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function binariesConfig(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $binaries = $settings->optimizers['binaries'] ?? [];

        if (!is_array($binaries)) {
            return [];
        }

        $normalized = [];
        foreach ($binaries as $name => $path) {
            if (!is_string($name) || !is_string($path) || $path === '') {
                continue;
            }
            $normalized[strtolower($name)] = $path;
        }

        return $normalized;
    }

    private function processRunner(): ProcessRunner
    {
        return Plugin::getInstance()->getProcessRunner();
    }
}
