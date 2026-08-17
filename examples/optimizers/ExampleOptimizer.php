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

final class ExampleOptimizer implements OptimizerInterface
{
    public function name(): string
    {
        return 'example-jpeg';
    }

    public function supports(string $format): bool
    {
        return in_array(strtolower($format), ['jpeg', 'jpg'], true);
    }

    public function optimize(EncodedImage $encoded, string $format, array $options = []): EncodedImage
    {
        $plugin = Plugin::getInstance();
        $binary = $plugin->getBinaryResolver()->resolve('jpegoptim', $options['binary'] ?? null);

        if ($binary === null) {
            return $encoded;
        }

        $temp = $plugin->getTemporaryFiles();
        $input = $encoded->hasPath() ? $encoded->path : $temp->write('opt-in-', (string) ($encoded->bytes ?? ''), 'jpg');
        $output = $temp->create('opt-out-', 'jpg');

        $quality = (int) ($options['quality'] ?? 82);
        $result = $plugin->getProcessRunner()->run([
            $binary,
            '--strip-all',
            '--max=' . $quality,
            '--stdout',
            $input,
        ]);

        if ($result['exitCode'] !== 0) {
            Craft::warning('example-jpeg optimizer failed: ' . trim($result['stderr'] ?: $result['stdout']), __METHOD__);

            return $encoded;
        }

        $bytes = $result['stdout'];
        if ($bytes === '') {
            return $encoded;
        }

        return $encoded->withBytes($bytes, strlen($bytes));
    }
}
