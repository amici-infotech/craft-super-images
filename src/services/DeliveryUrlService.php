<?php
/**
 * Plans delivery URLs for Twig.
 *
 * Mirrors Craft's generateTransformsBeforePageLoad:
 * - true  → generate missing files during the request, emit storage URLs
 * - false → emit a signed runtime action URL when the file is missing
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\services;

use amici\SuperImages\models\EffectiveConfig;
use amici\SuperImages\models\GenerationRequest;
use amici\SuperImages\models\OperationDefinition;
use amici\SuperImages\models\PlannedDelivery;
use amici\SuperImages\Plugin;
use Craft;
use yii\base\Component;

/**
 * Delivery URL Service
 *
 * Resolves whether Twig gets a storage URL (generate now) or a runtime action URL
 * (generate on first browser hit), plus thumbnail sync generation.
 */
final class DeliveryUrlService extends Component
{
    /**
     * Plan delivery metadata for a generation request.
     *
     * @param GenerationRequest $request The generation request with source and transform options.
     *
     * @return PlannedDelivery Delivery plan including storage URL, delivery URL, and dimension hints.
     */
    public function plan(GenerationRequest $request): PlannedDelivery
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $planned = $plugin->getGeneration()->plan($request);

        /** @var EffectiveConfig $config */
        $config = $planned['config'];
        $storageUrl = $planned['storageUrl'];
        $beforePageLoad = $this->generatesBeforePageLoad();

        $signParams = array_filter([
            'assetId' => $request->assetId,
            'localPath' => $request->localPath,
            'remoteUrl' => $request->remoteUrl,
            'profile' => $config->profile,
            'variant' => $config->variant,
            'format' => $config->format,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $deliveryUrl = $this->resolveDeliveryUrl(
            request: $request,
            storageUrl: $storageUrl,
            storagePath: $planned['storagePath'],
            identity: $planned['identity'],
            storageAdapter: $planned['definition']->storageAdapter,
            signParams: $signParams,
            beforePageLoad: $beforePageLoad,
            runtimeEnabled: (bool) ($settings->runtime['enabled'] ?? true),
        );

        [$widthHint, $heightHint] = $this->dimensionHints($config);

        return new PlannedDelivery(
            identity: $planned['identity'],
            storagePath: $planned['storagePath'],
            storageUrl: $storageUrl,
            deliveryUrl: $deliveryUrl,
            mode: $beforePageLoad ? 'beforePageLoad' : 'runtime',
            profile: $config->profile,
            variant: $config->variant,
            format: $config->format,
            widthHint: $widthHint,
            heightHint: $heightHint,
        );
    }

    /**
     * Whether missing derivatives should be created during Twig (like Craft transforms).
     *
     * Uses `delivery.generateBeforePageLoad` when set; otherwise mirrors Craft's
     * `generateTransformsBeforePageLoad`. Older `mode` / `generateOnMiss` keys still work.
     *
     * @return bool True to sync-generate before emitting a storage URL.
     */
    public function generatesBeforePageLoad(): bool
    {
        $delivery = Plugin::getInstance()->getSettings()->delivery ?? [];

        if (array_key_exists('generateBeforePageLoad', $delivery)) {
            return (bool) $delivery['generateBeforePageLoad'];
        }

        // Back-compat for older configs.
        if (array_key_exists('generateOnMiss', $delivery)) {
            return (bool) $delivery['generateOnMiss'];
        }

        $mode = strtolower((string) ($delivery['mode'] ?? ''));
        if ($mode === 'eager') {
            return true;
        }
        if ($mode === 'lazy' || $mode === 'hybrid') {
            return false;
        }

        return (bool) Craft::$app->getConfig()->getGeneral()->generateTransformsBeforePageLoad;
    }

    /**
     * Choose storage URL (generate now) or signed runtime URL (generate on request).
     *
     * @param GenerationRequest $request Generation request.
     * @param string $storageUrl Public storage/CDN URL.
     * @param string $storagePath Relative storage path.
     * @param string $identity Generation identity hash.
     * @param string $storageAdapter Adapter handle.
     * @param array<string, scalar> $signParams Params for SignedUrlService.
     * @param bool $beforePageLoad Generate during this request when missing.
     * @param bool $runtimeEnabled Whether signed runtime URLs are allowed.
     *
     * @return string URL for `<img>` / `srcset`.
     */
    private function resolveDeliveryUrl(
        GenerationRequest $request,
        string $storageUrl,
        string $storagePath,
        string $identity,
        string $storageAdapter,
        array $signParams,
        bool $beforePageLoad,
        bool $runtimeEnabled,
    ): string {
        $plugin = Plugin::getInstance();
        $exists = $this->derivativeExists(
            $storageAdapter,
            $storagePath,
            $identity,
            $request->assetId,
        );

        if ($exists) {
            return $storageUrl;
        }

        if ($beforePageLoad) {
            return $this->ensureStoredUrl($request, $storageUrl);
        }

        if ($runtimeEnabled) {
            return $plugin->getSignedUrls()->sign($signParams);
        }

        // Runtime off and not before-page-load — still sync-generate so Twig never 404s.
        return $this->ensureStoredUrl($request, $storageUrl);
    }

    /**
     * Whether the derivative is already in storage or marked as existing.
     *
     * @param string $storageAdapter Adapter handle.
     * @param string $storagePath Relative path.
     * @param string $identity Generation identity.
     *
     * @return bool True when the object or existence marker is present.
     */
    private function derivativeExists(string $storageAdapter, string $storagePath, string $identity, ?int $assetId = null): bool
    {
        return Plugin::getInstance()->getDerivativeExistence()->exists(
            $storageAdapter,
            $storagePath,
            $identity,
            $assetId,
        );
    }

    /**
     * Sync-generate a missing derivative and return its storage URL.
     *
     * @param GenerationRequest $request Generation request.
     * @param string $storageUrl Planned storage URL fallback.
     *
     * @return string Storage URL after generation (or the planned URL on failure).
     */
    private function ensureStoredUrl(GenerationRequest $request, string $storageUrl): string
    {
        $plugin = Plugin::getInstance();
        $locks = $plugin->getGenerationLocks();
        $planned = $plugin->getGeneration()->plan($request);
        $identity = $planned['identity'];

        if ($this->derivativeExists(
            $planned['definition']->storageAdapter,
            $planned['storagePath'],
            $identity,
            $request->assetId,
        )) {
            return $planned['storageUrl'];
        }

        if (!$locks->acquire($identity)) {
            $locks->waitAndCheck($identity);
            if ($this->derivativeExists(
                $planned['definition']->storageAdapter,
                $planned['storagePath'],
                $identity,
                $request->assetId,
            )) {
                return $planned['storageUrl'];
            }
            if (!$locks->acquire($identity)) {
                return $storageUrl;
            }
        }

        try {
            $result = $plugin->getGeneration()->generate($request, force: false);

            return $result->url !== '' ? $result->url : $storageUrl;
        } catch (\Throwable $exception) {
            Craft::warning(
                'Super Images generate-before-page-load failed: ' . $exception->getMessage(),
                __METHOD__,
            );

            return $storageUrl;
        } finally {
            $locks->release($identity);
        }
    }

    /**
     * Ensure a tiny placeholder derivative exists and return its storage URL.
     *
     * Always generates server-side (skips when already stored) and never emits a
     * signed runtime action URL — so `<img src>` paints immediately and reserves layout.
     *
     * @param GenerationRequest $sourceRequest Request carrying the source (+ profile/storage context).
     * @param array<string, mixed> $thumbnailConfig Merged delivery.thumbnail settings (already resolved).
     *
     * @return string|null Public storage URL, or null on failure / when disabled upstream.
     */
    public function ensureThumbnail(GenerationRequest $sourceRequest, array $thumbnailConfig): ?string
    {
        $plugin = Plugin::getInstance();
        $width = max(8, (int) ($thumbnailConfig['width'] ?? 32));
        $format = strtolower((string) ($thumbnailConfig['format'] ?? 'jpg'));
        if ($format === 'jpeg') {
            $format = 'jpg';
        }
        $variant = (string) ($thumbnailConfig['variant'] ?? 'thumb');
        if ($variant === '') {
            $variant = 'thumb';
        }

        $encodeOverrides = [];
        if (isset($thumbnailConfig['quality'])) {
            $encodeOverrides['quality'] = max(1, min(100, (int) $thumbnailConfig['quality']));
        }

        $thumbRequest = new GenerationRequest(
            assetId: $sourceRequest->assetId,
            localPath: $sourceRequest->localPath,
            remoteUrl: $sourceRequest->remoteUrl,
            profile: $sourceRequest->profile,
            variant: $variant,
            format: $format,
            operationOverrides: [
                new OperationDefinition('fit', OperationDefinition::normalizeOptions([
                    'width' => $width,
                ])),
            ],
            encodeOverrides: $encodeOverrides,
            optimizersEnabled: false,
            volume: $sourceRequest->volume,
            folder: $sourceRequest->folder,
            field: $sourceRequest->field,
            storageAdapter: $sourceRequest->storageAdapter,
            preview: false,
        );

        $locks = $plugin->getGenerationLocks();
        $planned = $plugin->getGeneration()->plan($thumbRequest);
        $identity = $planned['identity'];
        $storageUrl = $planned['storageUrl'];
        $storagePath = $planned['storagePath'];

        if ($this->derivativeExists(
            $planned['definition']->storageAdapter,
            $storagePath,
            $identity,
            $thumbRequest->assetId,
        )) {
            return $storageUrl;
        }

        if (!$locks->acquire($identity)) {
            $locks->waitAndCheck($identity);
            if ($this->derivativeExists(
                $planned['definition']->storageAdapter,
                $storagePath,
                $identity,
                $thumbRequest->assetId,
            )) {
                return $storageUrl;
            }
            if (!$locks->acquire($identity)) {
                // Another worker is generating; fall back to storage URL (may 404 briefly).
                return $storageUrl;
            }
        }

        try {
            $result = $plugin->getGeneration()->generate($thumbRequest, force: false);

            return $result->url !== '' ? $result->url : $storageUrl;
        } catch (\Throwable $exception) {
            Craft::warning(
                'Super Images thumbnail generation failed: ' . $exception->getMessage(),
                __METHOD__,
            );

            return null;
        } finally {
            $locks->release($identity);
        }
    }

    /**
     * Extract width/height hints from the first geometry operation in the config.
     *
     * @param EffectiveConfig $config The resolved effective configuration.
     *
     * @return array{0: ?int, 1: ?int} Tuple of [widthHint, heightHint].
     */
    private function dimensionHints(EffectiveConfig $config): array
    {
        foreach ($config->operations as $operation) {
            if (!$operation instanceof OperationDefinition) {
                continue;
            }

            if (!in_array($operation->type, ['resize', 'crop', 'fill', 'scale', 'fit'], true)) {
                continue;
            }

            $width = isset($operation->options['width']) ? (int) $operation->options['width'] : null;
            $height = isset($operation->options['height']) ? (int) $operation->options['height'] : null;

            if ($width !== null || $height !== null) {
                return [$width, $height];
            }
        }

        return [null, null];
    }
}
