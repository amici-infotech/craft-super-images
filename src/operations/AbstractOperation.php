<?php

namespace amici\SuperImages\operations;

use amici\SuperImages\contracts\ImageDriverInterface;
use amici\SuperImages\contracts\OperationInterface;
use amici\SuperImages\models\ImageHandle;
use amici\SuperImages\models\OperationDefinition;

abstract class AbstractOperation implements OperationInterface
{
    /** @var array<string, mixed> */
    protected array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = OperationDefinition::normalizeOptions($options);
    }

    public function options(): array
    {
        return $this->options;
    }

    public function supports(ImageHandle $handle, ImageDriverInterface $driver): bool
    {
        return $driver->supports($this->name());
    }

    abstract public function name(): string;

    abstract public function apply(ImageHandle $handle, ImageDriverInterface $driver): ImageHandle;
}
