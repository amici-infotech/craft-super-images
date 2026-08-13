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
use amici\SuperImages\twig\SuperImagesTwigExtension;
use amici\SuperImages\variables\SuperImagesVariable;
use Craft;
use craft\base\Model;
use craft\base\Plugin as CraftPlugin;
use craft\console\Application as ConsoleApplication;
use craft\web\twig\variables\CraftVariable;
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
     * @var Plugin|null Singleton plugin instance.
     */
    public static ?Plugin $plugin = null;

    /**
     * @var string Database schema version.
     */
    public string $schemaVersion = '5.0.0';

    /**
     * @var bool CP settings UI arrives in Phase 3.
     */
    public bool $hasCpSettings = false;

    /**
     * @var bool CP section arrives in Phase 3.
     */
    public bool $hasCpSection = false;

    /**
     * Initializes the plugin and registers core engine components.
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

        Craft::info(
            Craft::t('super-images', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    /**
     * Creates the plugin settings model.
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Registers Twig variable + filters for Phase 1 template testing.
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

        Craft::$app->getView()->registerTwigExtension(new SuperImagesTwigExtension());
    }
}
