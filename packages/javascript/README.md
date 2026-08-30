# @dallanj/pinia-hydrate

Hydrate explicitly mapped Pinia stores from Laravel response payloads.

## Installation

```bash
npm install @dallanj/pinia-hydrate pinia vue
```

Vue 3 and Pinia are peer dependencies.

## Usage

```ts
import { createPiniaHydrator } from "@dallanj/pinia-hydrate";
import { useCartStore } from "./stores/cart";
import { useSessionStore } from "./stores/session";

const hydratePinia = createPiniaHydrator({
  cart: useCartStore,
  session: useSessionStore,
});

hydratePinia({
  version: 1,
  modules: {
    cart: {
      mode: "patch",
      state: {
        items: [],
      },
    },
  },
});
```

Direct payloads, JSON strings, Inertia props containing `$pinia` or `pinia`, and
Axios response data are accepted:

```ts
hydratePinia(JSON.stringify(payload));
hydratePinia(page.props, { resetMissing: true });
hydratePinia(response.data);
```

## Hydration modes

- `patch` imports supplied state keys. Unspecified keys remain unchanged.
- `replace` imports supplied state keys and resets keys omitted from the
  payload. Stores with a key-aware `$reset(key)` reset each omitted key;
  standard Pinia stores reset before the supplied keys are imported.

Stores marked with `_isLazyLoaded` preserve omitted keys during hydration.
When a store has no `$reset()`, omitted keys fall back to `null`.

By default, omitted stores are not reset. Pass `{ resetMissing: true }` for a
full page hydration. A mapped store can opt out with
`{ useStore, resetOnFullHydration: false }`.

## Axios example

```ts
const responseInterceptor = [
  (response) => {
    hydratePinia(response.data);
    return response;
  },
];
```

Axios and Inertia are optional and are not package dependencies.

## Axios interceptor

The package includes the equivalent of your application's
`piniaInterceptor.js` without depending on Axios:

```ts
import { createPiniaResponseInterceptor } from "@dallanj/pinia-hydrate";
import { hydratePinia } from "./pinia";

export default {
  response: [createPiniaResponseInterceptor(hydratePinia)],
};
```

Only modules included in the response are patched. Omitted stores are never
reset by the response interceptor.

## Inertia lifecycle

Call `watchInertiaHydration()` after installing Pinia. It performs a full
initial hydration, resets omitted stores after full visits, and preserves them
after partial visits:

```ts
import { createApp, h } from "vue";
import { createPinia } from "pinia";
import { createInertiaApp } from "@inertiajs/vue3";
import {
  createPiniaHydrator,
  watchInertiaHydration,
} from "@dallanj/pinia-hydrate";
import { useDashboardStore } from "./stores/dashboard";

const pinia = createPinia();
const hydratePinia = createPiniaHydrator({
  dashboard: useDashboardStore,
});

createInertiaApp({
  // title and resolve omitted
  setup({ el, App, props, plugin }) {
    let stopHydrationWatch: (() => void) | undefined;
    const app = createApp({
      mounted() {
        stopHydrationWatch = watchInertiaHydration(hydratePinia, {
          router: this.$inertia,
          pinia,
          getProps: () => this.$page.props,
        });
      },
      unmounted() {
        stopHydrationWatch?.();
      },
      render: () => h(App, props),
    });
    app.use(plugin).use(pinia).mount(el);
  },
});
```

If your application already exposes current page props differently, make
`getProps` return that current value. The helper deliberately accepts an
Inertia-shaped router instead of importing Inertia.

## Local installation

Build this package once, then install it from the consuming application:

```bash
cd ../pinia-hydrate
npm run build --prefix packages/javascript

cd ../record-and-translate
npm install ../pinia-hydrate/packages/javascript
```

The equivalent persistent `package.json` entry is:

```json
{
  "dependencies": {
    "@dallanj/pinia-hydrate": "file:../pinia-hydrate/packages/javascript"
  }
}
```

## Payload schema

```ts
interface HydrationPayload {
  version: 1;
  modules: Record<
    string,
    {
      mode: "patch" | "replace";
      state: Record<string, unknown>;
    }
  >;
}
```

Store names must exist in the explicit map passed to `createPiniaHydrator()`.

## Related package

Laravel payloads can be generated with
[`dallanj/laravel-pinia-hydrate`](https://github.com/dallanj/pinia-hydrate/tree/main/packages/laravel).

## License

MIT
