<?php
/**
 * Allow-listed local filesystem path resolution.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\support;

use amici\SuperImages\exceptions\SourceException;
use Craft;

/**
 * Path Guard
 *
 * Resolves and canonicalizes local source paths, ensuring they remain within
 * configured allowed root directories before generation reads them.
 */
final class PathGuard
{
    /**
     * Canonical absolute allowed root directories.
     *
     * @var list<string>
     */
    private array $_allowedRoots;

    /**
     * @param list<string> $allowedRoots Craft aliases or absolute paths defining permitted roots.
     */
    public function __construct(array $allowedRoots)
    {
        $this->_allowedRoots = array_values(array_filter(array_map(
            static fn(string $root) => self::canonicalize(Craft::getAlias($root, false) ?: $root),
            $allowedRoots,
        )));
    }

    /**
     * Resolve a user-provided path to a canonical file within allowed roots.
     *
     * @param string $path Absolute path, web-relative path, or Craft alias.
     *
     * @return string Canonical absolute filesystem path to an existing file.
     *
     * @throws SourceException When the path is empty, not a file, or outside allowed roots.
     */
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

    /**
     * Canonicalize a filesystem path, resolving symlinks when possible.
     *
     * @param string $path Raw path string.
     *
     * @return string Canonical path without trailing separators.
     */
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
