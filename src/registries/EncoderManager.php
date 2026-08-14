<?php

namespace amici\SuperImages\registries;

use amici\SuperImages\contracts\EncoderInterface;
use amici\SuperImages\encoders\NativeDriverEncoder;
use amici\SuperImages\events\RegisterEncodersEvent;
use amici\SuperImages\exceptions\EncoderUnavailableException;
use yii\base\Component;

/**
 * Encoder Manager
 */
class EncoderManager extends Component
{
    public const EVENT_REGISTER_ENCODERS = 'registerEncoders';

    /** @var array<string, EncoderInterface> */
    private array $_encoders = [];

    public function registerDefaults(): void
    {
        $this->register(new NativeDriverEncoder());

        $event = new RegisterEncodersEvent();
        $this->trigger(self::EVENT_REGISTER_ENCODERS, $event);

        foreach ($event->encoders as $encoder) {
            $this->register($encoder);
        }
    }

    public function register(EncoderInterface $encoder): void
    {
        foreach ($encoder->formats() as $format) {
            $this->_encoders[strtolower($format)] = $encoder;
        }
    }

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
