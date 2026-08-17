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

final class ExampleWebpEncoder implements EncoderInterface
{
    public function name(): string
    {
        return 'example-cwebp';
    }

    public function formats(): array
    {
        return ['webp'];
    }

    public function supports(string $format): bool
    {
        return strtolower($format) === 'webp';
    }

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
