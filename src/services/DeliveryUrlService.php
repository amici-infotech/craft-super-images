<?php
/**
 * Plans delivery URLs for Twig without storage I/O.
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
use yii\base\Component;

/**
 * Delivery URL Service
 *
 * Plans which URL Twig should emit (direct storage vs signed runtime URL) based on
 * delivery mode, without reading from storage or generating derivatives.
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
        $mode = (string) ($settings->delivery['mode'] ?? 'lazy');
        $storageUrl = $planned['storageUrl'];

        $signParams = array_filter([
            'assetId' => $request->assetId,
            'localPath' => $request->localPath,
            'remoteUrl' => $request->remoteUrl,
            'profile' => $config->profile,
            'variant' => $config->variant,
            'format' => $config->format,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $deliveryUrl = $mode === 'lazy'
            ? $plugin->getSignedUrls()->sign($signParams)
            : $storageUrl;

        [$widthHint, $heightHint] = $this->dimensionHints($config);

        return new PlannedDelivery(
            identity: $planned['identity'],
            storagePath: $planned['storagePath'],
            storageUrl: $storageUrl,
            deliveryUrl: $deliveryUrl,
            mode: $mode,
            profile: $config->profile,
            variant: $config->variant,
            format: $config->format,
            widthHint: $widthHint,
            heightHint: $heightHint,
        );
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

            if (!in_array($operation->type, ['resize', 'crop', 'fill', 'scale'], true)) {
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
