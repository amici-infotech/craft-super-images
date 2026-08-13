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
 */
class ExistenceMarkerStore extends Component
{
    private ?string $_rootPath = null;
    private ?bool $_enabled = null;

    public function isEnabled(): bool
    {
        $this->boot();

        return (bool) $this->_enabled;
    }

    /**
     * @param array<string, mixed> $metadata
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

    public function exists(string $identity): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return is_file($this->markerPath($identity));
    }

    public function delete(string $identity): void
    {
        $this->boot();
        $path = $this->markerPath($identity);

        if (is_file($path)) {
            @unlink($path);
        }
    }

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
