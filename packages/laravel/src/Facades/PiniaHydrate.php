<?php

namespace Dallanj\PiniaHydrate\Facades;

use Dallanj\PiniaHydrate\Contracts\Hydrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Hydrator load(string $module, string|array $methods = 'all', mixed $arguments = [], bool $lazy = false, string $mode = 'patch')
 * @method static Hydrator lazy(string $module, string|array $methods = 'all', mixed $arguments = [], string $mode = 'patch')
 * @method static Hydrator replace(string $module, string|array $methods = 'all', mixed $arguments = [])
 * @method static Hydrator module(string $module, mixed $state, string $mode = 'patch')
 * @method static array toArray()
 * @method static string toJson(int $options = 0)
 * @method static JsonResponse toApiResponse(array $additional = [], int $status = 200, array $headers = [])
 * @method static Hydrator flush()
 *
 * @see Hydrator
 */
final class PiniaHydrate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Hydrator::class;
    }
}
