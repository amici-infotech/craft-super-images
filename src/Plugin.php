<?php
/**
 * Super Images plugin for Craft CMS 5.x
 *
 * Image processing infrastructure: transforms, formats, optimization, storage.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages;

use amici\SuperImages\base\PluginTrait;
use amici\SuperImages\models\Settings;
use amici\SuperImages\variables\SuperImagesVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin as CraftPlugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use Psr\Log\LogLevel;
use yii\base\Event;

/**
 * Super Images Plugin
 *
 * @author    Amici Infotech
 * @package   SuperImages
 * @since     5.0.0
 *
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends CraftPlugin
{
    use PluginTrait;

    /**
     * Singleton plugin instance set during {@see init()}.
     *
     * @var Plugin|null
     */
    public static ?Plugin $plugin = null;

    /**
     * Database schema version for plugin migrations.
     *
     * @var string
     */
    public string $schemaVersion = '5.0.0';

    /**
     * Whether the plugin exposes a settings model in the Craft plugin store UI.
     *
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * Whether the plugin registers a dedicated Control Panel section.
     *
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * Initializes the plugin and registers core engine components.
     *
     * @return void
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'amici\SuperImages\console\controllers';
        }

        $this->_setPluginComponents();
        $this->_registerDefaultRegistries();
        $this->_registerTwig();
        $this->_registerAssetEvents();
        $this->_registerCpRoutes();
        $this->_registerPermissions();
        $this->_registerLogTarget();

        Craft::info(
            Craft::t('super-images', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    /**
     * Creates the plugin settings model populated from `config/super-images.php`.
     *
     * @return Settings|null The settings model instance.
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Redirects Craft plugin settings access to the Super Images settings page.
     *
     * @return mixed HTTP redirect response.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('super-images/settings'));
    }

    /**
     * Builds the Control Panel navigation item and subnavigation.
     *
     * Subnav entries are filtered by user permissions.
     *
     * @return array<string, mixed>|null CP nav item definition, or null when unavailable.
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }

        $item['label'] = Craft::t('super-images', 'Super Images');
        $item['url'] = 'super-images';

        $user = Craft::$app->getUser();

        if ($user->checkPermission('super-images:view')) {
            $item['subnav']['dashboard'] = [
                'label' => Craft::t('super-images', 'Dashboard'),
                'url' => 'super-images',
            ];
            $item['subnav']['encoders'] = [
                'label' => Craft::t('super-images', 'Encoders & Optimizers'),
                'url' => 'super-images/encoders',
            ];
        }

        if ($user->checkPermission('super-images:playground')) {
            $item['subnav']['playground'] = [
                'label' => Craft::t('super-images', 'Playground'),
                'url' => 'super-images/playground',
            ];
        }

        if ($user->checkPermission('super-images:diagnostics')) {
            $item['subnav']['diagnostics'] = [
                'label' => Craft::t('super-images', 'Diagnostics'),
                'url' => 'super-images/diagnostics',
            ];
        }

        if ($user->checkPermission('super-images:manage-settings')) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('super-images', 'Settings'),
                'url' => 'super-images/settings',
            ];
        }

        return $item;
    }

    /**
     * Returns the SVG mask icon path used by the Craft CP navigation.
     *
     * @return string|null Absolute path to `icon-mask.svg`, or null when not used.
     */
    protected function cpNavIconPath(): ?string
    {
        return $this->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg';
    }

    /**
     * Registers the Twig variable (`craft.superImages`).
     *
     * @return void
     */
    private function _registerTwig(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function (Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('superImages', SuperImagesVariable::class);
            }
        );
    }

    /**
     * Enqueues eager generation after asset saves when auto-generate is enabled.
     *
     * @return void
     */
    private function _registerAssetEvents(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_SAVE,
            static function (ModelEvent $event): void {
                $asset = $event->sender;

                if (!$asset instanceof Asset) {
                    return;
                }

                Plugin::getInstance()->getAutoGenerate()->handleAfterSave($asset, $event->isNew);
            }
        );

        Event::on(
            Asset::class,
            Asset::EVENT_BEFORE_DELETE,
            static function (Event $event): void {
                $asset = $event->sender;

                if (!$asset instanceof Asset) {
                    return;
                }

                $settings = Plugin::getInstance()->getSettings();
                $cleanupPolicy = $settings->policies['cleanup'] ?? [];

                if (!($cleanupPolicy['onAssetDelete'] ?? true)) {
                    return;
                }

                Plugin::getInstance()->getCleanup()->purgeAssetDerivatives((int) $asset->id);
            }
        );
    }

    /**
     * Registers Super Images Control Panel URL rules.
     *
     * @return void
     */
    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function (RegisterUrlRulesEvent $event): void {
                $event->rules = array_merge($event->rules, [
                    'super-images' => 'super-images/dashboard/index',
                    'super-images/playground' => 'super-images/playground/index',
                    'super-images/playground/generate' => 'super-images/playground/generate',
                    'super-images/diagnostics' => 'super-images/diagnostics/index',
                    'super-images/settings' => 'super-images/settings/index',
                    'super-images/encoders' => 'super-images/encoders/index',
                ]);
            }
        );
    }

    /**
     * Registers Super Images user permissions.
     *
     * @return void
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('super-images', 'Super Images'),
                    'permissions' => [
                        'super-images:view' => [
                            'label' => Craft::t('super-images', 'View Super Images'),
                        ],
                        'super-images:playground' => [
                            'label' => Craft::t('super-images', 'Use Playground'),
                        ],
                        'super-images:diagnostics' => [
                            'label' => Craft::t('super-images', 'View diagnostics'),
                        ],
                        'super-images:manage-settings' => [
                            'label' => Craft::t('super-images', 'Manage settings'),
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Write category `super-images` warnings/errors to storage/logs/super-images.log.
     *
     * @return void
     */
    private function _registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'super-images',
            'categories' => ['super-images'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
        ]);
    }
}
