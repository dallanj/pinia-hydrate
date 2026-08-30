<?php

namespace Dallanj\PiniaHydrate\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

final class MakePiniaHydratorCommand extends Command
{
    protected $signature = 'make:pinia-hydrator
        {name : Store/module name, for example cart or Admin/Users}
        {--store : Also generate a Pinia store}
        {--force : Overwrite existing files}';

    protected $description = 'Create a Pinia module hydrator and optionally its Pinia store';

    public function handle(Filesystem $files): int
    {
        try {
            $name = $this->normalizedName((string) $this->argument('name'));
            $segments = explode('/', $name);
            $class = Str::studly(array_pop($segments));
            $module = Str::kebab($name);
            $subdirectory = implode('/', array_map([Str::class, 'studly'], $segments));
            $namespace = 'App\\PiniaHydrators'.($subdirectory === '' ? '' : '\\'.str_replace('/', '\\', $subdirectory));
            $hydratorPath = app_path('PiniaHydrators/'.($subdirectory === '' ? '' : $subdirectory.'/').$class.'Hydrator.php');

            $this->write($files, $hydratorPath, $this->hydratorStub($namespace, $class), 'Hydrator');

            if ($this->option('store')) {
                $storePath = resource_path('js/stores/'.Str::kebab($name).'.ts');
                $this->write($files, $storePath, $this->storeStub($class, $module), 'Store');
            }
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $hydratorClass = $namespace.'\\'.$class.'Hydrator';
        $this->newLine();
        $this->components->info('Register the module explicitly in config/pinia-hydrate.php:');
        $this->line("'{$module}' => \\{$hydratorClass}::class,");

        if ($this->option('store')) {
            $this->components->info("Add '{$module}' => use{$class}Store to createPiniaHydrator({...}).");
        }

        return self::SUCCESS;
    }

    private function normalizedName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');

        if ($name === '' || str_contains($name, '..')) {
            throw new RuntimeException('The module name must be a non-empty relative name.');
        }

        return $name;
    }

    private function write(Filesystem $files, string $path, string $contents, string $label): void
    {
        if ($files->exists($path) && ! $this->option('force')) {
            throw new RuntimeException("{$label} already exists: {$path}");
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $contents);
        $this->components->info("{$label} created: {$path}");
    }

    private function hydratorStub(string $namespace, string $class): string
    {
        return <<<PHP
<?php

namespace {$namespace};

final class {$class}Hydrator
{
    public function all(): array
    {
        return [
            // Return JSON-serializable state for the matching Pinia store.
        ];
    }
}

PHP;
    }

    private function storeStub(string $class, string $module): string
    {
        return <<<TS
import { defineStore } from 'pinia'

export const use{$class}Store = defineStore('{$module}', {
  state: () => ({
    // Define state shared with {$class}Hydrator here.
  }),
})

TS;
    }
}
