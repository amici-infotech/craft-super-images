<?php
/**
 * Optional external binary optimizer via ProcessRunner.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\optimizers;

use amici\SuperImages\contracts\OptimizerInterface;
use amici\SuperImages\exceptions\OptimizerUnavailableException;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\Plugin;

/**
 * Binary Optimizer
 */
class BinaryOptimizer implements OptimizerInterface
{
    public function name(): string
    {
        return 'binary';
    }

    public function supports(string $format): bool
    {
        return in_array(strtolower($format), ['jpeg', 'jpg', 'png', 'webp', 'avif'], true);
    }

    public function canOptimize(string $tool): bool
    {
        return Plugin::getInstance()->getProcessRunner()->isExecutableAvailable($tool);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage
    {
        $tool = (string) ($options['tool'] ?? '');
        if ($tool === '') {
            return $encoded;
        }

        $plugin = Plugin::getInstance();
        $processRunner = $plugin->getProcessRunner();
        $temporaryFiles = $plugin->getTemporaryFiles();

        $input = $this->resolveInputPath($encoded, $temporaryFiles);
        $output = $temporaryFiles->create('optimized-', $this->extensionForFormat($format));
        $command = $this->buildCommand($tool, $format, $input, $output, $options);
        $result = $processRunner->run($command);

        if ($result['exitCode'] !== 0) {
            // Optional optimizer failure should not hard-fail the pipeline unless forced.
            return $encoded;
        }

        $finalPath = $output;
        if ($tool === 'jpegoptim' && is_readable($input)) {
            // jpegoptim typically optimizes in place.
            $finalPath = $input;
        }

        if (!is_readable($finalPath) || filesize($finalPath) === 0) {
            return $encoded;
        }

        return $encoded->withPath($finalPath, (int) filesize($finalPath), true);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function buildCommand(string $tool, string $format, string $input, string $output, array $options): array
    {
        $format = strtolower($format) === 'jpg' ? 'jpeg' : strtolower($format);

        return match ($tool) {
            'jpegoptim' => ['jpegoptim', '--stdout', '--strip-all', $input],
            'oxipng' => ['oxipng', '-o', '2', '--out', $output, $input],
            'optipng' => ['optipng', '-out', $output, $input],
            'pngquant' => ['pngquant', '--force', '--output', $output, $input],
            'cwebp' => ['cwebp', '-q', (string) ($options['quality'] ?? 80), $input, '-o', $output],
            'avifenc' => ['avifenc', $input, $output],
            default => throw new OptimizerUnavailableException(sprintf('Unknown optimizer tool "%s".', $tool)),
        };
    }

    private function resolveInputPath(EncodedImage $encoded, $temporaryFiles): string
    {
        if ($encoded->hasPath()) {
            return (string) $encoded->path;
        }

        return $temporaryFiles->write(
            'optimize-in-',
            (string) $encoded->bytes,
            $this->extensionForFormat($encoded->format),
        );
    }

    private function extensionForFormat(string $format): string
    {
        $format = strtolower($format);

        return match ($format) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            'avif' => 'avif',
            default => 'bin',
        };
    }
}
