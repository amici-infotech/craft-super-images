<?php
/**
 * Runs libvips work in a CLI PHP child when the current SAPI cannot safely use FFI.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\ProcessingException;

/**
 * Libvips CLI Bridge
 *
 * On some platforms (notably macOS PHP-FPM), in-process php-vips image ops SIGSEGV
 * while the same code works under CLI. This bridge serializes jobs to
 * `bin/libvips-worker.php` so generation can still use libvips from web requests.
 */
final class LibvipsCliBridge
{
    private static ?string $phpBinary = null;

    private static ?string $vipsBinary = null;

    private static ?bool $cliUsable = null;

    /**
     * Whether the current process should avoid in-process libvips image operations.
     */
    public static function shouldIsolate(): bool
    {
        if (getenv('SUPER_IMAGES_VIPS_ISOLATE') === '1') {
            return true;
        }

        if (getenv('SUPER_IMAGES_VIPS_ISOLATE') === '0') {
            return false;
        }

        return in_array(PHP_SAPI, ['fpm-fcgi', 'cgi-fcgi'], true);
    }

    /**
     * True when the native `vips` binary or a PHP CLI worker can run libvips.
     */
    public static function isCliAvailable(): bool
    {
        if (self::$cliUsable !== null) {
            return self::$cliUsable;
        }

        if (self::resolveVipsBinary() !== null) {
            return self::$cliUsable = true;
        }

        try {
            $result = self::run(['action' => 'ping']);
            self::$cliUsable = ($result['ok'] ?? false) === true;
        } catch (\Throwable) {
            self::$cliUsable = false;
        }

        return self::$cliUsable;
    }

    /**
     * Fast path: shell out to the native `vips` binary (no PHP bootstrap).
     *
     * @param array{width?: int|null, height?: int|null, crop?: bool, quality?: int|null, strip?: bool, effort?: int|null} $options
     *
     * @return array{path: string, width: int, height: int, size: int}
     */
    public static function runThumbnail(
        string $sourcePath,
        string $outputPath,
        array $options = [],
    ): array {
        $vips = self::resolveVipsBinary();
        if ($vips === null) {
            throw new ProcessingException('Native vips binary is not available.');
        }

        if ($sourcePath === '' || !is_readable($sourcePath)) {
            throw new ProcessingException('vips thumbnail source is not readable.');
        }

        $width = isset($options['width']) ? (int) $options['width'] : 0;
        $height = isset($options['height']) ? (int) $options['height'] : 0;
        if ($width <= 0 && $height <= 0) {
            throw new ProcessingException('vips thumbnail requires width and/or height.');
        }

        $size = $width > 0 && $height > 0
            ? $width . 'x' . $height
            : (string) max($width, $height);

        $quality = isset($options['quality']) ? (int) $options['quality'] : null;
        $strip = (bool) ($options['strip'] ?? true);
        $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        $export = [];
        if ($quality !== null) {
            $export[] = 'Q=' . max(1, min(100, $quality));
        }
        if ($strip) {
            $export[] = 'strip';
        }
        if ($ext === 'avif') {
            $effort = array_key_exists('effort', $options)
                ? (int) $options['effort']
                : 0;
            $export[] = 'effort=' . max(0, min(9, $effort));
        }

        $target = $outputPath;
        if ($export !== []) {
            $target .= '[' . implode(',', $export) . ']';
        }

        $command = [$vips, 'thumbnail', $sourcePath, $target, $size];
        if (!empty($options['crop'])) {
            $command[] = '--crop';
            $command[] = 'centre';
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new ProcessingException('Cannot create vips output directory.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new ProcessingException('Failed to start vips binary.');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || !is_file($outputPath)) {
            throw new ProcessingException(sprintf(
                'vips thumbnail failed (exit %d): %s',
                $exitCode,
                trim($stderr),
            ));
        }

        $info = @getimagesize($outputPath);
        $sizeBytes = filesize($outputPath);

        return [
            'path' => $outputPath,
            'width' => is_array($info) ? (int) $info[0] : 0,
            'height' => is_array($info) ? (int) $info[1] : 0,
            'size' => is_int($sizeBytes) ? $sizeBytes : 0,
        ];
    }

    public static function resolveVipsBinary(): ?string
    {
        if (self::$vipsBinary !== null) {
            return self::$vipsBinary !== '' ? self::$vipsBinary : null;
        }

        $configured = getenv('SUPER_IMAGES_VIPS_BINARY');
        $candidates = [];
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = '/opt/homebrew/bin/vips';
        $candidates[] = '/usr/local/bin/vips';
        $candidates[] = '/usr/bin/vips';

        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir !== '') {
                $candidates[] = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'vips';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
                return self::$vipsBinary = $candidate;
            }
        }

        self::$vipsBinary = '';

        return null;
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    public static function run(array $job): array
    {
        $php = self::resolvePhpBinary();
        $worker = self::workerPath();

        if (!is_file($worker)) {
            throw new ProcessingException('Libvips CLI worker script is missing.');
        }

        $payload = json_encode($job, JSON_THROW_ON_ERROR);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = [
            $php,
            '-d',
            'ffi.enable=true',
            '-d',
            'zend.max_allowed_stack_size=-1',
            $worker,
        ];

        $previousConcurrency = getenv('VIPS_CONCURRENCY');
        if ($previousConcurrency === false || $previousConcurrency === '') {
            putenv('VIPS_CONCURRENCY=1');
        }

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            throw new ProcessingException('Failed to start libvips CLI worker.');
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $decoded = self::decodeWorkerStdout($stdout);

        if (!is_array($decoded)) {
            throw new ProcessingException(sprintf(
                'Libvips CLI worker returned invalid JSON (exit %d): %s %s',
                $exitCode,
                trim($stdout),
                trim($stderr),
            ));
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw new ProcessingException((string) ($decoded['error'] ?? 'Libvips CLI worker failed.') . (
                $stderr !== '' ? ' ' . trim($stderr) : ''
            ));
        }

        return $decoded;
    }

    public static function resolvePhpBinary(): string
    {
        if (self::$phpBinary !== null) {
            return self::$phpBinary;
        }

        $configured = getenv('SUPER_IMAGES_PHP_BINARY');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return self::$phpBinary = $configured;
        }

        $candidates = [];

        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            $binary = PHP_BINARY;
            if (str_contains($binary, 'php-fpm')) {
                $sibling = dirname($binary, 2) . '/bin/php';
                $candidates[] = $sibling;
                $candidates[] = preg_replace('#/sbin/php-fpm$#', '/bin/php', $binary) ?: '';
            } elseif (basename($binary) === 'php' || str_ends_with($binary, '/php')) {
                $candidates[] = $binary;
            }
        }

        if (defined('PHP_BINDIR')) {
            $candidates[] = rtrim((string) PHP_BINDIR, '/') . '/php';
        }

        $candidates[] = '/opt/homebrew/opt/php@8.4/bin/php';
        $candidates[] = '/opt/homebrew/bin/php';
        $candidates[] = '/usr/bin/php';

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
                return self::$phpBinary = $candidate;
            }
        }

        throw new ProcessingException(
            'Could not resolve a PHP CLI binary for libvips isolation. Set SUPER_IMAGES_PHP_BINARY.',
        );
    }

    private static function workerPath(): string
    {
        // src/support → plugin root /bin/libvips-worker.php
        return dirname(__DIR__, 2) . '/bin/libvips-worker.php';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeWorkerStdout(string $stdout): ?array
    {
        $stdout = trim($stdout);
        if ($stdout === '') {
            return null;
        }

        $decoded = json_decode($stdout, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // PHP startup warnings (e.g. broken imagick.so) may precede JSON on stdout.
        $start = strrpos($stdout, '{"ok"');
        if ($start === false) {
            $start = strrpos($stdout, '{');
        }
        if ($start === false) {
            return null;
        }

        $decoded = json_decode(substr($stdout, $start), true);

        return is_array($decoded) ? $decoded : null;
    }
}
