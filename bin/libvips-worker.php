<?php
/**
 * CLI worker for libvips jobs spawned from PHP-FPM (process isolation).
 *
 * Reads one JSON job from stdin and writes one JSON result to stdout.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

declare(strict_types=1);

use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\models\SharpnessSettings;
use amici\SuperImages\models\SourceImage;
use amici\SuperImages\models\SourceKind;
use amici\SuperImages\operations\color\Brightness;
use amici\SuperImages\operations\color\Contrast;
use amici\SuperImages\operations\color\Grayscale;
use amici\SuperImages\operations\color\Invert;
use amici\SuperImages\operations\color\Saturation;
use amici\SuperImages\operations\color\Sepia;
use amici\SuperImages\operations\effects\Blur;
use amici\SuperImages\operations\effects\Sharpen;
use amici\SuperImages\operations\geometry\Crop;
use amici\SuperImages\operations\geometry\Fill;
use amici\SuperImages\operations\geometry\Fit;
use amici\SuperImages\operations\geometry\Flip;
use amici\SuperImages\operations\geometry\Resize;
use amici\SuperImages\operations\geometry\Rotate;
use amici\SuperImages\operations\geometry\Scale;
use Jcupitt\Vips\Image;

$autoloadCandidates = [
    dirname(__DIR__, 3) . '/vendor/autoload.php', // Craft project root (path repo)
    dirname(__DIR__) . '/vendor/autoload.php',    // add-on local vendor
];
$autoload = null;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Could not locate Composer autoload.php for libvips worker.\n");
    exit(1);
}
require $autoload;

putenv('VIPS_CONCURRENCY=' . (getenv('VIPS_CONCURRENCY') ?: '1'));
putenv('SUPER_IMAGES_VIPS_ISOLATE=0');

/**
 * @param array<string, mixed> $payload
 */
function respond(array $payload): void
{
    echo json_encode($payload, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function readJob(): array
{
    $raw = stream_get_contents(STDIN);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Empty worker job.');
    }

    $job = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($job)) {
        throw new RuntimeException('Worker job must be a JSON object.');
    }

    return $job;
}

/**
 * @param array<string, mixed> $job
 */
function applyDriverSettings(LibvipsDriver $driver, array $job): void
{
    if (array_key_exists('allowUpscale', $job)) {
        $driver->setAllowUpscale((bool) $job['allowUpscale']);
    }

    if (isset($job['sharpness']) && is_array($job['sharpness'])) {
        $driver->setSharpness(SharpnessSettings::fromConfig($job['sharpness']));
    }
}

/**
 * @return array{0: ImageHandle, 1: string}
 */
function loadHandleFromPath(string $path, string $mime): array
{
    $image = Image::newFromFile($path, ['access' => 'sequential']);

    return [
        new ImageHandle(
            'libvips',
            $image,
            (int) $image->width,
            (int) $image->height,
            ((int) $image->bands) === 4,
            $mime,
        ),
        $path,
    ];
}

function persistImage(Image $image, string $outPath): void
{
    $dir = dirname($outPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create temp directory for libvips worker.');
    }

    $image->writeToFile($outPath);
}

try {
    $job = readJob();
    $action = (string) ($job['action'] ?? '');

    if ($action === 'ping') {
        $version = \Jcupitt\Vips\Config::version();
        respond(['ok' => true, 'version' => $version]);
        exit(0);
    }

    $driver = new LibvipsDriver();
    applyDriverSettings($driver, $job);

    switch ($action) {
        case 'load':
            $sourcePath = (string) ($job['sourcePath'] ?? '');
            $outPath = (string) ($job['outPath'] ?? '');
            $mime = (string) ($job['mime'] ?? 'image/jpeg');
            if ($sourcePath === '' || $outPath === '') {
                throw new RuntimeException('load requires sourcePath and outPath.');
            }

            if (!empty($job['sourceBytesBase64'])) {
                $bytes = base64_decode((string) $job['sourceBytesBase64'], true) ?: null;
                $source = new SourceImage(
                    kind: SourceKind::LocalPath,
                    identity: 'bytes:' . md5((string) $bytes),
                    path: null,
                    bytes: $bytes,
                    mime: $mime,
                );
            } else {
                $source = new SourceImage(
                    kind: SourceKind::LocalPath,
                    identity: 'path:' . $sourcePath,
                    path: $sourcePath,
                    bytes: null,
                    mime: $mime,
                );
            }

            $handle = $driver->load($source);
            /** @var Image $image */
            $image = $handle->resource;
            persistImage($image, $outPath);

            respond([
                'ok' => true,
                'path' => $outPath,
                'width' => $handle->width,
                'height' => $handle->height,
                'hasAlpha' => $handle->hasAlpha,
                'mime' => $handle->mime,
            ]);
            break;

        case 'op':
            $method = (string) ($job['method'] ?? '');
            $inPath = (string) ($job['inPath'] ?? '');
            $outPath = (string) ($job['outPath'] ?? '');
            $mime = (string) ($job['mime'] ?? 'image/jpeg');
            $args = is_array($job['args'] ?? null) ? $job['args'] : [];

            if ($method === '' || $inPath === '' || $outPath === '') {
                throw new RuntimeException('op requires method, inPath, and outPath.');
            }

            if (!method_exists($driver, $method)) {
                throw new RuntimeException(sprintf('Unknown libvips method "%s".', $method));
            }

            [$handle] = loadHandleFromPath($inPath, $mime);
            $result = $driver->{$method}($handle, ...$args);
            if (!$result instanceof ImageHandle) {
                throw new RuntimeException('Operation did not return an ImageHandle.');
            }

            /** @var Image $image */
            $image = $result->resource;
            persistImage($image, $outPath);

            respond([
                'ok' => true,
                'path' => $outPath,
                'width' => $result->width,
                'height' => $result->height,
                'hasAlpha' => $result->hasAlpha,
                'mime' => $result->mime,
            ]);
            break;

        case 'encode':
            $inPath = (string) ($job['inPath'] ?? '');
            $format = (string) ($job['format'] ?? 'jpeg');
            $mime = (string) ($job['mime'] ?? 'image/jpeg');
            $options = is_array($job['options'] ?? null) ? $job['options'] : [];

            if ($inPath === '') {
                throw new RuntimeException('encode requires inPath.');
            }

            [$handle] = loadHandleFromPath($inPath, $mime);
            $encoded = $driver->encodeNative(
                $handle,
                $format,
                new EncodeOptions(
                    quality: isset($options['quality']) ? (int) $options['quality'] : null,
                    stripMetadata: (bool) ($options['stripMetadata'] ?? true),
                    extra: is_array($options['extra'] ?? null) ? $options['extra'] : [],
                ),
            );

            respond([
                'ok' => true,
                'format' => $encoded->format,
                'width' => $encoded->width,
                'height' => $encoded->height,
                'bytes' => base64_encode($encoded->bytes),
                'mime' => $encoded->mime,
                'size' => $encoded->size,
            ]);
            break;

        case 'pipeline':
            $sourcePath = (string) ($job['sourcePath'] ?? '');
            $mime = (string) ($job['mime'] ?? 'image/jpeg');
            $format = (string) ($job['format'] ?? 'jpeg');
            $encodedOutPath = (string) ($job['encodedOutPath'] ?? '');
            $options = is_array($job['options'] ?? null) ? $job['options'] : [];
            $rawOps = is_array($job['operations'] ?? null) ? $job['operations'] : [];
            $maxSourcePixels = (int) ($job['maxSourcePixels'] ?? 0);

            if ($encodedOutPath === '') {
                throw new RuntimeException('pipeline requires encodedOutPath.');
            }

            if (!empty($job['sourceBytesBase64'])) {
                $bytes = base64_decode((string) $job['sourceBytesBase64'], true) ?: null;
                $source = new SourceImage(
                    kind: SourceKind::LocalPath,
                    identity: 'bytes:' . md5((string) $bytes),
                    path: null,
                    bytes: $bytes,
                    mime: $mime,
                );
            } else {
                if ($sourcePath === '') {
                    throw new RuntimeException('pipeline requires sourcePath or sourceBytesBase64.');
                }
                $source = new SourceImage(
                    kind: SourceKind::LocalPath,
                    identity: 'path:' . $sourcePath,
                    path: $sourcePath,
                    bytes: null,
                    mime: $mime,
                );
            }

            $handle = $driver->load($source);

            if ($maxSourcePixels > 0) {
                $sourcePixels = $handle->width * $handle->height;
                if ($sourcePixels > $maxSourcePixels) {
                    $scale = sqrt($maxSourcePixels / $sourcePixels);
                    $handle = $driver->fit(
                        $handle,
                        max(1, (int) floor($handle->width * $scale)),
                        max(1, (int) floor($handle->height * $scale)),
                    );
                }
            }

            $definitions = [];
            foreach ($rawOps as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $definitions[] = OperationDefinition::fromArray($raw);
            }

            if ($definitions !== []) {
                $map = [
                    'resize' => Resize::class,
                    'crop' => Crop::class,
                    'fit' => Fit::class,
                    'fill' => Fill::class,
                    'scale' => Scale::class,
                    'rotate' => Rotate::class,
                    'flip' => Flip::class,
                    'brightness' => Brightness::class,
                    'contrast' => Contrast::class,
                    'saturation' => Saturation::class,
                    'grayscale' => Grayscale::class,
                    'sepia' => Sepia::class,
                    'invert' => Invert::class,
                    'sharpen' => Sharpen::class,
                    'blur' => Blur::class,
                ];

                foreach ($definitions as $definition) {
                    $class = $map[strtolower($definition->type)] ?? null;
                    if ($class === null) {
                        throw new RuntimeException(sprintf(
                            'Unsupported isolated operation "%s".',
                            $definition->type,
                        ));
                    }

                    $operation = new $class($definition->options);
                    $handle = $operation->apply($handle, $driver);
                }
            }

            $encoded = $driver->encodeNative(
                $handle,
                $format,
                new EncodeOptions(
                    quality: isset($options['quality']) ? (int) $options['quality'] : null,
                    stripMetadata: (bool) ($options['stripMetadata'] ?? true),
                    extra: is_array($options['extra'] ?? null) ? $options['extra'] : [],
                ),
            );

            $dir = dirname($encodedOutPath);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create encoded output directory.');
            }

            if (file_put_contents($encodedOutPath, (string) $encoded->bytes) === false) {
                throw new RuntimeException('Failed to write encoded output.');
            }

            respond([
                'ok' => true,
                'path' => $encodedOutPath,
                'format' => $encoded->format,
                'width' => $encoded->width,
                'height' => $encoded->height,
                'mime' => $encoded->mime,
                'size' => $encoded->size,
            ]);
            break;

        default:
            throw new RuntimeException(sprintf('Unknown worker action "%s".', $action));
    }
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}
