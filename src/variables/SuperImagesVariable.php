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
     * Extra HTML attributes:
     * - top-level keys that are not reserved options (e.g. `id`, `class`, `data-*`)
     * - `attrs` / `attributes` / `imgAttrs` bag (merged last for those keys)
     *
     * Managed attrs (`src`, and dimensions when known) always win.
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

        $defaults = [
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'decoding' => $options['decoding'] ?? 'async',
        ];

        if ($planned->widthHint !== null) {
            $defaults['width'] = $planned->widthHint;
        }

        if ($planned->heightHint !== null) {
            $defaults['height'] = $planned->heightHint;
        }

        if (!empty($options['sizes'])) {
            $defaults['sizes'] = $options['sizes'];
        }

        $attrs = $this->mergeHtmlAttributes($defaults, $this->extractHtmlAttributes($options), [
            'src' => $planned->deliveryUrl,
        ]);

        return new Markup(Html::tag('img', '', $attrs), 'UTF-8');
    }

    /**
     * Plan a responsive `<picture>` with multi-width srcsets per format.
     *
     * Uses profile variants (or explicit `variants`) as `w` descriptors.
     * Optional `variant` picks the fallback `<img src>` (default: middle / `md`).
     *
     * Extra HTML attributes:
     * - `pictureAttrs` / `pictureAttributes` on `<picture>`
     * - top-level non-reserved keys + `attrs` / `attributes` / `imgAttrs` on the inner `<img>`
     * - `sourceAttrs` / `sourceAttributes` merged onto every `<source>`
     *
     * @param Asset|string|int $source
     * @param array<string, mixed> $options
     */
    public function picture(Asset|string|int $source, array $options = []): Markup
    {
        $plugin = Plugin::getInstance();
        $profileName = (string) ($options['profile'] ?? $plugin->getSettings()->defaultProfile);
        $profile = $plugin->getSettings()->profiles[$profileName] ?? [];
        $formats = $options['formats'] ?? ($profile['formats'] ?? ['webp', 'jpg']);

        if (!is_array($formats) || $formats === []) {
            $formats = ['webp', 'jpg'];
        }

        $variants = $options['variants'] ?? array_keys($profile['variants'] ?? []);
        if (!is_array($variants) || $variants === []) {
            $variants = [(string) ($options['variant'] ?? 'md')];
        }
        $variants = array_values(array_map('strval', $variants));

        $ordered = $this->orderFormats($formats);
        $fallback = array_pop($ordered) ?? 'jpg';
        $fallbackVariant = $this->resolveFallbackVariant($variants, $options['variant'] ?? null);

        $sizes = (string) ($options['sizes'] ?? '100vw');
        $pictureAttrs = $this->normalizeAttributeBag(
            $options['pictureAttrs'] ?? $options['pictureAttributes'] ?? [],
        );
        $sourceAttrs = $this->normalizeAttributeBag(
            $options['sourceAttrs'] ?? $options['sourceAttributes'] ?? [],
        );

        $html = [];
        $html[] = $pictureAttrs === []
            ? '<picture>'
            : Html::beginTag('picture', $pictureAttrs);

        foreach ($ordered as $format) {
            $html[] = Html::tag('source', '', $this->mergeHtmlAttributes($sourceAttrs, [
                'type' => $this->mimeFromFormat($format),
                'srcset' => $this->srcset($source, [
                    'profile' => $profileName,
                    'format' => $format,
                    'variants' => $variants,
                ]),
                'sizes' => $sizes,
            ]));
        }

        $imgPlanned = $this->plan($source, array_merge($options, [
            'profile' => $profileName,
            'variant' => $fallbackVariant,
            'format' => $fallback,
        ]));

        $defaults = [
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'decoding' => $options['decoding'] ?? 'async',
            'sizes' => $sizes,
        ];

        if ($imgPlanned->widthHint !== null) {
            $defaults['width'] = $imgPlanned->widthHint;
        }

        if ($imgPlanned->heightHint !== null) {
            $defaults['height'] = $imgPlanned->heightHint;
        }

        $imgAttrs = $this->mergeHtmlAttributes($defaults, $this->extractHtmlAttributes($options), [
            'src' => $imgPlanned->deliveryUrl,
            'srcset' => $this->srcset($source, [
                'profile' => $profileName,
                'format' => $fallback,
                'variants' => $variants,
            ]),
        ]);

        $html[] = Html::tag('img', '', $imgAttrs);
        $html[] = '</picture>';

        return new Markup(implode("\n", $html), 'UTF-8');
    }

    /**
     * @param list<string> $variants
     */
    private function resolveFallbackVariant(array $variants, mixed $requested): string
    {
        if (is_string($requested) && $requested !== '' && in_array($requested, $variants, true)) {
            return $requested;
        }

        if (in_array('md', $variants, true)) {
            return 'md';
        }

        $middle = (int) floor((count($variants) - 1) / 2);

        return $variants[$middle] ?? ($variants[0] ?? 'md');
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
     * Collect HTML attributes from options.
     *
     * Accepts:
     * - any top-level key that is not a reserved Super Images option
     * - explicit bags: attrs / attributes / imgAttrs
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function extractHtmlAttributes(array $options): array
    {
        $attrs = [];

        foreach ($options as $key => $value) {
            if (!is_string($key) || $this->isReservedOptionKey($key)) {
                continue;
            }

            $attrs[$key] = $value;
        }

        foreach (['attrs', 'attributes', 'imgAttrs'] as $bagKey) {
            if (!isset($options[$bagKey])) {
                continue;
            }

            $attrs = array_merge($attrs, $this->normalizeAttributeBag($options[$bagKey]));
        }

        return $attrs;
    }

    /**
     * Later arrays win. Empty strings / null are dropped.
     *
     * @param array<string, mixed> ...$layers
     * @return array<string, mixed>
     */
    private function mergeHtmlAttributes(array ...$layers): array
    {
        $merged = [];

        foreach ($layers as $layer) {
            foreach ($layer as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $merged[(string) $key] = $value;
            }
        }

        return $merged;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeAttributeBag(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $attrs = [];
        foreach ($value as $key => $attrValue) {
            if (!is_string($key) && !is_int($key)) {
                continue;
            }
            $attrs[(string) $key] = $attrValue;
        }

        return $attrs;
    }

    private function isReservedOptionKey(string $key): bool
    {
        return in_array($key, [
            // generation / planning
            'profile',
            'variant',
            'variants',
            'format',
            'formats',
            'storage',
            'preview',
            // convenience defaults handled separately
            'alt',
            'loading',
            'decoding',
            'sizes',
            // attribute bags / wrappers
            'attrs',
            'attributes',
            'imgAttrs',
            'pictureAttrs',
            'pictureAttributes',
            'sourceAttrs',
            'sourceAttributes',
        ], true);
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
