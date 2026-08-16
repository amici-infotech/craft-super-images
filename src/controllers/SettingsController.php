<?php
/**
 * Control Panel settings overview for Super Images.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\controllers;

use amici\SuperImages\Plugin;
use amici\SuperImages\services\StoragePathBuilder;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Settings Controller
 *
 * Overview + naming convention editor. Prefer `config/super-images.php` for durable
 * project settings; CP saves merge into plugin project config when the PHP file
 * does not override `storage.naming`.
 */
class SettingsController extends Controller
{
    /**
     * Whether anonymous requests are allowed.
     *
     * @var array|bool|int
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * Renders the settings overview (with naming editor).
     *
     * @return Response The rendered CP template response.
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('super-images:manage-settings');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $naming = $plugin->getStoragePathBuilder()->resolveNaming(
            is_array($settings->storage['naming'] ?? null) ? $settings->storage['naming'] : null
        );

        return $this->renderTemplate('super-images/settings/index', [
            'settings' => $settings,
            'binaries' => $plugin->getBinaryResolver()->inventory(),
            'drivers' => $plugin->getDriverManager()->all(),
            'naming' => $naming,
            'namingTokens' => StoragePathBuilder::tokenGlossary(),
            'namingExampleAsset' => $plugin->getStoragePathBuilder()->examplePath($naming, true),
            'namingExampleOther' => $plugin->getStoragePathBuilder()->examplePath($naming, false),
            'namingDefaults' => StoragePathBuilder::defaultNaming(),
            'configFileOverridesNaming' => $this->configFileDefinesNaming(),
        ]);
    }

    /**
     * Save storage naming conventions from the CP form.
     *
     * @return Response Redirect back to settings.
     */
    public function actionSaveNaming(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('super-images:manage-settings');

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $request = Craft::$app->getRequest();

        if ($this->configFileDefinesNaming()) {
            Craft::$app->getSession()->setError(Craft::t(
                'super-images',
                'Naming is defined in config/super-images.php — edit that file (CP cannot override it).'
            ));

            return $this->redirectToPostedUrl();
        }

        $assetPath = trim((string) $request->getBodyParam('assetPath', ''));
        $path = trim((string) $request->getBodyParam('path', ''));
        $transformHashLength = (int) $request->getBodyParam('transformHashLength', 16);
        $includeVolume = (bool) $request->getBodyParam('includeVolumeInFolderHash', false);

        $defaults = StoragePathBuilder::defaultNaming();
        $naming = [
            'assetPath' => $assetPath !== '' ? $assetPath : $defaults['assetPath'],
            'path' => $path !== '' ? $path : $defaults['path'],
            'transformHashLength' => max(8, min(64, $transformHashLength)),
            'includeVolumeInFolderHash' => $includeVolume,
        ];

        if (!$this->templateLooksSafe($naming['assetPath']) || !$this->templateLooksSafe($naming['path'])) {
            Craft::$app->getSession()->setError(Craft::t(
                'super-images',
                'Naming templates may only contain path tokens, letters, numbers, / . _ - and braces.'
            ));

            return $this->redirectToPostedUrl();
        }

        if (!str_contains($naming['assetPath'], '{ext}') && !str_contains($naming['assetPath'], '{format}')) {
            Craft::$app->getSession()->setError(Craft::t(
                'super-images',
                'Asset path template must include {ext} (or {format}) so files keep an extension.'
            ));

            return $this->redirectToPostedUrl();
        }

        if (!str_contains($naming['path'], '{ext}') && !str_contains($naming['path'], '{format}')) {
            Craft::$app->getSession()->setError(Craft::t(
                'super-images',
                'Non-asset path template must include {ext} (or {format}) so files keep an extension.'
            ));

            return $this->redirectToPostedUrl();
        }

        $settingsAware = str_contains($naming['assetPath'], '{transformHash}')
            || str_contains($naming['assetPath'], '{identityShort}')
            || str_contains($naming['assetPath'], '{transformFolderHash}')
            || str_contains($naming['assetPath'], '{identity}')
            || str_contains($naming['assetPath'], '{identityShard}');

        $storage = $settings->storage;
        $storage['naming'] = $naming;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, ['storage' => $storage])) {
            Craft::$app->getSession()->setError(Craft::t('super-images', 'Could not save naming settings.'));

            return $this->redirectToPostedUrl();
        }

        if (!$settingsAware) {
            Craft::$app->getSession()->setNotice(Craft::t(
                'super-images',
                'Naming saved. Warning: asset path has no transform/identity token — changing ops may reuse stale files.'
            ));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('super-images', 'Naming conventions saved.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Whether config/super-images.php defines storage.naming (wins over CP).
     *
     * @return bool
     */
    private function configFileDefinesNaming(): bool
    {
        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('super-images');

        return is_array($fileConfig)
            && isset($fileConfig['storage'])
            && is_array($fileConfig['storage'])
            && array_key_exists('naming', $fileConfig['storage']);
    }

    /**
     * Basic safety check for path templates (no `..`, no weird chars).
     *
     * @param string $template Template string.
     *
     * @return bool
     */
    private function templateLooksSafe(string $template): bool
    {
        if ($template === '' || str_contains($template, '..')) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9_\-{}\/.]+$/', $template);
    }
}
