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
 *
 * Interactive CP tool for testing profile generation against a chosen asset.
 */
class PlaygroundController extends Controller
{
    /**
     * Whether anonymous requests are allowed.
     *
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the playground form with default profile selection.
     *
     * @return Response The rendered CP template response.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:playground');

        $settings = Plugin::getInstance()->getSettings();

        return $this->renderTemplate('super-images/playground/index', $this->templateVars($settings, [
            'assetId' => null,
            'profile' => $settings->defaultProfile,
        ]));
    }

    /**
     * Generates all units for a profile against the posted asset.
     *
     * Accepts JSON when the client sends an `Accept: application/json` header.
     *
     * @return Response Rendered template or JSON result/failure response.
     */
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
     * Builds template variables for the playground view.
     *
     * @param Settings $settings Plugin settings.
     * @param array{assetId: ?int, profile: string} $posted Posted form values.
     *
     * @return array<string, mixed> Variables passed to the Twig template.
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

    /**
     * Normalizes a posted asset ID from scalar or array input.
     *
     * @param mixed $value Raw posted asset ID value.
     *
     * @return int Parsed asset ID, or 0 when invalid.
     */
    private function resolveAssetId(mixed $value): int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return (int) $value;
    }

    /**
     * Builds select options for the profile dropdown.
     *
     * @param array<string, array{label: string, variants: list<string>, formats: list<string>, unitCount: int}> $profiles Profile metadata keyed by handle.
     *
     * @return list<array{label: string, value: string}> Select option definitions.
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
