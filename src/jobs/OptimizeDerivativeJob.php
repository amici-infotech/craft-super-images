<?php
/**
 * Queue job that post-optimizes an already-stored derivative in place.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\jobs;

use amici\SuperImages\models\EncodedImage;
use amici\SuperImages\models\StorageWriteOptions;
use amici\SuperImages\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Optimize Derivative Job
 *
 * Runs jpegoptim/optipng/etc. on a file that was written without post-optimization
 * so Twig / generate-before-page-load can return early (optimizeType=job).
 */
class OptimizeDerivativeJob extends BaseJob
{
    /** Storage adapter handle that holds the derivative. */
    public string $storageAdapter = 'local';

    /** Relative storage path of the derivative to optimize. */
    public string $storagePath = '';

    /** Output format slug (jpg, png, …). */
    public string $format = 'jpg';

    /** MIME type used when rewriting the object. */
    public string $mime = 'image/jpeg';

    /** Optimizer tool slug (jpegoptim, optipng, …). */
    public string $tool = '';

    /** Optional absolute binary path override. */
    public ?string $binary = null;

    /** Optional quality hint for tools that accept it. */
    public ?int $quality = null;

    /**
     * Optional custom CLI arguments (tokenized).
     *
     * @var list<string>
     */
    public array $arguments = [];

    /**
     * Downloads/opens the stored file, runs the optimizer, and overwrites the same path.
     *
     * @param mixed $queue Queue instance.
     *
     * @return void
     */
    public function execute(mixed $queue): void
    {
        if ($this->storagePath === '' || $this->tool === '') {
            Craft::warning('OptimizeDerivativeJob skipped: missing storagePath or tool.', 'super-images');

            return;
        }

        $plugin = Plugin::getInstance();
        $adapter = $plugin->getStorageManager()->select($this->storageAdapter);

        if (!$adapter->exists($this->storagePath)) {
            Craft::warning(sprintf(
                'OptimizeDerivativeJob: missing storage object %s/%s',
                $this->storageAdapter,
                $this->storagePath,
            ), 'super-images');

            return;
        }

        try {
            $bytes = $adapter->read($this->storagePath);
            $before = strlen($bytes);
            if ($before <= 0) {
                return;
            }

            $resolvedBinary = $plugin->getBinaryResolver()->resolve($this->tool, $this->binary);
            if ($resolvedBinary === null) {
                Craft::warning(sprintf(
                    'OptimizeDerivativeJob: binary for %s not found.',
                    $this->tool,
                ), 'super-images');

                return;
            }

            $encoded = new EncodedImage(
                format: $this->format,
                width: 1,
                height: 1,
                size: $before,
                mime: $this->mime,
                bytes: $bytes,
            );

            $optimizer = $plugin->getOptimizerManager()->get($this->tool)
                ?? $plugin->getOptimizerManager()->binaryOptimizer();
            $options = [
                'tool' => $this->tool,
                'binary' => $resolvedBinary,
            ];
            if ($this->quality !== null) {
                $options['quality'] = $this->quality;
            }
            if ($this->arguments !== []) {
                $options['arguments'] = $this->arguments;
            }

            $optimized = $optimizer->optimize($encoded, $this->format, $options);

            if (!$optimized->hasPath() && !$optimized->hasBytes()) {
                Craft::warning(sprintf(
                    'OptimizeDerivativeJob: %s produced no payload for %s',
                    $this->tool,
                    $this->storagePath,
                ), 'super-images');
                $this->setProgress($queue, 1);

                return;
            }

            // Same object reference means BinaryOptimizer bailed out unchanged.
            if ($optimized === $encoded) {
                Craft::warning(sprintf(
                    'OptimizeDerivativeJob: %s left %s unchanged (%d bytes).',
                    $this->tool,
                    $this->storagePath,
                    $before,
                ), 'super-images');
                $this->setProgress($queue, 1);

                return;
            }

            $writeOptions = new StorageWriteOptions(
                contentType: $this->mime,
                public: true,
            );

            if ($optimized->hasPath()) {
                $path = (string) $optimized->path;
                $after = is_file($path) ? (int) filesize($path) : 0;
                // Guard: never overwrite storage with a tiny non-image (e.g. jpegoptim status text).
                if ($after < 256 || $after < (int) ($before * 0.05)) {
                    Craft::warning(sprintf(
                        'OptimizeDerivativeJob: refusing to write %s (%d bytes after %d).',
                        $this->storagePath,
                        $after,
                        $before,
                    ), 'super-images');
                    $this->setProgress($queue, 1);

                    return;
                }
                $adapter->writeFile($this->storagePath, $path, $writeOptions);
            } else {
                $payload = (string) $optimized->bytes;
                $after = strlen($payload);
                if ($after < 256 || $after < (int) ($before * 0.05)) {
                    Craft::warning(sprintf(
                        'OptimizeDerivativeJob: refusing to write %s (%d bytes after %d).',
                        $this->storagePath,
                        $after,
                        $before,
                    ), 'super-images');
                    $this->setProgress($queue, 1);

                    return;
                }
                $adapter->write($this->storagePath, $payload, $writeOptions);
            }

            Craft::info(sprintf(
                'OptimizeDerivativeJob: %s %s %d → %d bytes (%.2f%%).',
                $this->tool,
                $this->storagePath,
                $before,
                $after,
                $before > 0 ? (100 * (1 - ($after / $before))) : 0.0,
            ), 'super-images');

            $this->setProgress($queue, 1);
        } catch (\Throwable $exception) {
            Craft::warning(sprintf(
                'OptimizeDerivativeJob failed for %s/%s: %s',
                $this->storageAdapter,
                $this->storagePath,
                $exception->getMessage(),
            ), 'super-images');
        } finally {
            $plugin->getTemporaryFiles()->cleanup();
        }
    }

    /**
     * @return string|null
     */
    protected function defaultDescription(): ?string
    {
        return sprintf('Super Images: optimize %s', $this->storagePath !== '' ? $this->storagePath : 'derivative');
    }
}
