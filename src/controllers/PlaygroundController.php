<?php
/**
 * Control Panel Playground for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\exceptions\SuperImagesException;
use amici\SuperImages\models\Settings;
use amici\SuperImages\Plugin;
use Craft;
use craft\web\Controller;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Playground Controller
 */
class PlaygroundController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:playground');

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('super-images/playground/index', [
            'settings' => $settings,
            'profiles' => $this->profileOptions($settings->profiles),
            'profileSelectOptions' => $this->profileSelectOptions($settings->profiles),
            'formats' => $this->formatOptions($settings),
            'formatSelectOptions' => $this->formatSelectOptions($settings),
            'result' => null,
            'error' => null,
            'posted' => [
                'assetId' => null,
                'profile' => $settings->defaultProfile,
                'variant' => null,
                'format' => $settings->defaultFormat,
            ],
        ]);
    }

    public function actionGenerate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-images:playground');

        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        $assetId = (int) $request->getRequiredBodyParam('assetId');
        $profile = (string) $request->getBodyParam('profile', $settings->defaultProfile);
        $variant = $request->getBodyParam('variant');
        $format = (string) $request->getBodyParam('format', $settings->defaultFormat);
        $variant = is_string($variant) && $variant !== '' ? $variant : null;

        $posted = [
            'assetId' => $assetId,
            'profile' => $profile,
            'variant' => $variant,
            'format' => $format,
        ];

        $templateVars = [
            'settings' => $settings,
            'profiles' => $this->profileOptions($settings->profiles),
            'profileSelectOptions' => $this->profileSelectOptions($settings->profiles),
            'formats' => $this->formatOptions($settings),
            'formatSelectOptions' => $this->formatSelectOptions($settings),
            'posted' => $posted,
        ];

        try {
            if ($assetId <= 0) {
                throw new BadRequestHttpException('Asset ID is required.');
            }

            $result = Plugin::getInstance()->getPlayground()->generate(
                $assetId,
                $profile,
                $variant,
                $format,
            );

            if ($request->getAcceptsJson()) {
                return $this->asJson($result);
            }

            return $this->renderTemplate('super-images/playground/index', array_merge($templateVars, [
                'result' => $result,
                'error' => null,
            ]));
        } catch (SuperImagesException|BadRequestHttpException $exception) {
            if ($request->getAcceptsJson()) {
                return $this->asFailure($exception->getMessage());
            }

            return $this->renderTemplate('super-images/playground/index', array_merge($templateVars, [
                'result' => null,
                'error' => $exception->getMessage(),
            ]));
        } catch (Throwable $exception) {
            Craft::error($exception->getMessage(), __METHOD__);

            if ($request->getAcceptsJson()) {
                return $this->asFailure('Playground generation failed.');
            }

            return $this->renderTemplate('super-images/playground/index', array_merge($templateVars, [
                'result' => null,
                'error' => 'Playground generation failed.',
            ]));
        }
    }

    /**
     * @param array<string, mixed> $profiles
     * @return array<string, array{label: string, variants: list<string>}>
     */
    private function profileOptions(array $profiles): array
    {
        $out = [];
        foreach ($profiles as $handle => $config) {
            if (!is_string($handle) || !is_array($config)) {
                continue;
            }
            $variants = $config['variants'] ?? [];
            $out[$handle] = [
                'label' => $handle,
                'variants' => is_array($variants) ? array_map('strval', array_keys($variants)) : [],
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $profiles
     * @return list<array{label: string, value: string}>
     */
    private function profileSelectOptions(array $profiles): array
    {
        $options = [];
        foreach ($this->profileOptions($profiles) as $handle => $profile) {
            $options[] = [
                'label' => $profile['label'],
                'value' => $handle,
            ];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private function formatOptions(Settings $settings): array
    {
        $formats = array_keys($settings->encoders);
        $normalized = [];
        foreach ($formats as $format) {
            if (!is_string($format) || $format === '') {
                continue;
            }
            $key = strtolower($format) === 'jpeg' ? 'jpg' : strtolower($format);
            $normalized[$key] = $key;
        }

        $normalized = array_values($normalized);
        sort($normalized);

        return $normalized;
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function formatSelectOptions(Settings $settings): array
    {
        $options = [];
        foreach ($this->formatOptions($settings) as $format) {
            $options[] = [
                'label' => $format,
                'value' => $format,
            ];
        }

        return $options;
    }
}
