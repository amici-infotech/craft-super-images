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
 *
 * Twig API exposed as `craft.superImages`.
 */
class SuperImagesVariable
{
    /**
     * Generate a derivative and return the full result object.
     *
     * Options: `profile`, `variant`, `format`, `storage`.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Generation options.
     *
     * @return GenerationResult The generation outcome including URL and diagnostics.
     */
    public function generate(Asset|string|int $source, array $options = []): GenerationResult
    {
        $request = $this->buildRequest($source, $options);

        return Plugin::getInstance()->getGeneration()->generate($request);
    }

    /**
     * Soft generate for demo templates — returns null on failure instead of throwing.
     *
     * Options: `profile`, `variant`, `format`, `storage`.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Generation options.
     *
     * @return GenerationResult|null The result, or null when generation fails.
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
     * Plan and return a delivery URL (storage URL, or signed action URL when deferred).
     *
     * When planning fails for a missing or invalid Craft asset, and
     * `policies.fallback` is enabled with a distinct fallback asset ID,
     * retries once using that asset while preserving profile/variant/format options.
     *
     * Options: `profile`, `variant`, `format`, `storage`.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Planning options.
     *
     * @return string The resolved delivery URL.
     */
    public function url(Asset|string|int $source, array $options = []): string
    {
        return $this->plan($source, $options)->deliveryUrl;
    }

    /**
     * Plan and return an `<img>` tag for one derivative.
     *
     * Applies the same fallback policy as {@see url()} when planning fails.
     * Emits a single `src` (no `srcset`). Use {@see picture()} for responsive multi-width output.
     *
     * Generation options: `profile`, `variant`, `format`, `storage`.
     *
     * HTML attribute options:
     * - top-level keys that are not reserved (e.g. `id`, `class`, `data-*`)
     * - `attrs` / `attributes` / `imgAttrs` bag (merged last for those keys)
     * - convenience keys: `alt`, `loading`
     *
     * Managed attrs (`src`, and dimensions when known) always win.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Generation and HTML attribute options.
     *
     * @return Markup Safe HTML markup for the `<img>` tag, or an error `<div>` on failure.
     */
    public function img(Asset|string|int $source, array $options = []): Markup
    {
        try {
            $planned = $this->plan($source, $options);
        } catch (SuperImagesException $exception) {
            $message = Html::encode($exception->getMessage());

            return new Markup('<div class="error">Skipped: ' . $message . '</div>', 'UTF-8');
        }

        [$width, $height] = $this->resolveLayoutDimensions($source, $planned);

        $defaults = [
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
        ];

        if ($width !== null) {
            $defaults['width'] = $width;
        }

        if ($height !== null) {
            $defaults['height'] = $height;
        }

        // Single derivative — no srcset. Use picture() for responsive multi-width output.
        $attrs = $this->mergeHtmlAttributes($defaults, $this->extractHtmlAttributes($options), [
            'src' => $planned->deliveryUrl,
        ]);

        return new Markup(Html::tag('img', '', $attrs), 'UTF-8');
    }

    /**
     * Plan a responsive `<picture>` with multi-width srcsets per format.
     *
     * Applies the same fallback policy as {@see url()} when planning fails.
     *
     * Uses profile variants (or explicit `variants`) as `w` descriptors.
     * Optional `variant` picks the fallback `<img srcset>` middle candidate (default: middle / `md`).
     * When `delivery.thumbnail` is enabled, `<img src>` is a server-generated storage URL.
     * Pass `thumbnail: false` (or `thumbnail: { enabled: false }`) to skip the thumb and use the
     * fallback variant delivery URL as `src` instead.
     *
     * Generation options: `profile`, `variant`, `variants`, `formats`, `format`, `storage`, `sizes`, `thumbnail`.
     *
     * HTML attribute options:
     * - `pictureAttrs` / `pictureAttributes` on `<picture>`
     * - top-level non-reserved keys + `attrs` / `attributes` / `imgAttrs` on the inner `<img>`
     * - `sourceAttrs` / `sourceAttributes` merged onto every `<source>`
     * - convenience keys: `alt`, `loading`, `sizes`
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Generation and HTML attribute options.
     *
     * @return Markup Safe HTML markup for the `<picture>` element.
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

        $imgPlanned = $this->plan($source, array_merge($options, [
            'profile' => $profileName,
            'variant' => $fallbackVariant,
            'format' => $fallback,
        ]));
        [$width, $height] = $this->resolveLayoutDimensions($source, $imgPlanned);
        $thumbnailUrl = $this->resolveThumbnailUrl($source, $options);

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

        $defaults = [
            'alt' => (string) ($options['alt'] ?? $this->defaultAlt($source)),
            'loading' => $options['loading'] ?? 'lazy',
            'sizes' => $sizes,
        ];

        if ($width !== null) {
            $defaults['width'] = $width;
        }

        if ($height !== null) {
            $defaults['height'] = $height;
        }

        $imgAttrs = $this->mergeHtmlAttributes($defaults, $this->extractHtmlAttributes($options), [
            // Thumb when enabled; otherwise the fallback variant delivery URL (no SVG placeholder).
            'src' => $thumbnailUrl ?? $imgPlanned->deliveryUrl,
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
     * Picks the fallback variant for the inner `<img>` in a `<picture>`.
     *
     * @param list<string> $variants Available variant handles.
     * @param mixed $requested Explicitly requested variant, if any.
     *
     * @return string The resolved variant handle.
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
     * Applies the same fallback policy as {@see url()} when planning fails.
     *
     * Options: `profile`, `format`, `variants`.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Srcset planning options.
     *
     * @return string Comma-separated srcset descriptor string.
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
     *
     * @param string $format Output format handle (e.g. `webp`, `jpg`, `avif`).
     *
     * @return bool True when the active driver supports the format.
     */
    public function supportsFormat(string $format): bool
    {
        $driver = Plugin::getInstance()->getDriverManager()->select();
        $format = strtolower($format) === 'jpg' ? 'jpeg' : strtolower($format);

        return in_array($format, $driver->capabilities()->formats, true)
            || in_array(strtolower($format) === 'jpeg' ? 'jpg' : $format, $driver->capabilities()->formats, true);
    }

    /**
     * Plans delivery metadata without generating the derivative.
     *
     * When planning fails with {@see SuperImagesException} and a distinct fallback
     * asset is configured under `policies.fallback`, retries once with that asset.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Planning options.
     * @param bool $allowFallback Whether a configured fallback asset may be attempted.
     *
     * @return PlannedDelivery Planned delivery URL and dimension hints.
     */
    private function plan(Asset|string|int $source, array $options = [], bool $allowFallback = true): PlannedDelivery
    {
        try {
            return Plugin::getInstance()->getDeliveryUrls()->plan(
                $this->buildRequest($source, $options),
            );
        } catch (SuperImagesException $exception) {
            if (!$allowFallback) {
                throw $exception;
            }

            $fallbackId = $this->resolveFallbackAssetId($source);
            if ($fallbackId === null) {
                throw $exception;
            }

            return $this->plan($fallbackId, $options, false);
        }
    }

    /**
     * Resolves a configured fallback Craft asset ID when policy allows it.
     *
     * @param Asset|string|int $source Original Twig source reference.
     *
     * @return int|null Fallback asset ID, or null when fallback is disabled or would recurse.
     */
    private function resolveFallbackAssetId(Asset|string|int $source): ?int
    {
        $policies = Plugin::getInstance()->getSettings()->policies['fallback'] ?? [];

        if (!($policies['enabled'] ?? false)) {
            return null;
        }

        $fallbackId = (int) ($policies['assetId'] ?? 0);
        if ($fallbackId <= 0) {
            return null;
        }

        $requestedId = $this->requestedAssetId($source);
        if ($requestedId !== null && $requestedId === $fallbackId) {
            return null;
        }

        return $fallbackId;
    }

    /**
     * Extracts a Craft asset ID from a Twig source reference when available.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     *
     * @return int|null Asset ID when the source is asset-backed.
     */
    private function requestedAssetId(Asset|string|int $source): ?int
    {
        if ($source instanceof Asset) {
            return (int) $source->id;
        }

        if (is_int($source) || (is_string($source) && ctype_digit($source))) {
            return (int) $source;
        }

        return null;
    }

    /**
     * Builds a generation request from a source reference and options.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Request options.
     *
     * @return GenerationRequest The normalized generation request.
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

    /**
     * Returns a sensible default alt text for a source reference.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     *
     * @return string Default alt text, or an empty string when unavailable.
     */
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
     * @param array<string, mixed> $options Twig helper options.
     *
     * @return array<string, mixed> Extracted HTML attributes.
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
     * Merges HTML attribute layers; later arrays win.
     *
     * Empty strings and null values are dropped.
     *
     * @param array<string, mixed> ...$layers Attribute layers to merge.
     *
     * @return array<string, mixed> Merged attributes.
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
     * Normalizes an attribute bag to a string-keyed array.
     *
     * @param mixed $value Raw attribute bag value.
     *
     * @return array<string, mixed> Normalized attributes.
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

    /**
     * Resolves display width/height so the layout box is reserved before pixels load.
     *
     * Uses planned geometry hints and fills the missing axis from the source asset's
     * aspect ratio (variants often only set width).
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param PlannedDelivery $planned Planned delivery with optional width/height hints.
     *
     * @return array{0: ?int, 1: ?int} Tuple of [width, height].
     */
    private function resolveLayoutDimensions(Asset|string|int $source, PlannedDelivery $planned): array
    {
        $width = $planned->widthHint;
        $height = $planned->heightHint;

        $asset = $this->resolveAsset($source);
        $srcW = $asset !== null ? (int) $asset->getWidth() : 0;
        $srcH = $asset !== null ? (int) $asset->getHeight() : 0;

        if ($width !== null && $width > 0 && ($height === null || $height <= 0) && $srcW > 0 && $srcH > 0) {
            $height = (int) max(1, (int) round($width * $srcH / $srcW));
        }

        if ($height !== null && $height > 0 && ($width === null || $width <= 0) && $srcW > 0 && $srcH > 0) {
            $width = (int) max(1, (int) round($height * $srcW / $srcH));
        }

        if (($width === null || $width <= 0) && ($height === null || $height <= 0) && $srcW > 0 && $srcH > 0) {
            $width = $srcW;
            $height = $srcH;
        }

        return [
            $width !== null && $width > 0 ? $width : null,
            $height !== null && $height > 0 ? $height : null,
        ];
    }

    /**
     * Resolves a Craft Asset from a Twig source reference when possible.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     *
     * @return Asset|null The asset element, or null when the source is not asset-backed.
     */
    private function resolveAsset(Asset|string|int $source): ?Asset
    {
        if ($source instanceof Asset) {
            return $source;
        }

        if (is_int($source) || (is_string($source) && ctype_digit($source))) {
            return Craft::$app->getAssets()->getAssetById((int) $source);
        }

        return null;
    }

    /**
     * Resolves a tiny placeholder URL for immediate `<img src>` paint.
     *
     * Generates a Super Images derivative server-side (skipped when already stored) and
     * returns its storage URL — never a signed runtime action URL.
     *
     * @param Asset|string|int $source Asset, asset ID, local path, or remote URL.
     * @param array<string, mixed> $options Twig options; `thumbnail` may disable or override config.
     *
     * @return string|null Thumbnail storage URL, or null when disabled / generation fails.
     */
    private function resolveThumbnailUrl(Asset|string|int $source, array $options): ?string
    {
        $settings = Plugin::getInstance()->getSettings()->delivery['thumbnail'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        $override = $options['thumbnail'] ?? null;
        if ($override === false || $override === 0 || $override === '0' || $override === 'false') {
            return null;
        }

        $enabled = (bool) ($settings['enabled'] ?? true);
        if (is_array($override)) {
            $settings = array_merge($settings, $override);
            if (array_key_exists('enabled', $override) && ($override['enabled'] === false || $override['enabled'] === 0 || $override['enabled'] === '0' || $override['enabled'] === 'false')) {
                return null;
            }
            $enabled = (bool) ($settings['enabled'] ?? true);
        } elseif ($override === true || $override === 1 || $override === '1' || $override === 'true') {
            $enabled = true;
        }

        if (!$enabled) {
            return null;
        }

        try {
            return Plugin::getInstance()->getDeliveryUrls()->ensureThumbnail(
                $this->buildRequest($source, $options),
                $settings,
            );
        } catch (\Throwable $exception) {
            Craft::warning(
                'Super Images thumbnail resolve failed: ' . $exception->getMessage(),
                __METHOD__,
            );

            return null;
        }
    }

    /**
     * Whether an option key is reserved for generation/planning rather than HTML attrs.
     *
     * @param string $key Option key to test.
     *
     * @return bool True when the key is reserved.
     */
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
            'thumbnail',
            // convenience defaults handled separately
            'alt',
            'loading',
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
     * Orders formats for `<picture>` `<source>` elements (modern first).
     *
     * @param list<string> $formats Format handles to order.
     *
     * @return list<string> Ordered format handles.
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

    /**
     * Maps a format handle to a MIME type for `<source type="">`.
     *
     * @param string $format Output format handle.
     *
     * @return string MIME type string.
     */
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
