<?php

namespace Dallanj\PiniaHydrate;

use Dallanj\PiniaHydrate\Contracts\Hydrator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Collects server state for Pinia modules during the current Laravel request.
 *
 * Module hydrators are registered explicitly in config/pinia-hydrate.php. Each
 * selected public method is invoked through Laravel's container, and its return
 * value is stored under that method name in the module's state.
 *
 * This class is mutable and must remain scoped in the service container.
 */
final class HydrationFactory implements Hydrator
{
    /** @var array<string, array{mode: string, state: array<string, mixed>}> */
    private array $modules = [];

    /** @var array<string, array{mode: string, methods: array<string, callable>}> */
    private array $lazyModules = [];

    public function __construct(
        private Container $container,
        private ModuleRegistry $registry,
        private StateNormalizer $normalizer,
    ) {}

    /**
     * Hydrate selected methods from a registered module hydrator.
     *
     * Numeric entries select methods without arguments. Associative entries
     * map method names to scalar, positional, or named arguments.
     *
     * @param  string|list<string>|array<string, mixed>  $methods
     * @param  mixed  $arguments  Arguments used when $methods is a string
     * @param  bool  $lazy  Defer method execution until the payload is serialized
     * @param  'patch'|'replace'  $mode
     *
     * @throws InvalidArgumentException
     */
    public function load(
        string $module,
        string|array $methods = 'all',
        mixed $arguments = [],
        bool $lazy = false,
        string $mode = 'patch',
    ): self {
        $this->assertMode($mode);
        $selectedMethods = $this->selectedMethods($methods, $arguments);

        if ($lazy) {
            foreach ($selectedMethods as $method => $parameters) {
                $this->lazyModules[$module]['mode'] = $mode;
                $this->lazyModules[$module]['methods'][$method] = fn (): mixed => $this->invoke(
                    $module,
                    $method,
                    $parameters,
                );
            }

            return $this;
        }

        foreach ($selectedMethods as $method => $parameters) {
            $this->put($module, $method, $this->invoke($module, $method, $parameters), $mode);
        }

        return $this;
    }

    /**
     * Defer selected module methods until the payload is serialized.
     *
     * @param  string|list<string>|array<string, mixed>  $methods
     * @param  'patch'|'replace'  $mode
     */
    public function lazy(
        string $module,
        string|array $methods = 'all',
        mixed $arguments = [],
        string $mode = 'patch',
    ): self {
        return $this->load($module, $methods, $arguments, true, $mode);
    }

    /**
     * Hydrate a module in replace mode.
     *
     * @param  string|list<string>|array<string, mixed>  $methods
     */
    public function replace(string $module, string|array $methods = 'all', mixed $arguments = []): self
    {
        return $this->load($module, $methods, $arguments, false, 'replace');
    }

    /**
     * Add already-resolved state without invoking a module hydrator.
     *
     * @param  'patch'|'replace'  $mode
     */
    public function module(string $module, mixed $state, string $mode = 'patch'): self
    {
        $this->assertMode($mode);
        $this->modules[$module] = [
            'mode' => $mode,
            'state' => array_replace($this->modules[$module]['state'] ?? [], $this->normalizer->state($state)),
        ];

        return $this;
    }

    /**
     * Resolve lazy methods and return the shared hydration payload.
     *
     * @return array{version: 1, modules: array<string, mixed>}
     */
    public function toArray(): array
    {
        $this->resolveLazyModules();

        $payload = ['version' => 1, 'modules' => []];
        foreach ($this->modules as $module => $data) {
            $this->addNamespacedModule($payload['modules'], $module, $data);
        }

        return $payload;
    }

    /**
     * Resolve the hydration payload as JSON for an Inertia prop or response.
     *
     * @throws \JsonException
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | $options);
    }

    /**
     * Return the payload under "$pinia" alongside optional API response data.
     *
     * @param  array<string, mixed>  $additional
     * @param  array<string, string>  $headers
     */
    public function toApiResponse(array $additional = [], int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(['$pinia' => $this->toArray(), ...$additional], $status, $headers);
    }

    /** Clear all eager and lazy state collected for the current request. */
    public function flush(): self
    {
        $this->modules = [];
        $this->lazyModules = [];

        return $this;
    }

    /** @return array<string, array<int|string, mixed>> */
    private function selectedMethods(string|array $methods, mixed $arguments): array
    {
        if (is_string($methods)) {
            return [$methods => $this->wrapArguments($arguments)];
        }

        $selected = [];
        foreach ($methods as $key => $value) {
            $method = is_int($key) ? $value : $key;
            if (! is_string($method) || $method === '') {
                throw new InvalidArgumentException('Hydrator method names must be non-empty strings.');
            }

            $selected[$method] = $this->wrapArguments(is_int($key) ? [] : $value);
        }

        return $selected;
    }

    /** @return array<int|string, mixed> */
    private function wrapArguments(mixed $arguments): array
    {
        if ($arguments === null) {
            return [];
        }

        return is_array($arguments) ? $arguments : [$arguments];
    }

    /** @param array<int|string, mixed> $arguments */
    private function invoke(string $module, string $method, array $arguments): mixed
    {
        $hydrator = $this->container->make($this->registry->get($module));

        if (! is_callable([$hydrator, $method])) {
            throw new InvalidArgumentException("Public method [{$method}] does not exist on module hydrator [{$module}].");
        }

        return $this->container->call([$hydrator, $method], $this->namedArguments($hydrator, $method, $arguments));
    }

    /**
     * Convert positional application arguments to names so Laravel can still
     * inject unsupplied class dependencies into a hydrator method.
     *
     * @param  array<int|string, mixed>  $arguments
     * @return array<int|string, mixed>
     */
    private function namedArguments(object $hydrator, string $method, array $arguments): array
    {
        if (! array_is_list($arguments)) {
            return $arguments;
        }

        $named = [];
        foreach ((new ReflectionMethod($hydrator, $method))->getParameters() as $parameter) {
            if ($arguments === []) {
                break;
            }

            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $class = $type->getName();
                if (! $arguments[0] instanceof $class) {
                    continue;
                }
            }

            $named[$parameter->getName()] = array_shift($arguments);
        }

        if ($arguments !== []) {
            throw new InvalidArgumentException("Too many arguments supplied for hydrator method [{$method}].");
        }

        return $named;
    }

    private function resolveLazyModules(): void
    {
        foreach ($this->lazyModules as $module => $data) {
            foreach ($data['methods'] as $method => $resolve) {
                $this->put($module, $method, $resolve(), $data['mode']);
            }
        }

        $this->lazyModules = [];
    }

    private function put(string $module, string $key, mixed $value, string $mode): void
    {
        $this->modules[$module]['mode'] = $mode;
        $this->modules[$module]['state'][$key] = $this->normalizer->value($value);
    }

    /** @param array<string, mixed> $modules */
    private function addNamespacedModule(array &$modules, string $module, array $data): void
    {
        $segments = explode('/', trim($module, '/'));
        $leaf = array_pop($segments);
        $cursor = &$modules;

        foreach ($segments as $segment) {
            $cursor[$segment]['modules'] ??= [];
            $cursor = &$cursor[$segment]['modules'];
        }

        $cursor[$leaf] = $data;
    }

    private function assertMode(string $mode): void
    {
        if (! in_array($mode, ['patch', 'replace'], true)) {
            throw new InvalidArgumentException("Unknown hydration mode [{$mode}].");
        }
    }
}
