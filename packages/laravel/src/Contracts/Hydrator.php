<?php

namespace Dallanj\PiniaHydrate\Contracts;

use Illuminate\Http\JsonResponse;

/** Public contract behind the PiniaHydrate facade. */
interface Hydrator
{
    /** @param string|list<string>|array<string, mixed> $methods */
    public function load(
        string $module,
        string|array $methods = 'all',
        mixed $arguments = [],
        bool $lazy = false,
        string $mode = 'patch',
    ): self;

    /** @param string|list<string>|array<string, mixed> $methods */
    public function lazy(
        string $module,
        string|array $methods = 'all',
        mixed $arguments = [],
        string $mode = 'patch',
    ): self;

    /** @param string|list<string>|array<string, mixed> $methods */
    public function replace(string $module, string|array $methods = 'all', mixed $arguments = []): self;

    public function module(string $module, mixed $state, string $mode = 'patch'): self;

    /** @return array{version: 1, modules: array<string, mixed>} */
    public function toArray(): array;

    public function toJson(int $options = 0): string;

    /**
     * @param  array<string, mixed>  $additional
     * @param  array<string, string>  $headers
     */
    public function toApiResponse(array $additional = [], int $status = 200, array $headers = []): JsonResponse;

    public function flush(): self;
}
