<?php
/**
 * Twig variable registered as `craft.superImages`.
 *
 * Phase 1 test helpers — eagerly generate derivatives via GenerationService.
 * Phase 2 will replace render-time generation with deterministic/signed URLs.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\variables;

use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\GenerationResult;
use amici\SuperImages\Plugin;
use Craft;
use craft\elements\Asset;
use craft\helpers\Html;
use Twig\Markup;

/**
 * Super Images Variable
 */
class SuperImagesVariable
{
    /**
     * Generate a derivative and return the full result object.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL
     * @param array<string, mixed> $options profile, variant, format, …
     */
    public function generate(Asset|string|int $source, array $options = []): GenerationResult
    {
        $request = $this->buildRequest($source, $options);

        return Plugin::getInstance()->getGeneration()->generate($request);
    }

    /**
     * Soft generate for demo templates — returns null on failure instead of throwing.
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function tryGenerate(Asset|string|int $source, array $options = []): ?GenerationResult
    {
        try {
            return $this->generate($source, $options);
        } catch (SuperImagesException $exception) {
            Craft::warning($exception->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * Generate (if needed) and return the public URL.
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function url(Asset|string|int $source, array $options = []): string
    {
        return $this->generate($source, $options)->url;
    }

    /**
     * Generate an `<img>` tag for one derivative.
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function img(Asset|string|int $source, array $options = []): Markup
    {
        try {
            $result = $this->generate($source, $options);
        } catch (SuperImagesException $exception) {
            $message = Html::encode($exception->getMessage());

            return new Markup('<div class="error">Skipped: ' . $message . '</div>', 'UTF-8');
        }

        $attrs = [
            'src' => $result->url,
            'width' => $result->width,
            'height' => $result->height,
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'decoding' => $options['decoding'] ?? 'async',
        ];

        if (!empty($options['class'])) {
            $attrs['class'] = $options['class'];
        }

        if (!empty($options['sizes'])) {
            $attrs['sizes'] = $options['sizes'];
        }

        return new Markup(Html::tag('img', '', $attrs), 'UTF-8');
    }

    /**
     * Generate a responsive `<picture>` with profile formats (or explicit formats).
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function picture(Asset|string|int $source, array $options = []): Markup
    {
        $plugin = Plugin::getInstance();
        $profileName = (string) ($options['profile'] ?? $plugin->getSettings()->defaultProfile);
        $variant = (string) ($options['variant'] ?? 'md');
        $profile = $plugin->getSettings()->profiles[$profileName] ?? [];
        $formats = $options['formats'] ?? ($profile['formats'] ?? ['webp', 'jpg']);

        if (!is_array($formats) || $formats === []) {
            $formats = ['webp', 'jpg'];
        }

        // Prefer modern formats first for <source>, keep last as <img> fallback.
        $ordered = $this->orderFormats($formats);
        $fallback = array_pop($ordered) ?? 'jpg';

        $html = ['<picture>'];
        $sizes = (string) ($options['sizes'] ?? '100vw');

        foreach ($ordered as $format) {
            $result = $this->generate($source, array_merge($options, [
                'profile' => $profileName,
                'variant' => $variant,
                'format' => $format,
            ]));

            $html[] = Html::tag('source', '', [
                'type' => $result->mime,
                'srcset' => $result->url,
                'sizes' => $sizes,
            ]);
        }

        $imgResult = $this->generate($source, array_merge($options, [
            'profile' => $profileName,
            'variant' => $variant,
            'format' => $fallback,
        ]));

        $imgAttrs = [
            'src' => $imgResult->url,
            'width' => $imgResult->width,
            'height' => $imgResult->height,
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'decoding' => $options['decoding'] ?? 'async',
            'sizes' => $sizes,
        ];

        if (!empty($options['class'])) {
            $imgAttrs['class'] = $options['class'];
        }

        $html[] = Html::tag('img', '', $imgAttrs);
        $html[] = '</picture>';

        return new Markup(implode("\n", $html), 'UTF-8');
    }

    /**
     * Build srcset for multiple variants of one format.
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function srcset(Asset|string|int $source, array $options = []): string
    {
        $plugin = Plugin::getInstance();
        $profileName = (string) ($options['profile'] ?? $plugin->getSettings()->defaultProfile);
        $format = (string) ($options['format'] ?? $plugin->getSettings()->defaultFormat);
        $profile = $plugin->getSettings()->profiles[$profileName] ?? [];
        $variants = $options['variants'] ?? array_keys($profile['variants'] ?? []);

        if (!is_array($variants) || $variants === []) {
            $variants = ['md'];
        }

        $parts = [];

        foreach ($variants as $variant) {
            $result = $this->generate($source, [
                'profile' => $profileName,
                'variant' => (string) $variant,
                'format' => $format,
            ]);
            $parts[] = $result->url . ' ' . $result->width . 'w';
        }

        return implode(', ', $parts);
    }

    /**
     * Whether the selected driver can encode a format.
     */
    public function supportsFormat(string $format): bool
    {
        $driver = Plugin::getInstance()->getDriverManager()->select();
        $format = strtolower($format) === 'jpg' ? 'jpeg' : strtolower($format);

        return in_array($format, $driver->capabilities()->formats, true)
            || in_array(strtolower($format) === 'jpeg' ? 'jpg' : $format, $driver->capabilities()->formats, true);
    }

    /**
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    private function buildRequest(Asset|string|int $source, array $options): GenerationRequest
    {
        $assetId = null;
        $localPath = null;
        $remoteUrl = null;

        if ($source instanceof Asset) {
            $assetId = (int) $source->id;
        } elseif (is_int($source) || (is_string($source) && ctype_digit($source))) {
            $assetId = (int) $source;
        } elseif (is_string($source) && (str_starts_with($source, 'http://') || str_starts_with($source, 'https://'))) {
            $remoteUrl = $source;
        } else {
            $localPath = (string) $source;
        }

        return new GenerationRequest(
            assetId: $assetId,
            localPath: $localPath,
            remoteUrl: $remoteUrl,
            profile: isset($options['profile']) ? (string) $options['profile'] : null,
            variant: isset($options['variant']) ? (string) $options['variant'] : null,
            format: isset($options['format']) ? (string) $options['format'] : null,
            storageAdapter: isset($options['storage']) ? (string) $options['storage'] : null,
        );
    }

    private function defaultAlt(Asset|string|int $source): string
    {
        if ($source instanceof Asset) {
            return (string) ($source->title ?: $source->filename);
        }

        if (is_string($source)) {
            return basename(parse_url($source, PHP_URL_PATH) ?: $source);
        }

        return '';
    }

    /**
     * @param list<string> $formats
     * @return list<string>
     */
    private function orderFormats(array $formats): array
    {
        $priority = ['avif' => 0, 'webp' => 1, 'jpg' => 2, 'jpeg' => 2, 'png' => 3];
        $normalized = array_values(array_unique(array_map(
            static fn(string $f) => strtolower($f) === 'jpeg' ? 'jpg' : strtolower($f),
            $formats,
        )));

        usort($normalized, static function (string $a, string $b) use ($priority): int {
            return ($priority[$a] ?? 50) <=> ($priority[$b] ?? 50);
        });

        return $normalized;
    }
}
