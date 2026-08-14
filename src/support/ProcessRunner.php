<?php
/**
 * Safe external process runner for optimizer binaries.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\ProcessingException;
use yii\base\Component;

/**
 * Process Runner
 *
 * Executes external optimizer binaries using argument arrays only (never shell strings).
 * Provides executable resolution and availability checks for BinaryResolver.
 */
class ProcessRunner extends Component
{
    /**
     * Run an external command and capture stdout, stderr, and exit code.
     *
     * @param list<string> $command Argument vector passed to proc_open (argv[0] is the binary).
     * @param int|null $timeoutSeconds Maximum runtime in seconds; null disables timeout.
     *
     * @return array{exitCode: int, stdout: string, stderr: string} Process result payload.
     *
     * @throws ProcessingException When the command is empty, proc_open fails, or the process times out.
     */
    public function run(array $command, ?int $timeoutSeconds = 60): array
    {
        if ($command === []) {
            throw new ProcessingException('Process command cannot be empty.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            throw new ProcessingException('Unable to start external process.');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = microtime(true);

        while (true) {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';

            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            if ($timeoutSeconds !== null && (microtime(true) - $start) > $timeoutSeconds) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new ProcessingException('External process timed out.');
            }

            usleep(10_000);
        }

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * Check whether a binary path or PATH lookup name is executable.
     *
     * @param string $binary Absolute path or bare executable name.
     *
     * @return bool True when the binary exists and is executable.
     */
    public function isExecutableAvailable(string $binary): bool
    {
        if ($binary === '' || str_contains($binary, '/')) {
            return is_executable($binary);
        }

        $path = getenv('PATH') ?: '';

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_executable($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a binary to an absolute executable path.
     *
     * @param string $binary Absolute path or bare executable name to search on PATH.
     *
     * @return string|null The resolved absolute path, or null when not found.
     */
    public function resolveExecutable(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, '/') && is_executable($binary)) {
            return $binary;
        }

        $path = getenv('PATH') ?: '';

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
