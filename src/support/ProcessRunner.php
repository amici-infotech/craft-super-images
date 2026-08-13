<?php

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\ProcessingException;
use yii\base\Component;

/**
 * Safe external process runner (argument arrays only — never shell strings).
 */
class ProcessRunner extends Component
{
    /**
     * @param list<string> $command
     * @return array{exitCode: int, stdout: string, stderr: string}
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
