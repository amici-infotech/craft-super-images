<?php

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\SourceException;
use Craft;

final class PathGuard
{
    /** @var list<string> */
    private array $_allowedRoots;

    /**
     * @param list<string> $allowedRoots
     */
    public function __construct(array $allowedRoots)
    {
        $this->_allowedRoots = array_values(array_filter(array_map(
            static fn(string $root) => self::canonicalize(Craft::getAlias($root, false) ?: $root),
            $allowedRoots,
        )));
    }

    public function resolve(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            throw new SourceException('Local path cannot be empty.');
        }

        if (str_starts_with($path, '/')) {
            $webroot = Craft::getAlias('@webroot', false);
            if ($webroot && !str_starts_with($path, $webroot)) {
                $path = rtrim($webroot, '/') . $path;
            }
        } else {
            $path = Craft::getAlias($path, false) ?: $path;
        }

        $canonical = self::canonicalize($path);

        if (!is_file($canonical)) {
            throw new SourceException('Local path does not exist or is not a file.');
        }

        foreach ($this->_allowedRoots as $root) {
            if ($root !== '' && str_starts_with($canonical, $root . DIRECTORY_SEPARATOR)) {
                return $canonical;
            }

            if ($root !== '' && $canonical === $root) {
                return $canonical;
            }
        }

        throw new SourceException('Local path is outside allowed roots.');
    }

    public static function canonicalize(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            return $real;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        $parts = [];

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        $prefix = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';

        return $prefix . implode(DIRECTORY_SEPARATOR, $parts);
    }
}
