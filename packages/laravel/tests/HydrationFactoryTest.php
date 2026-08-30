<?php

namespace Dallanj\PiniaHydrate\Tests;

use Dallanj\PiniaHydrate\Contracts\Hydrator;
use Dallanj\PiniaHydrate\Facades\PiniaHydrate;
use Dallanj\PiniaHydrate\HydrationFactory;
use Dallanj\PiniaHydrate\ModuleRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Artisan;

final class HydrationFactoryTest extends TestCase
{
    public function test_it_loads_selected_methods_through_the_container(): void
    {
        DashboardHydrator::$calls = 0;
        $this->app->make(ModuleRegistry::class)->register('dashboard', DashboardHydrator::class);

        PiniaHydrate::load('dashboard', [
            'stats',
            'activity' => ['limit' => 3],
        ]);

        $this->assertSame(2, DashboardHydrator::$calls);
        $this->assertSame([
            'version' => 1,
            'modules' => [
                'dashboard' => [
                    'mode' => 'patch',
                    'state' => [
                        'stats' => ['count' => 2],
                        'activity' => ['limit' => 3],
                    ],
                ],
            ],
        ], PiniaHydrate::toArray());
    }

    public function test_lazy_methods_execute_only_when_serialized_and_only_once(): void
    {
        DashboardHydrator::$calls = 0;
        $this->app->make(ModuleRegistry::class)->register('dashboard', DashboardHydrator::class);

        PiniaHydrate::load('dashboard', ['stats', 'activity' => 5], lazy: true);
        $this->assertSame(0, DashboardHydrator::$calls);
        $this->assertJson(PiniaHydrate::toJson());
        $this->assertSame(2, DashboardHydrator::$calls);
        PiniaHydrate::toArray();
        $this->assertSame(2, DashboardHydrator::$calls);
    }

    public function test_it_supports_replace_reset_and_api_response_helpers(): void
    {
        $this->app->make(ModuleRegistry::class)->register('dashboard', DashboardHydrator::class);

        PiniaHydrate::replace('dashboard', 'stats');
        $this->assertSame('replace', PiniaHydrate::toArray()['modules']['dashboard']['mode']);
        $this->assertSame(
            ['$pinia' => ['version' => 1, 'modules' => []], 'ok' => true],
            PiniaHydrate::flush()->toApiResponse(['ok' => true])->getData(true),
        );
    }

    public function test_it_supports_positional_arguments_container_injection_and_nested_modules(): void
    {
        $this->app->make(ModuleRegistry::class)->register('admin/dashboard', DashboardHydrator::class);

        PiniaHydrate::load('admin/dashboard', 'injected', 4);

        $state = PiniaHydrate::toArray()['modules']['admin']['modules']['dashboard']['state'];
        $this->assertSame(['total' => 8], $state['injected']);
    }

    public function test_it_normalizes_resources_and_builds_json_responses(): void
    {
        $resource = new JsonResource(['id' => 7]);
        $hydrator = $this->app->make(Hydrator::class)->module('user', $resource, 'replace');
        $this->assertSame(['id' => 7], $hydrator->toArray()['modules']['user']['state']);
        $this->assertJson($hydrator->toJson());
    }

    public function test_hydrators_are_scoped_and_registry_is_singleton(): void
    {
        $first = $this->app->make(Hydrator::class);
        $this->app->forgetScopedInstances();
        $second = $this->app->make(Hydrator::class);
        $this->assertNotSame($first, $second);
        $this->assertSame($this->app->make(ModuleRegistry::class), $this->app->make(ModuleRegistry::class));
        $this->assertInstanceOf(HydrationFactory::class, $second);
    }

    public function test_the_generator_creates_a_hydrator_and_optional_store_without_registering_it(): void
    {
        $hydrator = app_path('PiniaHydrators/CartHydrator.php');
        $store = resource_path('js/stores/cart.ts');
        $files = $this->app->make(Filesystem::class);
        $files->delete([$hydrator, $store]);

        try {
            $this->assertSame(0, Artisan::call('make:pinia-hydrator', ['name' => 'cart', '--store' => true]));
            $this->assertFileExists($hydrator);
            $this->assertFileExists($store);
            $this->assertStringContainsString('final class CartHydrator', file_get_contents($hydrator));
            $this->assertStringContainsString("defineStore('cart'", file_get_contents($store));
            $this->assertFalse($this->app->make(ModuleRegistry::class)->has('cart'));
            $this->assertSame(1, Artisan::call('make:pinia-hydrator', ['name' => 'cart']));
        } finally {
            $files->delete([$hydrator, $store]);
        }
    }
}

final class DashboardHydrator
{
    public static int $calls = 0;

    public function __construct(private HydratorDependency $dependency) {}

    public function stats(): object
    {
        self::$calls++;

        return (object) ['count' => $this->dependency->count];
    }

    public function activity(int $limit): array
    {
        self::$calls++;

        return ['limit' => $limit];
    }

    public function injected(HydratorDependency $dependency, int $multiplier): array
    {
        return ['total' => $dependency->count * $multiplier];
    }
}

final class HydratorDependency
{
    public int $count = 2;
}
