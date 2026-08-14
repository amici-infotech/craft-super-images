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
 */
class OptimizerManager extends Component
{
    public const EVENT_REGISTER_OPTIMIZERS = 'registerOptimizers';

    private ?NullOptimizer $_nullOptimizer = null;
    private ?BinaryOptimizer $_binaryOptimizer = null;

    /** @var array<string, OptimizerInterface> */
    private array $_optimizers = [];

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

    public function register(OptimizerInterface $optimizer): void
    {
        $this->_optimizers[$optimizer->name()] = $optimizer;
    }

    /**
     * @param array<string, mixed> $config
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
     * @return array{0: ?string, 1: ?string} [tool, binaryPath]
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

    private function nullOptimizer(): NullOptimizer
    {
        return $this->_nullOptimizer ??= new NullOptimizer();
    }

    private function binaryOptimizer(): BinaryOptimizer
    {
        return $this->_binaryOptimizer ??= new BinaryOptimizer();
    }
}
