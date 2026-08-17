<?php
/**
 * Reference optimizer that wraps an external CLI via ProcessRunner.
 *
 * Register with RegisterOptimizersEvent, then in config:
 *   'optimizers' => ['jpeg' => 'example-jpeg'],
 */

namespace myagency\superimages\examples\optimizers;

use amici\SuperImages\contracts\OptimizerInterface;
use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\Plugin;
use Craft;

/**
 * Example JPEG Optimizer
 *
 * Wraps jpegoptim the same way BinaryOptimizer does internally. Optimizers run
 * after encode and may be deferred to the queue when `optimizeType = 'job'`.
 */
final class ExampleOptimizer implements OptimizerInterface
{
    /**
     * Returns the optimizer identifier — must match the tool string in config.
     *
     * @return string
     */
    public function name(): string
    {
        return 'example-jpeg';
    }

    /**
     * Whether this optimizer supports the given output format.
     *
     * @param string $format Target format slug.
     *
     * @return bool
     */
    public function supports(string $format): bool
    {
        return in_array(strtolower($format), ['jpeg', 'jpg'], true);
    }

    /**
     * Runs jpegoptim against encoded JPEG bytes when the binary is available.
     *
     * Failures are non-fatal: the original EncodedImage is returned so generation
     * still succeeds without optimization.
     *
     * @param EncodedImage $encoded Output from the encoder stage.
     * @param string $format Target format slug (jpeg/jpg).
     * @param array<string, mixed> $options `binary`, `quality`, and optional CLI `arguments`.
     *
     * @return EncodedImage Optimized bytes or the original on skip/failure.
     */
    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage
    {
        $plugin = Plugin::getInstance();
        $binary = $plugin->getBinaryResolver()->resolve('jpegoptim', $options['binary'] ?? null);

        if ($binary === null) {
            return $encoded;
        }

        $temp = $plugin->getTemporaryFiles();
        $input = $encoded->hasPath()
            ? $encoded->path
            : $temp->write('opt-in-', (string) ($encoded->bytes ?? ''), 'jpg');

        $quality = (int) ($options['quality'] ?? 82);
        $result = $plugin->getProcessRunner()->run([
            $binary,
            '--strip-all',
            '--max=' . $quality,
            '--stdout',
            $input,
        ]);

        if ($result['exitCode'] !== 0) {
            Craft::warning(
                'example-jpeg optimizer failed: ' . trim($result['stderr'] ?: $result['stdout']),
                __METHOD__,
            );

            return $encoded;
        }

        $bytes = $result['stdout'];
        if ($bytes === '') {
            return $encoded;
        }

        return $encoded->withBytes($bytes, strlen($bytes));
    }
}
