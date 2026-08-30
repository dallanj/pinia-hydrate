# Laravel Pinia Hydrate

A small, explicit bridge for producing Pinia state in Laravel and applying it
in Vue 3. This monorepo contains the Composer package
`dallanj/laravel-pinia-hydrate` and npm package
`@dallanj/pinia-hydrate`.

## Payload schema

Both packages share this versioned JSON-compatible envelope:

```json
{
  "version": 1,
  "modules": {
    "cart": { "mode": "patch", "state": { "items": [] } },
    "session": { "mode": "replace", "state": { "user": null } }
  }
}
```

Store names are application-defined registry keys. `state` must normalize to a
JSON object. `mode` is `patch` (merge with `$patch`) or `replace` (assign to
`$state`). Unknown names are rejected by JavaScript. This explicit contract
does not inspect Pinia internals.

## Laravel

```bash
composer require dallanj/laravel-pinia-hydrate
```

Publish the configuration and register modules explicitly:

```bash
php artisan vendor:publish --tag=pinia-hydrate-config
```

```php
use Dallanj\PiniaHydrate\Facades\PiniaHydrate;

// config/pinia-hydrate.php
'modules' => [
    'dashboard' => App\PiniaHydrators\DashboardHydrator::class,
],

PiniaHydrate::load('dashboard', [
    'stats',
    'storage',
    'activity' => ['limit' => 8],
    'processing',
]);

return Inertia::render('Dashboard', [
    '$pinia' => PiniaHydrate::toJson(),
]);
```

A module hydrator is a class whose public methods produce named pieces of store state.
Requested methods are invoked through Laravel's container, so method arguments
may combine supplied values with injected services. Results can be arrays,
objects, `JsonSerializable`, `Arrayable`, Laravel JSON resources, or resource
collections. `HydrationFactory` is scoped for Octane safety; only the explicit
`ModuleRegistry` is a singleton. There is no filesystem scanning.

Generate a module hydrator, with an optional matching TypeScript store:

```bash
php artisan make:pinia-hydrator cart
php artisan make:pinia-hydrator cart --store
```

This creates `app/PiniaHydrators/CartHydrator.php` and, with `--store`,
`resources/js/stores/cart.ts`. It does not register or discover the new module.
The command prints the config and JavaScript store-map entries to add. Existing
files are protected unless `--force` is passed.

`PiniaHydrate::load()` executes immediately by default. Pass `lazy: true` or use
`PiniaHydrate::lazy()` to defer queries until serialization. `toJson()` is suited
to Inertia props; `toApiResponse()` returns `{ "$pinia": payload }` plus any
additional response data. `flush()` clears the current request's state.

## Vue / Pinia

```bash
npm install @dallanj/pinia-hydrate pinia vue
```

```ts
import { createPiniaHydrator } from '@dallanj/pinia-hydrate'
import { useCartStore } from './stores/cart'

const hydrate = createPiniaHydrator({ cart: useCartStore })
hydrate(inertiaProps, { resetMissing: true }) // full visit
hydrate(axiosResponse.data) // partial update; omitted stores remain unchanged
```

The npm package also exports `createPiniaResponseInterceptor()` and
`watchInertiaHydration()` for the Axios-response and Inertia-visit wiring.
Inertia and Axios remain optional peer integrations, not dependencies. See the
[JavaScript package guide](packages/javascript/README.md) for complete setup.

## Local installation

Use Composer's path repository for the PHP package:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../pinia-hydrate",
      "options": { "symlink": true }
    }
  ]
}
```

```bash
composer require dallanj/laravel-pinia-hydrate:@dev
```

Install the JavaScript workspace locally after building it:

```bash
cd ../pinia-hydrate
npm run build --prefix packages/javascript
cd ../record-and-translate
npm install ../pinia-hydrate/packages/javascript
```

These commands are documentation only. This repository has not been installed
into or linked with `record-and-translate`.

## Development

```bash
composer install
npm install
composer test
npm run build --prefix packages/javascript
```

Requires PHP 8.2+, Laravel 11, 12, or 13, Vue 3, and Pinia 2 or 3.
Laravel 13 itself requires PHP 8.3+.
