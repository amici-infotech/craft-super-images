<?php
/**
 * Tracks and cleans up temporary files created during generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\support;

use Craft;
use yii\base\Component;

/**
 * Temporary File Manager
 *
 * Creates, tracks, and deletes temp files under Craft's temp path. Used for
 * asset copies, remote downloads, and encoded output staging during generation.
 */
final class TemporaryFileManager extends Component
{
    /**
     * Tracked temp file paths scheduled for cleanup.
     *
     * @var list<string>
     */
    private array $_files = [];

    /**
     * Create a new empty temp file path and track it for cleanup.
     *
     * @param string $prefix Filename prefix passed to uniqid().
     * @param string|null $extension Optional file extension without leading dot.
     *
     * @return string Absolute path to the new temp file (file is not created until written).
     */
    public function create(string $prefix = 'super-images-', ?string $extension = null): string
    {
        $extension = $extension !== null && $extension !== '' ? '.' . ltrim($extension, '.') : '';
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid($prefix, true) . $extension;

        $this->_files[] = $path;

        return $path;
    }

    /**
     * Create a temp file, write contents to it, and track it for cleanup.
     *
     * @param string $prefix Filename prefix passed to uniqid().
     * @param string $contents Raw file contents to write.
     * @param string|null $extension Optional file extension without leading dot.
     *
     * @return string Absolute path to the written temp file.
     */
    public function write(string $prefix, string $contents, ?string $extension = null): string
    {
        $path = $this->create($prefix, $extension);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Track an externally created file path for later cleanup.
     *
     * @param string $path Absolute path to a temp file (e.g. asset copy from Craft).
     *
     * @return void
     */
    public function track(string $path): void
    {
        if (!in_array($path, $this->_files, true)) {
            $this->_files[] = $path;
        }
    }

    /**
     * Delete all tracked temp files and clear the tracking list.
     *
     * @return void
     */
    public function cleanup(): void
    {
        foreach ($this->_files as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->_files = [];
    }

    /**
     * Ensure tracked temp files are removed when the component is destroyed.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
