<?php

namespace amici\SuperImages\support;

use Craft;
use yii\base\Component;

final class TemporaryFileManager extends Component
{
    /** @var list<string> */
    private array $_files = [];

    public function create(string $prefix = 'super-images-', ?string $extension = null): string
    {
        $extension = $extension !== null && $extension !== '' ? '.' . ltrim($extension, '.') : '';
        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid($prefix, true) . $extension;

        $this->_files[] = $path;

        return $path;
    }

    public function write(string $prefix, string $contents, ?string $extension = null): string
    {
        $path = $this->create($prefix, $extension);
        file_put_contents($path, $contents);

        return $path;
    }

    public function track(string $path): void
    {
        if (!in_array($path, $this->_files, true)) {
            $this->_files[] = $path;
        }
    }

    public function cleanup(): void
    {
        foreach ($this->_files as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->_files = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }
}
