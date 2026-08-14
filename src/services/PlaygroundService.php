<?php
/**
 * Playground preview generation against the real GenerationService.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\InvalidConfigurationException;
use amici\SuperImages\exceptions\SourceException;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\Plugin;
use craft\elements\Asset;
use craft\helpers\Html;
use Throwable;
use yii\base\Component;

/**
 * Playground Service
 *
 * Permissions are enforced by controllers, not this service.
 */
class PlaygroundService extends Component
{
    /**
     * Generate every variant × format for a profile and return a preview gallery payload.
     *
     * @return array<string, mixed>
     */
    public function generateProfile(int $assetId, ?string $profile = null): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $asset = Asset::find()->id($assetId)->one();
        if (!$asset instanceof Asset) {
            throw new SourceException(sprintf('Asset #%d was not found.', $assetId));
        }

        $profile = $profile !== null && $profile !== ''
            ? $profile
            : $settings->defaultProfile;

        if (!isset($settings->profiles[$profile]) || !is_array($settings->profiles[$profile])) {
            throw new InvalidConfigurationException(sprintf('Profile "%s" is not defined.', $profile));
        }

        $profileConfig = $settings->profiles[$profile];
        $formats = array_values(array_map('strval', $profileConfig['formats'] ?? [$settings->defaultFormat]));
        $variantNames = array_map('strval', array_keys($profileConfig['variants'] ?? []));
        if ($variantNames === []) {
            $variantNames = ['default'];
        }

        $units = $plugin->getManifest()->buildForAsset($asset, ['profile' => $profile]);
        $items = [];
        $errors = [];
        $totalDurationMs = 0.0;
        $started = microtime(true);

        foreach ($units as $unit) {
            try {
                $request = new GenerationRequest(
                    assetId: $assetId,
                    profile: $unit->profile,
                    variant: $unit->variant,
                    format: $unit->format,
                    preview: true,
                );
                $result = $plugin->getGeneration()->generate($request, force: true);
                $totalDurationMs += $result->durationMs;

                $originalSize = (int) ($asset->size ?? 0);
                $generatedSize = (int) $result->size;
                $percentSaved = null;
                if ($originalSize > 0 && $generatedSize >= 0) {
                    $percentSaved = round((($originalSize - $generatedSize) / $originalSize) * 100, 2);
                }

                $items[] = [
                    'success' => $result->success,
                    'variant' => $unit->variant,
                    'format' => $unit->format,
                    'width' => $result->width,
                    'height' => $result->height,
                    'size' => $generatedSize,
                    'mime' => $result->mime,
                    'url' => $result->url,
                    'storagePath' => $result->storagePath,
                    'identity' => $result->identity,
                    'durationMs' => $result->durationMs,
                    'percentSaved' => $percentSaved,
                    'diagnostics' => $result->diagnostics,
                    'error' => null,
                ];
            } catch (Throwable $exception) {
                $errors[] = [
                    'variant' => $unit->variant,
                    'format' => $unit->format,
                    'message' => $exception->getMessage(),
                ];
                $items[] = [
                    'success' => false,
                    'variant' => $unit->variant,
                    'format' => $unit->format,
                    'width' => 0,
                    'height' => 0,
                    'size' => 0,
                    'mime' => '',
                    'url' => null,
                    'storagePath' => $unit->storagePath,
                    'identity' => $unit->identity,
                    'durationMs' => 0.0,
                    'percentSaved' => null,
                    'diagnostics' => [],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        $byVariant = [];
        foreach ($variantNames as $variantName) {
            $byVariant[$variantName] = [];
        }
        foreach ($items as $item) {
            $byVariant[$item['variant']][] = $item;
        }

        $generatedCount = count(array_filter($items, static fn(array $item): bool => (bool) $item['success']));

        return [
            'success' => $errors === [],
            'assetId' => $assetId,
            'assetTitle' => (string) ($asset->title ?: $asset->filename),
            'assetUrl' => $asset->getUrl(),
            'profile' => $profile,
            'formats' => $formats,
            'variants' => $variantNames,
            'original' => [
                'width' => (int) ($asset->width ?? 0),
                'height' => (int) ($asset->height ?? 0),
                'size' => (int) ($asset->size ?? 0),
                'mime' => (string) ($asset->getMimeType() ?? ''),
                'filename' => (string) $asset->filename,
            ],
            'units' => $items,
            'byVariant' => $byVariant,
            'errors' => $errors,
            'summary' => [
                'planned' => count($units),
                'generated' => $generatedCount,
                'failed' => count($errors),
                'durationMs' => $totalDurationMs > 0 ? $totalDurationMs : ((microtime(true) - $started) * 1000),
            ],
            'code' => $this->profileCodeSamples($profile, $formats),
            'responsiveHtml' => $this->buildResponsiveHtml(
                $items,
                $formats,
                $variantNames,
                (string) ($asset->title ?: $asset->filename),
            ),
        ];
    }

    /**
     * Build a real multi-width `<picture>` from generated preview URLs.
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $formats
     * @param list<string> $variants
     * @return array{markup: string, pretty: string}|null
     */
    private function buildResponsiveHtml(array $items, array $formats, array $variants, string $alt): ?array
    {
        $byFormat = [];
        foreach ($items as $item) {
            if (!(bool) ($item['success'] ?? false) || empty($item['url'])) {
                continue;
            }
            $format = strtolower((string) $item['format']);
            $byFormat[$format][] = $item;
        }

        if ($byFormat === []) {
            return null;
        }

        $priority = ['avif' => 0, 'webp' => 1, 'jpg' => 2, 'jpeg' => 2, 'png' => 3];
        $orderedFormats = array_values(array_unique(array_map(
            static fn(string $f) => strtolower($f) === 'jpeg' ? 'jpg' : strtolower($f),
            $formats !== [] ? $formats : array_keys($byFormat),
        )));
        usort($orderedFormats, static fn(string $a, string $b): int => ($priority[$a] ?? 50) <=> ($priority[$b] ?? 50));

        $fallback = null;
        foreach (array_reverse($orderedFormats) as $format) {
            if (isset($byFormat[$format])) {
                $fallback = $format;
                break;
            }
        }
        if ($fallback === null) {
            return null;
        }

        $sourceFormats = array_values(array_filter(
            $orderedFormats,
            static fn(string $f): bool => $f !== $fallback && isset($byFormat[$f]),
        ));

        $sizes = '100vw';
        $lines = ['<picture>'];

        foreach ($sourceFormats as $format) {
            $srcset = $this->srcsetFromItems($byFormat[$format], $variants);
            if ($srcset === '') {
                continue;
            }
            $lines[] = '  ' . Html::tag('source', '', [
                'type' => $this->mimeFromFormat($format),
                'srcset' => $srcset,
                'sizes' => $sizes,
            ]);
        }

        $fallbackItems = $byFormat[$fallback];
        $fallbackSrc = $this->pickFallbackUrl($fallbackItems, $variants);
        $fallbackSrcset = $this->srcsetFromItems($fallbackItems, $variants);
        $fallbackMeta = $this->pickFallbackItem($fallbackItems, $variants);

        $imgAttrs = [
            'src' => $fallbackSrc,
            'alt' => $alt,
            'loading' => 'lazy',
            'decoding' => 'async',
            'sizes' => $sizes,
            'data-animation-controller' => 'off',
        ];
        if ($fallbackSrcset !== '') {
            $imgAttrs['srcset'] = $fallbackSrcset;
        }
        if (!empty($fallbackMeta['width'])) {
            $imgAttrs['width'] = (int) $fallbackMeta['width'];
        }
        if (!empty($fallbackMeta['height'])) {
            $imgAttrs['height'] = (int) $fallbackMeta['height'];
        }

        $lines[] = '  ' . Html::tag('img', '', $imgAttrs);
        $lines[] = '</picture>';

        $pretty = implode("\n", $lines);

        return [
            'markup' => $pretty,
            'pretty' => $pretty,
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $variants
     */
    private function srcsetFromItems(array $items, array $variants): string
    {
        $byVariant = [];
        foreach ($items as $item) {
            $byVariant[(string) $item['variant']] = $item;
        }

        $parts = [];
        foreach ($variants as $variant) {
            if (!isset($byVariant[$variant])) {
                continue;
            }
            $item = $byVariant[$variant];
            $url = (string) $item['url'];
            $width = (int) ($item['width'] ?? 0);
            $parts[] = $width > 0 ? "{$url} {$width}w" : $url;
        }

        return implode(', ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $variants
     * @return array<string, mixed>
     */
    private function pickFallbackItem(array $items, array $variants): array
    {
        $byVariant = [];
        foreach ($items as $item) {
            $byVariant[(string) $item['variant']] = $item;
        }

        if (isset($byVariant['md'])) {
            return $byVariant['md'];
        }

        $middle = (int) floor((count($variants) - 1) / 2);
        $name = $variants[$middle] ?? ($variants[0] ?? null);

        return ($name !== null && isset($byVariant[$name]))
            ? $byVariant[$name]
            : ($items[0] ?? []);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string> $variants
     */
    private function pickFallbackUrl(array $items, array $variants): string
    {
        return (string) ($this->pickFallbackItem($items, $variants)['url'] ?? '');
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

    /**
     * @param list<string> $formats
     * @return array{twigPicture: string, twigImg: string, twigUrl: string}
     */
    private function profileCodeSamples(string $profile, array $formats): array
    {
        $formatList = $formats !== []
            ? "'" . implode("', '", $formats) . "'"
            : "'webp'";

        return [
            'twigPicture' => "{{ craft.superImages.picture(asset, { profile: '{$profile}', formats: [{$formatList}], sizes: '100vw' }) }}",
            'twigImg' => "{{ craft.superImages.img(asset, { profile: '{$profile}', variant: 'md', format: 'webp' }) }}",
            'twigUrl' => "{{ craft.superImages.url(asset, { profile: '{$profile}', variant: 'md', format: 'webp' }) }}",
        ];
    }
}
