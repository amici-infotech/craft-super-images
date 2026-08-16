<?php
/**
 * Selects optional binary optimizers or a null passthrough.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\OptimizerInterface;
use amici\SuperImages\events\RegisterOptimizersEvent;
use amici\SuperImages\optimizers\BinaryOptimizer;
use amici\SuperImages\optimizers\NullOptimizer;
use yii\base\Component;

/**
 * Optimizer Manager
 *
 * Selects post-encode optimizers per format from config, falling back to a
 * null passthrough when optimizers are disabled or binaries are unavailable.
 */
class OptimizerManager extends Component
{
    /**
     * Event fired after built-in optimizers are registered so plugins can add custom implementations.
     */
    public const EVENT_REGISTER_OPTIMIZERS = 'registerOptimizers';

    /** Cached NullOptimizer singleton instance. */
    private ?NullOptimizer $_nullOptimizer = null;

    /** Cached BinaryOptimizer singleton instance. */
    private ?BinaryOptimizer $_binaryOptimizer = null;

    /**
     * Registered optimizer implementations keyed by name().
     *
     * @var array<string, OptimizerInterface>
     */
    private array $_optimizers = [];

    /**
     * Register built-in optimizers and trigger the register event for extensions.
     *
     * @return void
     */
    public function registerDefaults(): void
    {
        $this->register($this->nullOptimizer());
        $this->register($this->binaryOptimizer());

        $event = new RegisterOptimizersEvent();
        $this->trigger(self::EVENT_REGISTER_OPTIMIZERS, $event);

        foreach ($event->optimizers as $optimizer) {
            $this->register($optimizer);
        }
    }

    /**
     * Register an optimizer implementation.
     *
     * @param OptimizerInterface $optimizer The optimizer instance to register.
     *
     * @return void
     */
    public function register(OptimizerInterface $optimizer): void
    {
        $this->_optimizers[strtolower($optimizer->name())] = $optimizer;
    }

    /**
     * Select the optimizer for a given output format.
     *
     * Resolution order:
     * 1. Registered optimizer whose `name()` matches the configured tool
     * 2. Built-in BinaryOptimizer when the CLI tool is available
     * 3. NullOptimizer passthrough
     *
     * @param string $format Output format (jpg/jpeg normalized internally).
     * @param array<string, mixed> $config Optimizer config map keyed by format.
     * @param bool $enabled When false, always returns the null optimizer.
     *
     * @return OptimizerInterface The selected optimizer or NullOptimizer passthrough.
     */
    public function select(string $format, array $config, bool $enabled = true): OptimizerInterface
    {
        if (!$enabled) {
            return $this->nullOptimizer();
        }

        $format = strtolower($format);
        $format = $format === 'jpg' ? 'jpeg' : $format;
        [$tool, $binaryPath] = $this->normalizeToolConfig($config[$format] ?? null);

        if ($tool === null || $tool === '') {
            return $this->nullOptimizer();
        }

        $registered = $this->get($tool);
        if ($registered !== null && $registered->name() !== 'binary' && $registered->name() !== 'null') {
            if ($registered->supports($format)) {
                return $registered;
            }
        }

        $binary = $this->binaryOptimizer();
        if ($binary->supports($format) && $binary->canOptimize($tool, $binaryPath)) {
            return $binary;
        }

        return $this->nullOptimizer();
    }

    /**
     * Return a registered optimizer by name, if present.
     *
     * @param string $name Optimizer `name()` (e.g. `jpegoptim`, `binary`, custom handle).
     *
     * @return OptimizerInterface|null
     */
    public function get(string $name): ?OptimizerInterface
    {
        return $this->_optimizers[strtolower($name)] ?? null;
    }

    /**
     * All registered optimizers.
     *
     * @return list<OptimizerInterface>
     */
    public function all(): array
    {
        return array_values($this->_optimizers);
    }

    /**
     * Normalize optimizer tool config from string or array form.
     *
     * @param mixed $value Config value: tool name string, {tool, binary, arguments} array, false, or null.
     *
     * @return array{0: ?string, 1: ?string, 2: list<string>} Tuple of [tool, binary path, CLI arguments].
     */
    public function normalizeToolConfig(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [null, null, []];
        }

        if (is_string($value)) {
            return [strtolower($value), null, []];
        }

        if (!is_array($value)) {
            return [null, null, []];
        }

        $tool = isset($value['tool']) && is_string($value['tool']) && $value['tool'] !== ''
            ? strtolower($value['tool'])
            : null;
        $binary = isset($value['binary']) && is_string($value['binary']) && $value['binary'] !== ''
            ? $value['binary']
            : null;

        return [$tool, $binary, $this->normalizeArguments($value['arguments'] ?? $value['args'] ?? null)];
    }

    /**
     * Normalize configured CLI arguments to a list of strings.
     *
     * Accepts:
     * - whitespace-separated string
     * - list of tokens: `['--stdout', '--max=85', '{input}']`
     * - key/value map: `['--stdout' => true, '--max' => 85, '-o' => '{output}', '{input}']`
     *   - `true` / `''` → flag only (`--stdout`)
     *   - `false` / `null` → skip
     *   - other scalar → `flag` + value as two argv tokens (`--max`, `85`)
     *   - key ending in `=` → single token (`--max=` + `85` → `--max=85`)
     *   - integer keys → positional token (value only)
     *   - `'_'` / `'positional'` / `'positionals'` => list of trailing positionals
     *
     * @param mixed $value List of args, key/value map, whitespace-separated string, or null.
     *
     * @return list<string>
     */
    public function normalizeArguments(mixed $value): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/\s+/', trim($value)) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            $args = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $args[] = (string) $item;
                }
            }

            return $args;
        }

        $args = [];
        foreach ($value as $key => $item) {
            if (is_int($key)) {
                if (is_string($item) || is_int($item) || is_float($item)) {
                    $args[] = (string) $item;
                }
                continue;
            }

            $key = (string) $key;
            if ($key === '_' || $key === 'positional' || $key === 'positionals') {
                foreach ((array) $item as $positional) {
                    if (is_string($positional) || is_int($positional) || is_float($positional)) {
                        $args[] = (string) $positional;
                    }
                }
                continue;
            }

            if ($item === false || $item === null) {
                continue;
            }

            if ($item === true || $item === '') {
                $args[] = $key;
                continue;
            }

            if (is_array($item)) {
                foreach ($item as $nested) {
                    if ($nested === false || $nested === null) {
                        continue;
                    }
                    if ($nested === true || $nested === '') {
                        $args[] = $key;
                        continue;
                    }
                    if (is_string($nested) || is_int($nested) || is_float($nested)) {
                        if (str_ends_with($key, '=')) {
                            $args[] = $key . (string) $nested;
                        } else {
                            $args[] = $key;
                            $args[] = (string) $nested;
                        }
                    }
                }
                continue;
            }

            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                continue;
            }

            if (str_ends_with($key, '=')) {
                $args[] = $key . (string) $item;
            } else {
                $args[] = $key;
                $args[] = (string) $item;
            }
        }

        return $args;
    }

    /**
     * Whether the tool is a format converter (must run during encode), not a post-optimizer.
     *
     * @param string|null $tool Tool slug.
     *
     * @return bool True for cwebp / avifenc.
     */
    public function isExternalEncoder(?string $tool): bool
    {
        return in_array(strtolower((string) $tool), ['cwebp', 'avifenc'], true);
    }

    /**
     * Whether post-optimizers should run via Craft queue after the file is stored.
     *
     * @param array<string, mixed> $optimizerConfig Full `optimizers` settings block.
     *
     * @return bool True when optimizeType is `job`.
     */
    public function shouldDeferPostOptimize(array $optimizerConfig): bool
    {
        $type = strtolower((string) ($optimizerConfig['optimizeType'] ?? 'job'));

        return $type === 'job';
    }

    /**
     * Return the shared null optimizer passthrough instance.
     *
     * @return NullOptimizer The no-op optimizer.
     */
    private function nullOptimizer(): NullOptimizer
    {
        return $this->_nullOptimizer ??= new NullOptimizer();
    }

    /**
     * Return the shared binary optimizer instance.
     *
     * @return BinaryOptimizer The external-binary optimizer.
     */
    public function binaryOptimizer(): BinaryOptimizer
    {
        return $this->_binaryOptimizer ??= new BinaryOptimizer();
    }
}
