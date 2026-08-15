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
use Craft;

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
     * @param array<string, mixed> $options Tool selection, binary path, quality, arguments, and other settings.
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
            Craft::warning(sprintf(
                'Optimizer %s exited %d: %s',
                $tool,
                $result['exitCode'],
                trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']),
            ), 'super-images');

            return $encoded;
        }

        $finalPath = $this->resolveOptimizedPath($tool, $input, $output, $result, $options);

        if ($finalPath === null || !is_readable($finalPath) || filesize($finalPath) === 0) {
            Craft::warning(sprintf(
                'Optimizer %s produced no readable output (stdout=%d bytes).',
                $tool,
                strlen($result['stdout']),
            ), 'super-images');

            return $encoded;
        }

        return $encoded->withPath($finalPath, (int) filesize($finalPath), true);
    }

    /**
     * Pick the filesystem path that holds optimized bytes after a successful run.
     *
     * @param string $tool Optimizer tool slug.
     * @param string $input Input path passed to the tool.
     * @param string $output Intended output path (may be unused for in-place tools).
     * @param array{exitCode: int, stdout: string, stderr: string} $result Process result.
     * @param array<string, mixed> $options Original optimize options (detects `--stdout`).
     *
     * @return string|null Absolute path, or null when optimization did not produce a file.
     */
    private function resolveOptimizedPath(
        string $tool,
        string $input,
        string $output,
        array $result,
        array $options = [],
    ): ?string {
        if ($tool === 'jpegoptim') {
            // Default recipe is in-place (`-s`). jpegoptim still prints a human status
            // line on stdout — that must NOT be written as the image (was ~100 bytes of text).
            $usesStdout = $this->commandUsesStdout($options);

            if ($usesStdout) {
                if ($result['stdout'] === '' || !$this->looksLikeJpeg($result['stdout'])) {
                    return null;
                }
                file_put_contents($output, $result['stdout']);

                return is_readable($output) && filesize($output) > 0 ? $output : null;
            }

            return is_readable($input) && filesize($input) > 0 ? $input : null;
        }

        if (is_readable($output) && filesize($output) > 0) {
            return $output;
        }

        return null;
    }

    /**
     * Whether custom arguments request JPEG bytes on stdout.
     *
     * @param array<string, mixed> $options Optimize options.
     *
     * @return bool True when `--stdout` is present in custom arguments.
     */
    private function commandUsesStdout(array $options): bool
    {
        $custom = Plugin::getInstance()->getOptimizerManager()->normalizeArguments(
            $options['arguments'] ?? $options['args'] ?? null,
        );

        return in_array('--stdout', $custom, true) || in_array('-stdout', $custom, true);
    }

    /**
     * Cheap magic-byte check so we never persist jpegoptim's text status as an image.
     *
     * @param string $bytes Candidate payload.
     *
     * @return bool True when payload starts with a JPEG SOI marker.
     */
    private function looksLikeJpeg(string $bytes): bool
    {
        return str_starts_with($bytes, "\xFF\xD8\xFF");
    }

    /**
     * Builds the CLI argument list for the requested optimizer tool.
     *
     * When `arguments` / `args` is set in options, that list replaces the built-in recipe
     * (after the binary). Tokens: `{input}`, `{output}`, `{quality}`, `{effort}`, `{method}`.
     *
     * @param string $tool Optimizer tool slug.
     * @param string $binary Resolved absolute path to the executable.
     * @param string $format Target format slug.
     * @param string $input Absolute path to the input file.
     * @param string $output Absolute path to the output file.
     * @param array<string, mixed> $options Tool-specific options such as quality and arguments.
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
        $custom = Plugin::getInstance()->getOptimizerManager()->normalizeArguments(
            $options['arguments'] ?? $options['args'] ?? null,
        );

        if ($custom !== []) {
            return array_merge([$binary], $this->expandArgumentTokens($custom, $input, $output, $options));
        }

        return match ($tool) {
            // In-place strip/optimize (same approach as Imager). Avoid --stdout so we
            // never depend on capturing binary JPEG data from process pipes.
            'jpegoptim' => array_values(array_filter([
                $binary,
                '-s',
                '--strip-all',
                isset($options['quality']) ? '--max=' . (int) $options['quality'] : null,
                $input,
            ], static fn(mixed $v): bool => $v !== null && $v !== '')),
            'oxipng' => [$binary, '-o', '2', '--out', $output, $input],
            'optipng' => [$binary, '-out', $output, $input],
            'pngquant' => [$binary, '--force', '--output', $output, $input],
            'cwebp' => [
                $binary,
                '-q',
                (string) ($options['quality'] ?? 80),
                '-m',
                (string) ($options['effort'] ?? $options['method'] ?? 4),
                '-sharp_yuv',
                $input,
                '-o',
                $output,
            ],
            'avifenc' => [$binary, $input, $output],
            default => throw new OptimizerUnavailableException(sprintf('Unknown optimizer tool "%s".', $tool)),
        };
    }

    /**
     * Replaces `{input}`, `{output}`, `{quality}`, `{effort}`, and `{method}` tokens.
     *
     * @param list<string> $arguments Argument list from config.
     * @param string $input Absolute input path.
     * @param string $output Absolute output path.
     * @param array<string, mixed> $options Quality / effort options.
     *
     * @return list<string>
     */
    private function expandArgumentTokens(array $arguments, string $input, string $output, array $options): array
    {
        $quality = (string) ($options['quality'] ?? 80);
        $effort = (string) ($options['effort'] ?? $options['method'] ?? 4);
        $replacements = [
            '{input}' => $input,
            '{output}' => $output,
            '{quality}' => $quality,
            '{effort}' => $effort,
            '{method}' => $effort,
        ];

        $expanded = [];
        foreach ($arguments as $argument) {
            $expanded[] = strtr($argument, $replacements);
        }

        return $expanded;
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
