<?php
/**
 * Twig variable registered as `craft.superImages`.
 *
 * Render-time helpers plan delivery URLs only — no image processing.
 * Use generate()/tryGenerate() for explicit eager generation.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\variables;

use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\GenerationResult;
use amici\SuperImages\models\PlannedDelivery;
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
     * Plan and return a delivery URL (lazy signed or eager storage URL).
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function url(Asset|string|int $source, array $options = []): string
    {
        return $this->plan($source, $options)->deliveryUrl;
    }

    /**
     * Plan and return an `<img>` tag for one derivative.
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function img(Asset|string|int $source, array $options = []): Markup
    {
        try {
            $planned = $this->plan($source, $options);
        } catch (SuperImagesException $exception) {
            $message = Html::encode($exception->getMessage());

            return new Markup('<div class="error">Skipped: ' . $message . '</div>', 'UTF-8');
        }

        $attrs = [
            'src' => $planned->deliveryUrl,
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'decoding' => $options['decoding'] ?? 'async',
        ];

        if ($planned->widthHint !== null) {
            $attrs['width'] = $planned->widthHint;
        }

        if ($planned->heightHint !== null) {
            $attrs['height'] = $planned->heightHint;
        }

        if (!empty($options['class'])) {
            $attrs['class'] = $options['class'];
        }

        if (!empty($options['sizes'])) {
            $attrs['sizes'] = $options['sizes'];
        }

        return new Markup(Html::tag('img', '', $attrs), 'UTF-8');
    }

    /**
     * Plan a responsive `<picture>` with profile formats (or explicit formats).
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

        $ordered = $this->orderFormats($formats);
        $fallback = array_pop($ordered) ?? 'jpg';

        $html = ['<picture>'];
        $sizes = (string) ($options['sizes'] ?? '100vw');

        foreach ($ordered as $format) {
            $planned = $this->plan($source, array_merge($options, [
                'profile' => $profileName,
                'variant' => $variant,
                'format' => $format,
            ]));

            $html[] = Html::tag('source', '', [
                'type' => $this->mimeFromFormat($planned->format),
                'srcset' => $planned->deliveryUrl,
                'sizes' => $sizes,
            ]);
        }

        $imgPlanned = $this->plan($source, array_merge($options, [
            'profile' => $profileName,
            'variant' => $variant,
            'format' => $fallback,
        ]));

        $imgAttrs = [
            'src' => $imgPlanned->deliveryUrl,
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'decoding' => $options['decoding'] ?? 'async',
            'sizes' => $sizes,
        ];

        if ($imgPlanned->widthHint !== null) {
            $imgAttrs['width'] = $imgPlanned->widthHint;
        }

        if ($imgPlanned->heightHint !== null) {
            $imgAttrs['height'] = $imgPlanned->heightHint;
        }

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
            $planned = $this->plan($source, [
                'profile' => $profileName,
                'variant' => (string) $variant,
                'format' => $format,
            ]);

            $descriptor = $planned->widthHint !== null
                ? $planned->widthHint . 'w'
                : $planned->variant . 'w';

            $parts[] = $planned->deliveryUrl . ' ' . $descriptor;
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
    private function plan(Asset|string|int $source, array $options = []): PlannedDelivery
    {
        return Plugin::getInstance()->getDeliveryUrls()->plan(
            $this->buildRequest($source, $options),
        );
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

    private function mimeFromFormat(string $format): string
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
