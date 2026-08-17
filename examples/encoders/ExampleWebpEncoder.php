<?php
/**
 * Reference encoder: lossless PNG intermediate → cwebp CLI → WebP bytes.
 *
 * Register with RegisterEncodersEvent. Because encoders are keyed by format,
 * this replaces the built-in native encoder for WebP while it is registered.
 *
 * Most sites should keep the native encoder and set optimizers.webp = 'cwebp' instead.
 * Use a custom encoder when you need full control over the encode step itself.
 */

namespace myagency\superimages\examples\encoders;

use amici\SuperImages\contracts\EncoderInterface;
use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\models\EncodeOptions;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\Plugin;
use Craft;

/**
 * Example WebP Encoder
 *
 * Demonstrates the PNG→cwebp pipeline Super Images uses internally when
 * `optimizers.webp = 'cwebp'`, but implemented as a standalone encoder class.
 */
final class ExampleWebpEncoder implements EncoderInterface
{
    /**
     * Returns the encoder identifier used in diagnostics.
     *
     * @return string
     */
    public function name(): string
    {
        return 'example-cwebp';
    }

    /**
     * Lists output formats this encoder registers (replaces native for these formats).
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return ['webp'];
    }

    /**
     * Whether this encoder can produce the requested format.
     *
     * @param string $format Target format slug.
     *
     * @return bool
     */
    public function supports(string $format): bool
    {
        return strtolower($format) === 'webp';
    }

    /**
     * Encodes the handle to WebP using cwebp when available, otherwise native WebP.
     *
     * Flow:
     * 1. Resolve `cwebp` binary via BinaryResolver (honours `$options->extra['binary']`).
     * 2. Encode a lossless PNG intermediate via the active driver.
     * 3. Run cwebp with quality/method from EncodeOptions.
     * 4. On failure, fall back to driver native WebP (never ship PNG as `.webp`).
     *
     * @param ImageHandle $handle Loaded image resource from the active driver.
     * @param string $format Target output format slug (must be `webp`).
     * @param EncodeOptions $options Quality, metadata stripping, and format extras.
     * @param ImageDriverInterface $driver Driver used for native encode fallback.
     *
     * @return EncodedImage WebP bytes on disk or in memory.
     */
    public function encode(
        ImageHandle $handle,
        string $format,
        EncodeOptions $options,
        ImageDriverInterface $driver,
    ): EncodedImage {
        $plugin = Plugin::getInstance();
        $binary = $plugin->getBinaryResolver()->resolve('cwebp', $options->extra['binary'] ?? null);

        if ($binary === null) {
            return $driver->encodeNative($handle, 'webp', $options);
        }

        // Lossless PNG preserves quality before lossy WebP conversion.
        $png = $driver->encodeNative(
            $handle,
            'png',
            new EncodeOptions(quality: 100, stripMetadata: $options->stripMetadata, extra: ['pngCompression' => 1]),
        );

        $temp = $plugin->getTemporaryFiles();
        $input = $png->hasPath() ? $png->path : $temp->write('enc-in-', (string) $png->bytes, 'png');
        $output = $temp->create('enc-out-', 'webp');

        $quality = $options->qualityOrDefault(80);
        $method = (int) ($options->extra['method'] ?? $options->extra['effort'] ?? 4);
        $result = $plugin->getProcessRunner()->run([
            $binary,
            '-q',
            (string) $quality,
            '-m',
            (string) $method,
            '-sharp_yuv',
            $input,
            '-o',
            $output,
        ]);

        if ($result['exitCode'] !== 0 || !is_readable($output) || filesize($output) === 0) {
            Craft::warning('example-cwebp failed; falling back to native WebP.', __METHOD__);

            return $driver->encodeNative($handle, 'webp', $options);
        }

        return new EncodedImage(
            'webp',
            $handle->width,
            $handle->height,
            (int) filesize($output),
            'image/webp',
            null,
            $output,
            true,
        );
    }
}
