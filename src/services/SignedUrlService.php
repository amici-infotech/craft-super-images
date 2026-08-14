<?php

namespace amici\SuperImages\services;

use amici\SuperImages\exceptions\InvalidConfigurationException;
use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use yii\base\Component;

/**
 * Signs and verifies runtime generation URLs.
 */
final class SignedUrlService extends Component
{
    private const ACTION_ROUTE = 'super-images/runtime/generate';

    /**
     * @param array<string, scalar|null> $params assetId|localPath|remoteUrl, profile, variant, format
     */
    public function sign(array $params): string
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!($settings->runtime['enabled'] ?? true)) {
            throw new InvalidConfigurationException('Runtime generation is disabled.');
        }

        $payload = $this->normalizeParams($params);
        $ttl = (int) ($settings->runtime['urlTtl'] ?? 3600);
        $payload['exp'] = time() + max(1, $ttl);
        $payload['sig'] = $this->computeSignature($payload);

        return UrlHelper::actionUrl(self::ACTION_ROUTE, $payload);
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, scalar>
     */
    public function verify(array $queryParams): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!($settings->runtime['enabled'] ?? true)) {
            throw new SuperImagesException('Runtime generation is disabled.');
        }

        $signature = (string) ($queryParams['sig'] ?? '');
        if ($signature === '') {
            throw new SuperImagesException('Missing signature.');
        }

        $params = $this->normalizeParams($queryParams);

        if (!isset($params['exp']) || !is_numeric($params['exp'])) {
            throw new SuperImagesException('Missing expiry.');
        }

        if ((int) $params['exp'] < time()) {
            throw new SuperImagesException('Signed URL has expired.');
        }

        $expected = $this->computeSignature($params);

        if (!hash_equals($expected, $signature)) {
            throw new SuperImagesException('Invalid signature.');
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, scalar>
     */
    private function normalizeParams(array $params): array
    {
        $normalized = [];

        if (isset($params['assetId']) && $params['assetId'] !== '' && $params['assetId'] !== null) {
            $normalized['assetId'] = (int) $params['assetId'];
        } elseif (!empty($params['localPath'])) {
            $normalized['localPath'] = (string) $params['localPath'];
        } elseif (!empty($params['remoteUrl'])) {
            $normalized['remoteUrl'] = (string) $params['remoteUrl'];
        } else {
            throw new SuperImagesException('Signed URL must include assetId, localPath, or remoteUrl.');
        }

        foreach (['profile', 'variant', 'format'] as $key) {
            if (!isset($params[$key]) || $params[$key] === '') {
                throw new SuperImagesException(sprintf('Signed URL must include %s.', $key));
            }

            $normalized[$key] = (string) $params[$key];
        }

        if (isset($params['exp']) && $params['exp'] !== '') {
            $normalized['exp'] = (int) $params['exp'];
        }

        return $normalized;
    }

    /**
     * @param array<string, scalar> $params
     */
    private function computeSignature(array $params): string
    {
        unset($params['sig']);
        ksort($params);

        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $secret = $this->signingSecret();

        return hash_hmac('sha256', $payload, $secret);
    }

    private function signingSecret(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $secret = $settings->runtime['signingSecret'] ?? null;

        if (is_string($secret) && $secret !== '') {
            return $secret;
        }

        return Craft::$app->getConfig()->getGeneral()->securityKey;
    }
}
