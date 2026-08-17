<?php
/**
 * Private existence markers under Craft storage/ for remote derivative checks.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\storage;

use amici\SuperImages\Plugin;
use Craft;
use yii\base\Component;

/**
 * Existence Marker Store
 *
 * Tiny metadata files only — never image binaries, never under webroot.
 * Used to track derivative identity locally when remote storage `exists()` checks are expensive.
 */
class ExistenceMarkerStore extends Component
{
    /** @var string|null Lazily resolved absolute root path for marker files. */
    private ?string $_rootPath = null;

    /** @var bool|null Lazily resolved enabled flag from plugin settings. */
    private ?bool $_enabled = null;

    /**
     * Returns whether existence markers are enabled in plugin settings.
     *
     * @return bool True when marker writes and lookups are active.
     */
    public function isEnabled(): bool
    {
        $this->boot();

        return (bool) $this->_enabled;
    }

    /**
     * Writes a JSON marker file for the given derivative identity.
     *
     * @param string $identity Stable derivative identity hash or key.
     * @param array<string, mixed> $metadata Optional metadata stored alongside the marker.
     *
     * @return void
     */
    public function write(string $identity, array $metadata = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $path = $this->markerPath($identity);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $payload = [
            'identity' => $identity,
            'createdAt' => time(),
            'metadata' => $metadata,
        ];

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Checks whether a marker file exists for the given derivative identity.
     *
     * @param string $identity Stable derivative identity hash or key.
     *
     * @return bool True when a marker file is present and markers are enabled.
     */
    public function exists(string $identity): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return is_file($this->markerPath($identity));
    }

    /**
     * Reads a marker payload for the given identity when present.
     *
     * @param string $identity Stable derivative identity hash or key.
     *
     * @return array{identity?: string, createdAt?: int, metadata?: array<string, mixed>}|null
     */
    public function read(string $identity): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $path = $this->markerPath($identity);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            /** @var array{identity?: string, createdAt?: int, metadata?: array<string, mixed>} $payload */
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Finds a marker whose stored storage path matches (identity hash drift fallback).
     *
     * @param string $storagePath Relative storage path from {@see StoragePathBuilder}.
     *
     * @return array{identity?: string, createdAt?: int, metadata?: array<string, mixed>}|null
     */
    public function findByStoragePath(string $storagePath): ?array
    {
        if (!$this->isEnabled() || $storagePath === '') {
            return null;
        }

        $this->boot();
        $root = (string) $this->_rootPath;

        if (!is_dir($root)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $node */
        foreach ($iterator as $node) {
            if (!$node->isFile() || !str_ends_with($node->getFilename(), '.marker')) {
                continue;
            }

            $raw = file_get_contents($node->getPathname());
            if ($raw === false || $raw === '') {
                continue;
            }

            try {
                /** @var array{identity?: string, metadata?: array<string, mixed>} $payload */
                $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if ((string) ($payload['metadata']['path'] ?? '') === $storagePath) {
                return is_array($payload) ? $payload : null;
            }
        }

        return null;
    }

    /**
     * Deletes the marker file for the given derivative identity when present.
     *
     * @param string $identity Stable derivative identity hash or key.
     *
     * @return void
     */
    public function delete(string $identity): void
    {
        $this->boot();
        $path = $this->markerPath($identity);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Deletes every marker file under the configured markers root.
     *
     * Used after a full remote/local purge so cache-hit lookups do not skip
     * regeneration against a new storage adapter.
     *
     * @return int Number of marker files removed.
     */
    public function clearAll(): int
    {
        $this->boot();
        $root = (string) $this->_rootPath;

        if (!is_dir($root)) {
            return 0;
        }

        $deleted = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $node */
        foreach ($iterator as $node) {
            if ($node->isFile() && str_ends_with($node->getFilename(), '.marker')) {
                if (@unlink($node->getPathname())) {
                    $deleted++;
                }

                continue;
            }

            if ($node->isDir()) {
                @rmdir($node->getPathname());
            }
        }

        return $deleted;
    }

    /**
     * Lazily loads marker root path and enabled flag from plugin settings.
     *
     * @return void
     */
    private function boot(): void
    {
        if ($this->_rootPath !== null) {
            return;
        }

        $markerConfig = Plugin::getInstance()->getSettings()->storage['markers'] ?? [];
        $path = (string) ($markerConfig['path'] ?? '@storage/super-images/markers');
        $this->_rootPath = (string) Craft::getAlias($path);
        $this->_enabled = (bool) ($markerConfig['enabled'] ?? true);
    }

    /**
     * Builds the sharded filesystem path for a marker file from an identity string.
     *
     * @param string $identity Stable derivative identity hash or key.
     *
     * @return string Absolute path to the `.marker` file.
     */
    private function markerPath(string $identity): string
    {
        $this->boot();
        $safe = hash('sha256', $identity);

        return rtrim((string) $this->_rootPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . substr($safe, 0, 2)
            . DIRECTORY_SEPARATOR
            . $safe
            . '.marker';
    }
}
