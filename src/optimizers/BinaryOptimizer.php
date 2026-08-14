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
 *
 * Invokes configured CLI tools (jpegoptim, oxipng, cwebp, etc.) to shrink encoded output.
 * Failures are non-fatal unless the caller opts into strict handling elsewhere.
 */
class BinaryOptimizer implements OptimizerInterface
{
    /**
     * Returns the optimizer identifier used in configuration and logging.
     *
     * @return string Always "binary".
     */
    public function name(): string
    {
        return 'binary';
    }

    /**
     * Checks whether the optimizer supports the requested output format.
     *
     * @param string $format Target format slug (case-insensitive).
     *
     * @return bool True for jpeg, png, webp, and avif variants.
     */
    public function supports(string $format): bool
    {
        return in_array(strtolower($format), ['jpeg', 'jpg', 'png', 'webp', 'avif'], true);
    }

    /**
     * Checks whether a specific optimizer binary is available on the system.
     *
     * @param string $tool Optimizer tool slug (e.g. "jpegoptim", "cwebp").
     * @param string|null $binaryPath Optional explicit path to the binary executable.
     *
     * @return bool True when the binary resolver locates a usable executable.
     */
    public function canOptimize(string $tool, ?string $binaryPath = null): bool
    {
        return Plugin::getInstance()->getBinaryResolver()->isAvailable($tool, $binaryPath);
    }

    /**
     * Runs an external optimizer tool against the encoded image when configured.
     *
     * @param EncodedImage $encoded The image produced by the encoder stage.
     * @param string $format Target format slug used to pick file extensions and tool behavior.
     * @param array<string, mixed> $options Tool selection, binary path, quality, and other tool-specific settings.
     *
     * @return EncodedImage The optimized image, or the original when optimization is skipped or fails.
     */
    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage
    {
        $tool = strtolower((string) ($options['tool'] ?? ''));
        if ($tool === '') {
            return $encoded;
        }

        $plugin = Plugin::getInstance();
        $binaryPath = isset($options['binary']) && is_string($options['binary']) && $options['binary'] !== ''
            ? $options['binary']
            : null;
        $binary = $plugin->getBinaryResolver()->resolve($tool, $binaryPath);

        if ($binary === null) {
            return $encoded;
        }

        $processRunner = $plugin->getProcessRunner();
        $temporaryFiles = $plugin->getTemporaryFiles();

        $input = $this->resolveInputPath($encoded, $temporaryFiles);
        $output = $temporaryFiles->create('optimized-', $this->extensionForFormat($format));
        $command = $this->buildCommand($tool, $binary, $format, $input, $output, $options);
        $result = $processRunner->run($command);

        if ($result['exitCode'] !== 0) {
            // Optional optimizer failure should not hard-fail the pipeline unless forced.
            return $encoded;
        }

        $finalPath = $output;

        if ($tool === 'jpegoptim') {
            // --stdout emits optimized bytes on stdout; persist them to the temp output path.
            if ($result['stdout'] !== '') {
                file_put_contents($output, $result['stdout']);
                $finalPath = $output;
            } elseif (is_readable($input)) {
                $finalPath = $input;
            }
        }

        if (!is_readable($finalPath) || filesize($finalPath) === 0) {
            return $encoded;
        }

        return $encoded->withPath($finalPath, (int) filesize($finalPath), true);
    }

    /**
     * Builds the CLI argument list for the requested optimizer tool.
     *
     * @param string $tool Optimizer tool slug.
     * @param string $binary Resolved absolute path to the executable.
     * @param string $format Target format slug.
     * @param string $input Absolute path to the input file.
     * @param string $output Absolute path to the output file.
     * @param array<string, mixed> $options Tool-specific options such as quality.
     *
     * @return list<string> Command and arguments suitable for ProcessRunner.
     *
     * @throws OptimizerUnavailableException When the tool slug is not recognized.
     */
    private function buildCommand(
        string $tool,
        string $binary,
        string $format,
        string $input,
        string $output,
        array $options,
    ): array {
        $format = strtolower($format) === 'jpg' ? 'jpeg' : strtolower($format);

        return match ($tool) {
            'jpegoptim' => [$binary, '--stdout', '--strip-all', $input],
            'oxipng' => [$binary, '-o', '2', '--out', $output, $input],
            'optipng' => [$binary, '-out', $output, $input],
            'pngquant' => [$binary, '--force', '--output', $output, $input],
            'cwebp' => [$binary, '-q', (string) ($options['quality'] ?? 80), $input, '-o', $output],
            'avifenc' => [$binary, $input, $output],
            default => throw new OptimizerUnavailableException(sprintf('Unknown optimizer tool "%s".', $tool)),
        };
    }

    /**
     * Resolves a filesystem path for optimizer input, writing bytes to a temp file when needed.
     *
     * @param EncodedImage $encoded The encoded image, which may hold bytes or a path.
     * @param object $temporaryFiles Temporary file manager from the plugin container.
     *
     * @return string Absolute path readable by external optimizer binaries.
     */
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

    /**
     * Maps a format slug to a conventional file extension for temp files.
     *
     * @param string $format Target format slug.
     *
     * @return string File extension without a leading dot.
     */
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
