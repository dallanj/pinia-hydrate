import type { Pinia, StateTree, StoreGeneric } from "pinia";

export type HydrationMode = "patch" | "replace";
export interface ModulePayload {
  mode?: HydrationMode;
  state?: StateTree;
  modules?: Record<string, ModulePayload>;
}
export interface HydrationPayload {
  version?: 1;
  modules: Record<string, ModulePayload>;
}
export type HydrationInput =
  | HydrationPayload
  | {
      pinia?: HydrationPayload | string;
      $pinia?: HydrationPayload | string;
    }
  | string;
export type StoreFactory = (pinia?: Pinia) => StoreGeneric;
export interface StoreRegistration {
  useStore: StoreFactory;
  resetOnFullHydration?: boolean;
}
export type StoreMap = Record<string, StoreFactory | StoreRegistration>;
export interface HydrationOptions {
  pinia?: Pinia;
  resetMissing?: boolean;
}

export interface ResponseLike {
  data?: unknown;
}

export interface InertiaFinishEvent {
  detail?: {
    visit?: {
      only?: string[];
    };
  };
}

export interface InertiaRouterLike {
  on(
    event: "finish",
    callback: (event: InertiaFinishEvent) => void,
  ): void | (() => void);
}

export interface InertiaHydrationOptions {
  router: InertiaRouterLike;
  getProps: () => HydrationInput;
  pinia?: Pinia;
}

export type PiniaHydrator = (
  input: HydrationInput,
  piniaOrOptions?: Pinia | HydrationOptions,
) => void;

export function createPiniaHydrator(registrations: StoreMap): PiniaHydrator {
  return (
    input: HydrationInput,
    piniaOrOptions?: Pinia | HydrationOptions,
  ): void => {
    const options = normalizeOptions(piniaOrOptions);
    const payload = normalizePayload(input);
    if (!payload) return;
    const requested = new Set<string>();

    for (const [name, entry] of moduleEntries(payload.modules)) {
      requested.add(name);
      const registration = registrations[name];
      if (!registration)
        throw new Error(`No Pinia store factory is registered as [${name}].`);
      if (
        !entry.state ||
        typeof entry.state !== "object" ||
        Array.isArray(entry.state)
      ) {
        throw new TypeError(`Invalid hydration entry for [${name}].`);
      }

      const factory =
        typeof registration === "function"
          ? registration
          : registration.useStore;
      const store = factory(options.pinia);
      const mode = entry.mode ?? (options.resetMissing ? "replace" : "patch");
      importStateData(store, entry.state, {
        resetMissingKeys: mode === "replace" || options.resetMissing === true,
      });
    }

    if (options.resetMissing) {
      for (const [name, registration] of Object.entries(registrations)) {
        if (requested.has(name)) continue;
        if (
          typeof registration !== "function" &&
          registration.resetOnFullHydration === false
        )
          continue;
        const factory =
          typeof registration === "function"
            ? registration
            : registration.useStore;
        const store = factory(options.pinia);
        if (typeof store.$reset === "function") store.$reset();
      }
    }
  };
}

function importStateData(
  store: StoreGeneric,
  state: StateTree,
  { resetMissingKeys = false }: { resetMissingKeys?: boolean } = {},
): void {
  const resettableStore = store as StoreGeneric & {
    _isLazyLoaded?: boolean;
    $reset?: (key?: string) => void;
  };
  const isLazyLoaded = resettableStore._isLazyLoaded ?? false;
  const resetsIndividualKeys = (resettableStore.$reset?.length ?? 0) > 0;

  if (
    resetMissingKeys &&
    !isLazyLoaded &&
    typeof resettableStore.$reset === "function" &&
    !resetsIndividualKeys
  ) {
    resettableStore.$reset();
  }

  for (const storeKey of Object.keys(store.$state)) {
    if (Object.prototype.hasOwnProperty.call(state, storeKey)) {
      store[storeKey] = state[storeKey];
    } else if (resetMissingKeys && !isLazyLoaded) {
      if (
        typeof resettableStore.$reset === "function" &&
        resetsIndividualKeys
      ) {
        resettableStore.$reset(storeKey);
      } else if (typeof resettableStore.$reset !== "function") {
        store[storeKey] = null;
      }
    }
  }
}

export function createPiniaResponseInterceptor(hydrate: PiniaHydrator) {
  return <Response extends ResponseLike>(response: Response): Response => {
    if (response.data !== undefined) {
      hydrate(response.data as HydrationInput, { resetMissing: false });
    }

    return response;
  };
}

export function watchInertiaHydration(
  hydrate: PiniaHydrator,
  options: InertiaHydrationOptions,
): () => void {
  const hydrateCurrentPage = (resetMissing: boolean): void => {
    hydrate(options.getProps(), {
      pinia: options.pinia,
      resetMissing,
    });
  };

  hydrateCurrentPage(true);

  const unsubscribe = options.router.on("finish", (event) => {
    const only = event.detail?.visit?.only;
    const isPartial = Array.isArray(only) && only.length > 0;
    hydrateCurrentPage(!isPartial);
  });

  return typeof unsubscribe === "function" ? unsubscribe : () => {};
}

function normalizeOptions(value?: Pinia | HydrationOptions): HydrationOptions {
  if (!value) return {};
  if ("pinia" in value || "resetMissing" in value)
    return value as HydrationOptions;
  return { pinia: value as Pinia };
}

function normalizePayload(input: HydrationInput): HydrationPayload | null {
  let value: unknown = typeof input === "string" ? JSON.parse(input) : input;
  if (
    value &&
    typeof value === "object" &&
    ("pinia" in value || "$pinia" in value)
  ) {
    const props = value as { pinia?: unknown; $pinia?: unknown };
    value = props.pinia ?? props.$pinia;
    if (typeof value === "string") value = JSON.parse(value);
  }

  if (!value || typeof value !== "object") {
    throw new TypeError("Unsupported Pinia hydration payload.");
  }

  if (!("modules" in value)) return null;

  const payload = value as HydrationPayload;
  if (
    (payload.version !== undefined && payload.version !== 1) ||
    !payload.modules ||
    typeof payload.modules !== "object"
  ) {
    throw new TypeError("Unsupported Pinia hydration payload.");
  }
  return payload;
}

function* moduleEntries(
  modules: Record<string, ModulePayload>,
  prefix = "",
): Generator<[string, ModulePayload]> {
  for (const [name, entry] of Object.entries(modules)) {
    const path = prefix ? `${prefix}/${name}` : name;
    if (!entry || typeof entry !== "object")
      throw new TypeError(`Invalid hydration entry for [${path}].`);
    if (entry.state !== undefined) yield [path, entry];
    if (entry.modules) yield* moduleEntries(entry.modules, path);
  }
}
