<?php

namespace Dallanj\PiniaHydrate;

use InvalidArgumentException;

/**
 * Stores the explicit mapping of Pinia module names to application hydrators.
 *
 * The registry contains no request state and is safe to register as a singleton.
 */
final class ModuleRegistry
{
    /** @param array<string, class-string> $modules */
    public function __construct(private array $modules = []) {}

    /**
     * Register or replace one module mapping.
     *
     * @param  class-string  $hydrator
     */
    public function register(string $name, string $hydrator): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('A module name cannot be empty.');
        }
        $this->modules[$name] = $hydrator;

        return $this;
    }

    /** @param array<string, class-string> $modules */
    public function registerMany(array $modules): self
    {
        foreach ($modules as $name => $hydrator) {
            $this->register($name, $hydrator);
        }

        return $this;
    }

    /** @return class-string */
    public function get(string $name): string
    {
        return $this->modules[$name] ?? throw new InvalidArgumentException("No Pinia module hydrator is registered as [{$name}].");
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->modules);
    }

    /** @return array<string, class-string> */
    public function all(): array
    {
        return $this->modules;
    }
}
