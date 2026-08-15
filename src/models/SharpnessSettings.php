<?php
/**
 * Resolved downscale sharpness settings for image drivers.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\models;

/**
 * Sharpness Settings
 *
 * Controls Imagick/libvips/GD resize blur and optional post-downscale unsharp.
 * Configure via `policies.geometry.sharpness` as a preset string or options array.
 */
final class SharpnessSettings
{
    /**
     * Named presets from softest to sharpest.
     *
     * @var array<string, array{blur: float, unsharp: array{radius: float, sigma: float, amount: float, threshold: float}|null}>
     */
    private const PRESETS = [
        'soft' => [
            'blur' => 1.0,
            'unsharp' => null,
        ],
        'normal' => [
            'blur' => 0.92,
            'unsharp' => [
                'radius' => 0.0,
                'sigma' => 0.5,
                'amount' => 0.5,
                'threshold' => 0.02,
            ],
        ],
        'sharp' => [
            'blur' => 0.82,
            'unsharp' => [
                'radius' => 0.0,
                'sigma' => 0.6,
                'amount' => 0.85,
                'threshold' => 0.02,
            ],
        ],
        'extra' => [
            'blur' => 0.72,
            'unsharp' => [
                'radius' => 0.0,
                'sigma' => 0.75,
                'amount' => 1.1,
                'threshold' => 0.025,
            ],
        ],
    ];

    /**
     * @param string $preset Resolved preset name (soft|normal|sharp|extra).
     * @param float $blur Imagick resize blur factor (&lt; 1 sharpens, &gt; 1 softens).
     * @param array{radius: float, sigma: float, amount: float, threshold: float}|null $unsharp
     *     Unsharp-mask params after downscale, or null to skip.
     */
    public function __construct(
        public readonly string $preset,
        public readonly float $blur,
        public readonly ?array $unsharp,
    ) {
    }

    /**
     * Build sharpness settings from config (`soft` / `sharp` / array overrides).
     *
     * @param mixed $value Preset string, options array, or null for default `sharp`.
     *
     * @return self Resolved settings.
     */
    public static function fromConfig(mixed $value): self
    {
        $preset = 'sharp';
        $overrides = [];

        if (is_string($value) && $value !== '') {
            $preset = strtolower($value);
        } elseif (is_array($value)) {
            if (isset($value['preset']) && is_string($value['preset']) && $value['preset'] !== '') {
                $preset = strtolower($value['preset']);
            }
            $overrides = $value;
        }

        if (!isset(self::PRESETS[$preset])) {
            $preset = 'sharp';
        }

        $base = self::PRESETS[$preset];
        $blur = isset($overrides['blur']) ? (float) $overrides['blur'] : $base['blur'];
        $blur = max(0.1, min(2.0, $blur));

        $unsharp = $base['unsharp'];
        if (array_key_exists('unsharp', $overrides)) {
            if ($overrides['unsharp'] === false || $overrides['unsharp'] === null) {
                $unsharp = null;
            } elseif (is_array($overrides['unsharp'])) {
                $defaults = $base['unsharp'] ?? self::PRESETS['sharp']['unsharp'];
                $unsharp = [
                    'radius' => (float) ($overrides['unsharp']['radius'] ?? $defaults['radius'] ?? 0.0),
                    'sigma' => (float) ($overrides['unsharp']['sigma'] ?? $defaults['sigma'] ?? 0.6),
                    'amount' => (float) ($overrides['unsharp']['amount'] ?? $defaults['amount'] ?? 0.85),
                    'threshold' => (float) ($overrides['unsharp']['threshold'] ?? $defaults['threshold'] ?? 0.02),
                ];
            }
        }

        return new self($preset, $blur, $unsharp);
    }

    /**
     * Stable array for generation identity hashing.
     *
     * @return array<string, mixed>
     */
    public function toIdentityArray(): array
    {
        return [
            'preset' => $this->preset,
            'blur' => $this->blur,
            'unsharp' => $this->unsharp,
        ];
    }
}
