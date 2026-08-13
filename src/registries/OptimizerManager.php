<?php
/**
 * Selects optional binary optimizers or a null passthrough.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\OptimizerInterface;
use amici\SuperImages\optimizers\BinaryOptimizer;
use amici\SuperImages\optimizers\NullOptimizer;
use yii\base\Component;

/**
 * Optimizer Manager
 */
class OptimizerManager extends Component
{
    private ?NullOptimizer $_nullOptimizer = null;
    private ?BinaryOptimizer $_binaryOptimizer = null;

    /** @var array<string, OptimizerInterface> */
    private array $_optimizers = [];

    public function registerDefaults(): void
    {
        $this->register($this->nullOptimizer());
        $this->register($this->binaryOptimizer());
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
        $tool = $config[$format] ?? null;

        if ($tool === null || $tool === '') {
            return $this->nullOptimizer();
        }

        $binary = $this->binaryOptimizer();
        if ($binary->supports($format) && $binary->canOptimize((string) $tool)) {
            return $binary;
        }

        return $this->nullOptimizer();
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
