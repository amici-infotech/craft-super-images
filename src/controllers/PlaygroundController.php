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
use craft\elements\Asset;
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

        return $this->renderTemplate('super-images/playground/index', $this->templateVars($settings, [
            'assetId' => null,
            'profile' => $settings->defaultProfile,
        ]));
    }

    public function actionGenerate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-images:playground');

        $request = Craft::$app->getRequest();
        $settings = Plugin::getInstance()->getSettings();

        $assetId = $this->resolveAssetId($request->getBodyParam('assetId'));
        $profile = (string) $request->getBodyParam('profile', $settings->defaultProfile);

        $posted = [
            'assetId' => $assetId > 0 ? $assetId : null,
            'profile' => $profile,
        ];

        $templateVars = $this->templateVars($settings, $posted);

        try {
            if ($assetId <= 0) {
                throw new BadRequestHttpException('Please choose an image asset.');
            }

            $result = Plugin::getInstance()->getPlayground()->generateProfile($assetId, $profile);

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
     * @param array{assetId: ?int, profile: string} $posted
     * @return array<string, mixed>
     */
    private function templateVars(Settings $settings, array $posted): array
    {
        $selectedAssets = [];
        if (!empty($posted['assetId'])) {
            $asset = Asset::find()->id((int) $posted['assetId'])->kind(Asset::KIND_IMAGE)->one();
            if ($asset instanceof Asset) {
                $selectedAssets[] = $asset;
            }
        }

        $profileMeta = [];
        foreach ($settings->profiles as $handle => $config) {
            if (!is_string($handle) || !is_array($config)) {
                continue;
            }

            $variantsConfig = $config['variants'] ?? [];
            $variants = is_array($variantsConfig)
                ? array_values(array_map('strval', array_keys($variantsConfig)))
                : [];

            $formatsConfig = $config['formats'] ?? [];
            $formats = [];
            if (is_array($formatsConfig)) {
                foreach ($formatsConfig as $format) {
                    if (!is_string($format) || $format === '') {
                        continue;
                    }
                    $formats[] = strtolower($format) === 'jpeg' ? 'jpg' : strtolower($format);
                }
                $formats = array_values(array_unique($formats));
            }

            $profileMeta[$handle] = [
                'label' => $handle,
                'variants' => $variants,
                'formats' => $formats,
                'unitCount' => count($variants) * count($formats),
            ];
        }

        return [
            'settings' => $settings,
            'profiles' => $profileMeta,
            'profileSelectOptions' => $this->profileSelectOptions($profileMeta),
            'posted' => $posted,
            'selectedAssets' => $selectedAssets,
            'assetElementType' => Asset::class,
            'result' => null,
            'error' => null,
        ];
    }

    private function resolveAssetId(mixed $value): int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return (int) $value;
    }

    /**
     * @param array<string, array{label: string, variants: list<string>, formats: list<string>, unitCount: int}> $profiles
     * @return list<array{label: string, value: string}>
     */
    private function profileSelectOptions(array $profiles): array
    {
        $options = [];
        foreach ($profiles as $handle => $profile) {
            $formatLabel = $profile['formats'] !== []
                ? implode(', ', $profile['formats'])
                : '—';
            $options[] = [
                'label' => sprintf(
                    '%s (%d images · %d variants · %s)',
                    $handle,
                    $profile['unitCount'],
                    count($profile['variants']),
                    $formatLabel,
                ),
                'value' => $handle,
            ];
        }

        return $options;
    }
}