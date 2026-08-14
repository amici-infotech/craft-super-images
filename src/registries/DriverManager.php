<?php
/**
 * Registry and selector for image processing drivers.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\drivers\GdDriver;
use amici\SuperImages\drivers\ImagickDriver;
use amici\SuperImages\drivers\LibvipsDriver;
use amici\SuperImages\events\RegisterDriversEvent;
use amici\SuperImages\exceptions\DriverUnavailableException;
use yii\base\Component;

/**
 * Driver Manager
 *
 * Registers GD, Imagick, and libvips drivers and selects an available driver
 * based on explicit preference or automatic fallback order.
 */
class DriverManager extends Component
{
    /**
     * Event fired after built-in drivers are registered so plugins can add custom drivers.
     */
    public const EVENT_REGISTER_DRIVERS = 'registerDrivers';

    /**
     * Registered driver instances keyed by driver name.
     *
     * @var array<string, ImageDriverInterface>
     */
    private array $_drivers = [];

    /**
     * Fallback order used when preference is auto or empty.
     *
     * @var list<string>
     */
    private array $_fallbackOrder = ['libvips', 'imagick', 'gd'];

    /**
     * Register built-in drivers and trigger the register event for extensions.
     *
     * @return void
     */
    public function registerDefaults(): void
    {
        $this->register(new LibvipsDriver());
        $this->register(new ImagickDriver());
        $this->register(new GdDriver());

        $event = new RegisterDriversEvent();
        $this->trigger(self::EVENT_REGISTER_DRIVERS, $event);

        foreach ($event->drivers as $driver) {
            $this->register($driver);
        }
    }

    /**
     * Register an image driver implementation.
     *
     * @param ImageDriverInterface $driver The driver instance to register.
     *
     * @return void
     */
    public function register(ImageDriverInterface $driver): void
    {
        $this->_drivers[$driver->name()] = $driver;
    }

    /**
     * Select an available image driver by preference or automatic fallback.
     *
     * @param string|null $preference Driver name, or auto/null for fallback order.
     *
     * @return ImageDriverInterface The selected available driver.
     *
     * @throws DriverUnavailableException When the requested driver is unavailable or none exist.
     */
    public function select(?string $preference = 'auto'): ImageDriverInterface
    {
        if ($preference !== null && $preference !== '' && $preference !== 'auto') {
            $driver = $this->_drivers[strtolower($preference)] ?? null;
            if ($driver !== null && $driver->isAvailable()) {
                return $driver;
            }

            throw new DriverUnavailableException(sprintf('Requested driver "%s" is unavailable.', $preference));
        }

        foreach ($this->_fallbackOrder as $name) {
            $driver = $this->_drivers[$name] ?? null;
            if ($driver !== null && $driver->isAvailable()) {
                return $driver;
            }
        }

        throw new DriverUnavailableException('No image driver is available.');
    }

    /**
     * Return all registered driver instances.
     *
     * @return list<ImageDriverInterface> Registered drivers in registration order values.
     */
    public function all(): array
    {
        return array_values($this->_drivers);
    }
}
