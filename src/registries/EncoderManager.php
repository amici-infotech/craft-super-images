<?php
/**
 * Registry and selector for image encoders.
 *
 * @link      https://amiciinfotech.com
 * @copyright Copyright (c) 2026 Amici Infotech
 */

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\EncoderInterface;
use amici\SuperImages\encoders\NativeDriverEncoder;
use amici\SuperImages\events\RegisterEncodersEvent;
use amici\SuperImages\exceptions\EncoderUnavailableException;
use yii\base\Component;

/**
 * Encoder Manager
 *
 * Registers encoders by supported format and selects the appropriate encoder
 * when writing derivative output from an image handle.
 */
class EncoderManager extends Component
{
    /**
     * Event fired after the native encoder is registered so plugins can add custom encoders.
     */
    public const EVENT_REGISTER_ENCODERS = 'registerEncoders';

    /**
     * Map of lowercase format name to encoder instance.
     *
     * @var array<string, EncoderInterface>
     */
    private array $_encoders = [];

    /**
     * Register the native driver encoder and trigger the register event for extensions.
     *
     * @return void
     */
    public function registerDefaults(): void
    {
        $this->register(new NativeDriverEncoder());

        $event = new RegisterEncodersEvent();
        $this->trigger(self::EVENT_REGISTER_ENCODERS, $event);

        foreach ($event->encoders as $encoder) {
            $this->register($encoder);
        }
    }

    /**
     * Register an encoder for each format it supports.
     *
     * @param EncoderInterface $encoder The encoder implementation.
     *
     * @return void
     */
    public function register(EncoderInterface $encoder): void
    {
        foreach ($encoder->formats() as $format) {
            $this->_encoders[strtolower($format)] = $encoder;
        }
    }

    /**
     * Select an encoder for the requested output format.
     *
     * @param string $format Output format (jpg is normalized to jpeg).
     *
     * @return EncoderInterface The encoder that supports the requested format.
     *
     * @throws EncoderUnavailableException When no registered encoder supports the format.
     */
    public function select(string $format): EncoderInterface
    {
        $format = strtolower($format);
        $format = $format === 'jpg' ? 'jpeg' : $format;

        $encoder = $this->_encoders[$format] ?? $this->_encoders['jpeg'] ?? null;

        if ($encoder === null || !$encoder->supports($format)) {
            throw new EncoderUnavailableException(sprintf('No encoder available for format "%s".', $format));
        }

        return $encoder;
    }
}
