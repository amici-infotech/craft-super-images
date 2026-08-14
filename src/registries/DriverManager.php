<?php

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
 */
class DriverManager extends Component
{
    public const EVENT_REGISTER_DRIVERS = 'registerDrivers';

    /** @var array<string, ImageDriverInterface> */
    private array $_drivers = [];

    /** @var list<string> */
    private array $_fallbackOrder = ['libvips', 'imagick', 'gd'];

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

    public function register(ImageDriverInterface $driver): void
    {
        $this->_drivers[$driver->name()] = $driver;
    }

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
     * @return list<ImageDriverInterface>
     */
    public function all(): array
    {
        return array_values($this->_drivers);
    }
}
