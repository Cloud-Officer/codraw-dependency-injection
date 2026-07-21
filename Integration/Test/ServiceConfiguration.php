<?php

namespace Draw\Component\DependencyInjection\Integration\Test;

class ServiceConfiguration
{
    private $definitionCheckCallback;

    public function __construct(private string $id, private array $aliases, ?callable $definitionCheckCallback = null)
    {
        $this->definitionCheckCallback = $definitionCheckCallback;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getDefinitionCheckCallback(): ?callable
    {
        return $this->definitionCheckCallback;
    }
}
