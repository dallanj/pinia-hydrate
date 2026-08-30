import { beforeEach, describe, expect, it } from "vitest";
import { createPinia, defineStore, setActivePinia } from "pinia";
import {
  createPiniaHydrator,
  createPiniaResponseInterceptor,
  watchInertiaHydration,
} from "../src";

const useCart = defineStore("cart", {
  state: () => ({ count: 1, label: "old" }),
});
const useSession = defineStore("session", {
  state: () => ({ user: "initial" }),
});

function addKeyReset<State extends StateTree>(
  store: StoreGeneric,
  initialState: State,
): void {
  store.$reset = (key?: string) => {
    if (key) {
      store[key] = initialState[key];
      return;
    }

    store.$patch(initialState);
  };
}

describe("createPiniaHydrator", () => {
  beforeEach(() => setActivePinia(createPinia()));

  it("patches modules from the PHP payload and accepts Inertia props", () => {
    const hydrate = createPiniaHydrator({ cart: useCart });
    hydrate({
      $pinia: JSON.stringify({
        version: 1,
        modules: { cart: { mode: "patch", state: { count: 3 } } },
      }),
    });
    expect(useCart().$state).toEqual({ count: 3, label: "old" });
  });

  it("resets included and omitted stores during full hydration", () => {
    const cart = useCart();
    const session = useSession();
    addKeyReset(cart, { count: 1, label: "old" });
    addKeyReset(session, { user: "initial" });
    cart.$patch({ count: 8, label: "changed" });
    session.user = "changed";
    const hydrate = createPiniaHydrator({ cart: useCart, session: useSession });
    hydrate(
      { modules: { cart: { state: { count: 9 } } } },
      { resetMissing: true },
    );
    expect(useCart().$state).toEqual({ count: 9, label: "old" });
    expect(useSession().$state).toEqual({ user: "initial" });
  });

  it("resets missing keys for an explicitly replaced module", () => {
    const cart = useCart();
    addKeyReset(cart, { count: 1, label: "old" });
    cart.$patch({ count: 8, label: "changed" });
    const hydrate = createPiniaHydrator({ cart: useCart });

    hydrate({
      modules: { cart: { mode: "replace", state: { count: 9 } } },
    });

    expect(cart.$state).toEqual({ count: 9, label: "old" });
  });

  it("preserves missing keys for partial module hydration", () => {
    const cart = useCart();
    addKeyReset(cart, { count: 1, label: "old" });
    cart.$patch({ count: 8, label: "changed" });
    const hydrate = createPiniaHydrator({ cart: useCart });

    hydrate({ modules: { cart: { state: { count: 9 } } } });

    expect(cart.$state).toEqual({ count: 9, label: "changed" });
  });

  it("preserves missing keys for lazy-loaded stores", () => {
    const cart = useCart() as StoreGeneric & { _isLazyLoaded?: boolean };
    addKeyReset(cart, { count: 1, label: "old" });
    cart.$patch({ count: 8, label: "changed" });
    cart._isLazyLoaded = true;
    const hydrate = createPiniaHydrator({ cart: useCart });

    hydrate(
      { modules: { cart: { state: { count: 9 } } } },
      { resetMissing: true },
    );

    expect(cart.$state).toEqual({ count: 9, label: "changed" });
  });

  it("supports explicitly preserved stores during full hydration", () => {
    useSession().user = "keep";
    const hydrate = createPiniaHydrator({
      cart: useCart,
      session: { useStore: useSession, resetOnFullHydration: false },
    });
    hydrate(
      { modules: { cart: { state: { count: 2 } } } },
      { resetMissing: true },
    );
    expect(useSession().user).toBe("keep");
  });

  it("supports nested modules", () => {
    const useUsers = defineStore("admin-users", {
      state: () => ({ count: 0 }),
    });
    const hydrate = createPiniaHydrator({ "admin/users": useUsers });
    hydrate({
      modules: { admin: { modules: { users: { state: { count: 4 } } } } },
    });
    expect(useUsers().count).toBe(4);
  });

  it("rejects unregistered names and malformed versions", () => {
    const hydrate = createPiniaHydrator({ cart: useCart });
    expect(() => hydrate({ modules: { secret: { state: {} } } })).toThrow(
      "No Pinia store factory",
    );
    expect(() => hydrate({ version: 2, modules: {} } as never)).toThrow(
      "Unsupported",
    );
  });

  it("hydrates Axios-shaped responses without resetting omitted stores", () => {
    useCart().count = 6;
    useSession().user = "keep";
    const hydrate = createPiniaHydrator({ cart: useCart, session: useSession });
    const intercept = createPiniaResponseInterceptor(hydrate);
    const response = {
      data: {
        $pinia: { modules: { cart: { state: { count: 10 } } } },
      },
    };

    expect(intercept(response)).toBe(response);
    expect(useCart().$state).toEqual({ count: 10, label: "old" });
    expect(useSession().user).toBe("keep");
  });

  it("watches initial, full, and partial Inertia hydration", () => {
    const hydrate = createPiniaHydrator({ cart: useCart, session: useSession });
    let props = {
      $pinia: { modules: { cart: { state: { count: 2 } } } },
    };
    let finish:
      ((event: { detail: { visit: { only?: string[] } } }) => void) | undefined;
    const stop = watchInertiaHydration(hydrate, {
      router: {
        on: (_event, callback) => {
          finish = callback;
          return () => {
            finish = undefined;
          };
        },
      },
      getProps: () => props,
    });

    expect(useCart().count).toBe(2);
    expect(useSession().user).toBe("initial");

    useSession().user = "keep-partial";
    props = { $pinia: { modules: { cart: { state: { count: 3 } } } } };
    finish?.({ detail: { visit: { only: ["$pinia"] } } });
    expect(useSession().user).toBe("keep-partial");

    finish?.({ detail: { visit: {} } });
    expect(useSession().user).toBe("initial");
    stop();
    expect(finish).toBeUndefined();
  });

  it("ignores responses and Inertia props without hydration state", () => {
    const hydrate = createPiniaHydrator({ cart: useCart });
    expect(() => hydrate({ page: "dashboard" } as never)).not.toThrow();
    expect(() =>
      createPiniaResponseInterceptor(hydrate)({ data: { ok: true } }),
    ).not.toThrow();
  });
});
