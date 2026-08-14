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
