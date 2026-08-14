<?php
/**
 * Playground preview generation against the real GenerationService.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\SourceException;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\Plugin;
use craft\elements\Asset;
use yii\base\Component;

/**
 * Playground Service
 *
 * Permissions are enforced by controllers, not this service.
 */
class PlaygroundService extends Component
{
    /**
     * Generate a preview derivative and return comparison metrics + code samples.
     *
     * @return array<string, mixed>
     */
    public function generate(int $assetId, ?string $profile = null, ?string $variant = null, ?string $format = null): array
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
        $format = $format !== null && $format !== ''
            ? $format
            : $settings->defaultFormat;

        if ($variant === null || $variant === '') {
            $variants = $settings->profiles[$profile]['variants'] ?? [];
            $variant = is_array($variants) && $variants !== []
                ? (string) array_key_first($variants)
                : null;
        }

        $request = new GenerationRequest(
            assetId: $assetId,
            profile: $profile,
            variant: $variant,
            format: $format,
            preview: true,
        );

        $result = $plugin->getGeneration()->generate($request, force: true);

        $originalSize = (int) ($asset->size ?? 0);
        $originalWidth = (int) ($asset->width ?? 0);
        $originalHeight = (int) ($asset->height ?? 0);
        $generatedSize = (int) $result->size;
        $percentSaved = null;
        if ($originalSize > 0 && $generatedSize >= 0) {
            $percentSaved = round((($originalSize - $generatedSize) / $originalSize) * 100, 2);
        }

        return [
            'success' => $result->success,
            'assetId' => $assetId,
            'assetTitle' => (string) $asset->title,
            'profile' => $profile,
            'variant' => $variant,
            'format' => $format,
            'original' => [
                'width' => $originalWidth,
                'height' => $originalHeight,
                'size' => $originalSize,
                'mime' => (string) ($asset->getMimeType() ?? ''),
                'filename' => (string) $asset->filename,
            ],
            'generated' => [
                'width' => $result->width,
                'height' => $result->height,
                'size' => $generatedSize,
                'mime' => $result->mime,
                'format' => $result->format,
                'url' => $result->url,
                'storagePath' => $result->storagePath,
                'identity' => $result->identity,
                'durationMs' => $result->durationMs,
                'diagnostics' => $result->diagnostics,
            ],
            'percentSaved' => $percentSaved,
            'bytesSaved' => $originalSize > 0 ? max(0, $originalSize - $generatedSize) : null,
            'code' => $this->codeSamples($assetId, $profile, $variant, $format),
        ];
    }

    /**
     * @return array{twigUrl: string, twigImg: string, twigPicture: string, php: string}
     */
    private function codeSamples(int $assetId, string $profile, ?string $variant, string $format): array
    {
        $options = [
            'profile' => $profile,
            'format' => $format,
        ];
        if ($variant !== null && $variant !== '') {
            $options['variant'] = $variant;
        }

        $optionsLiteral = $this->phpArrayLiteral($options);
        $twigOptions = $this->twigHashLiteral($options);

        return [
            'twigUrl' => "{{ craft.superImages.url(asset, {$twigOptions}) }}",
            'twigImg' => "{{ craft.superImages.img(asset, {$twigOptions}) }}",
            'twigPicture' => "{{ craft.superImages.picture(asset, { profile: '{$profile}', formats: ['{$format}'] }) }}",
            'php' => "\$result = \\amici\\SuperImages\\Plugin::getInstance()\n    ->getGeneration()\n    ->generate(new \\amici\\SuperImages\\models\\GenerationRequest(\n        assetId: {$assetId},\n        profile: '{$profile}',\n        variant: " . ($variant !== null ? "'{$variant}'" : 'null') . ",\n        format: '{$format}',\n    ));",
            'phpOptions' => $optionsLiteral,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function twigHashLiteral(array $options): string
    {
        $parts = [];
        foreach ($options as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $parts[] = sprintf("%s: '%s'", $key, $value);
        }

        return '{ ' . implode(', ', $parts) . ' }';
    }

    /**
     * @param array<string, mixed> $options
     */
    private function phpArrayLiteral(array $options): string
    {
        $parts = [];
        foreach ($options as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            $parts[] = sprintf("'%s' => '%s'", $key, $value);
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
