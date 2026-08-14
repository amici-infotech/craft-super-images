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
        $this->_optimizers[$optimizer->name()] = $optimizer;
    }

    /**
     * Select the optimizer for a given output format.
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

        $binary = $this->binaryOptimizer();
        if ($binary->supports($format) && $binary->canOptimize($tool, $binaryPath)) {
            return $binary;
        }

        return $this->nullOptimizer();
    }

    /**
     * Normalize optimizer tool config from string or array form.
     *
     * @param mixed $value Config value: tool name string, {tool, binary} array, false, or null.
     *
     * @return array{0: ?string, 1: ?string} Tuple of [tool name, optional binary path].
     */
    public function normalizeToolConfig(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [null, null];
        }

        if (is_string($value)) {
            return [strtolower($value), null];
        }

        if (!is_array($value)) {
            return [null, null];
        }

        $tool = isset($value['tool']) && is_string($value['tool']) && $value['tool'] !== ''
            ? strtolower($value['tool'])
            : null;
        $binary = isset($value['binary']) && is_string($value['binary']) && $value['binary'] !== ''
            ? $value['binary']
            : null;

        return [$tool, $binary];
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
    private function binaryOptimizer(): BinaryOptimizer
    {
        return $this->_binaryOptimizer ??= new BinaryOptimizer();
    }
}
