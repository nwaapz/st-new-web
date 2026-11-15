import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { apiFetch, cachedGet, pingServer, setRelogin } from '../api/client';
import type {
  CartLine,
  FlashOffer,
  NamedItem,
  OrderDetail,
  OrderListRow,
  Product,
  SalesUser,
  Series,
  ShopSettings,
} from '../api/types';
import { CacheKeys } from '../cache/keys';
import { cacheGet, cacheSet, cacheDel } from '../cache/store';
import { uuid } from '../utils/media';

type CatalogSnapshot = {
  items: Product[];
  series: Series[];
  total?: number;
};

type Ctx = {
  bootstrapped: boolean;
  user: SalesUser | null;
  setupDone: boolean;
  internet: boolean;
  server: boolean;
  cart: CartLine[];
  unread: number;
  fromCache: boolean;
  setFromCache: (v: boolean) => void;
  login: (username: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  completeSetup: () => Promise<void>;
  prefetchCatalog: (onProgress?: (msg: string) => void) => Promise<void>;
  loadCatalog: (params?: Record<string, string>) => Promise<{ items: Product[]; series: Series[]; total: number; fromCache: boolean }>;
  loadProduct: (id: number) => Promise<{ item: Product; fromCache: boolean }>;
  loadSeries: (id: number) => Promise<{ item: Series; fromCache: boolean }>;
  shopSettings: ShopSettings | null;
  factories: NamedItem[];
  categories: NamedItem[];
  carModels: NamedItem[];
  loadFilters: () => Promise<void>;
  addToCart: (line: CartLine) => void;
  setQty: (id: number, flashDealId: number | null | undefined, qty: number) => void;
  clearCart: () => void;
  submitOrder: () => Promise<OrderDetail>;
  loadOrders: () => Promise<{ orders: OrderListRow[]; fromCache: boolean }>;
  loadOrder: (id: number) => Promise<{ order: OrderDetail; fromCache: boolean }>;
  cancelOrder: (id: number) => Promise<OrderDetail>;
  confirmAdjustments: (id: number) => Promise<OrderDetail>;
  submitPayment: (id: number, note: string, reference: string, files: File[]) => Promise<OrderDetail>;
  loadMessages: (orderId: number) => Promise<{ messages: Array<Record<string, unknown>>; fromCache: boolean }>;
  sendOrderMessage: (orderId: number, body: string, image?: File | null) => Promise<void>;
  loadDirect: () => Promise<{ messages: Array<Record<string, unknown>>; fromCache: boolean }>;
  sendDirect: (body: string, image?: File | null) => Promise<void>;
  loadFlash: () => Promise<{ offers: FlashOffer[]; fromCache: boolean; serverTime?: string }>;
  loadFestival: () => Promise<{ festivals: Array<Record<string, unknown>>; fromCache: boolean }>;
  loadCheques: () => Promise<{ cheques: Array<Record<string, unknown>>; fromCache: boolean }>;
  syncPrices: () => Promise<string>;
  loadPriceCache: () => Promise<Product[]>;
  onlineForWrite: boolean;
};

const AppCtx = createContext<Ctx | null>(null);

export function useApp() {
  const ctx = useContext(AppCtx);
  if (!ctx) throw new Error('AppState missing');
  return ctx;
}

function qs(params?: Record<string, string>) {
  if (!params) return '';
  const sp = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v) sp.set(k, v);
  }
  const s = sp.toString();
  return s ? `?${s}` : '';
}

export function AppStateProvider({ children }: { children: ReactNode }) {
  const [bootstrapped, setBootstrapped] = useState(false);
  const [user, setUser] = useState<SalesUser | null>(null);
  const [setupDone, setSetupDone] = useState(false);
  const [internet, setInternet] = useState(navigator.onLine);
  const [server, setServer] = useState(false);
  const [cart, setCart] = useState<CartLine[]>([]);
  const [unread, setUnread] = useState(0);
  const [fromCache, setFromCache] = useState(false);
  const [shopSettings, setShopSettings] = useState<ShopSettings | null>(null);
  const [factories, setFactories] = useState<NamedItem[]>([]);
  const [categories, setCategories] = useState<NamedItem[]>([]);
  const [carModels, setCarModels] = useState<NamedItem[]>([]);

  const persistCart = useCallback(async (next: CartLine[]) => {
    setCart(next);
    await cacheSet(CacheKeys.cart(), next);
  }, []);

  const persistUser = useCallback(async (next: SalesUser | null) => {
    setUser(next);
    if (next) await cacheSet(CacheKeys.sessionUser(), next);
    else await cacheDel(CacheKeys.sessionUser());
  }, []);

  useEffect(() => {
    const on = () => setInternet(true);
    const off = () => setInternet(false);
    window.addEventListener('online', on);
    window.addEventListener('offline', off);
    return () => {
      window.removeEventListener('online', on);
      window.removeEventListener('offline', off);
    };
  }, []);

  useEffect(() => {
    let stop = false;
    const tick = async () => {
      const ok = await pingServer();
      if (!stop) setServer(ok);
    };
    void tick();
    const id = window.setInterval(() => void tick(), 15000);
    return () => {
      stop = true;
      window.clearInterval(id);
    };
  }, []);

  useEffect(() => {
    if (!user) return;
    const beat = () => {
      void apiFetch('sales-presence.php', { method: 'POST', body: '{}' }).catch(() => undefined);
    };
    beat();
    const id = window.setInterval(beat, 60000);
    return () => window.clearInterval(id);
  }, [user]);

  useEffect(() => {
    if (!user) return;
    const loadUnread = async () => {
      try {
        const body = (await apiFetch('sales-direct-messages.php?unread=1')) as { unread_count?: number };
        setUnread(body.unread_count || 0);
      } catch {
        /* ignore */
      }
    };
    void loadUnread();
    const id = window.setInterval(() => void loadUnread(), 20000);
    return () => window.clearInterval(id);
  }, [user]);

  useEffect(() => {
    void (async () => {
      const remembered = await cacheGet<{ username: string; password: string }>(CacheKeys.rememberedLogin());
      const cachedUser = await cacheGet<SalesUser>(CacheKeys.sessionUser());
      const done = await cacheGet<boolean>(CacheKeys.setupDone());
      const cachedCart = await cacheGet<CartLine[]>(CacheKeys.cart());
      if (cachedCart) setCart(cachedCart);
      if (done) setSetupDone(true);
      if (remembered) setRelogin(remembered);
      if (cachedUser) setUser(cachedUser);
      try {
        const me = (await apiFetch('sales-auth-me.php')) as { authenticated?: boolean; user?: SalesUser };
        if (me.authenticated && me.user) {
          await persistUser(me.user);
        } else if (remembered) {
          const loginBody = (await apiFetch('sales-auth-login.php', {
            method: 'POST',
            body: JSON.stringify(remembered),
          })) as { user: SalesUser };
          await persistUser(loginBody.user);
        } else {
          await persistUser(null);
        }
      } catch {
        /* keep cached user for offline */
      }
      setBootstrapped(true);
    })();
  }, [persistUser]);

  const login = useCallback(async (username: string, password: string) => {
    const body = (await apiFetch('sales-auth-login.php', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    })) as { user: SalesUser };
    const pair = { username, password };
    setRelogin(pair);
    await cacheSet(CacheKeys.rememberedLogin(), pair);
    await persistUser(body.user);
  }, [persistUser]);

  const logout = useCallback(async () => {
    try {
      await apiFetch('sales-push-register.php', { method: 'DELETE', body: '{}' });
    } catch {
      /* ignore */
    }
    try {
      await apiFetch('sales-auth-logout.php', { method: 'POST', body: '{}' });
    } catch {
      /* ignore */
    }
    setRelogin(null);
    await cacheDel(CacheKeys.rememberedLogin());
    await persistUser(null);
  }, [persistUser]);

  const completeSetup = useCallback(async () => {
    await cacheSet(CacheKeys.setupDone(), true);
    setSetupDone(true);
  }, []);

  const prefetchCatalog = useCallback(async (onProgress?: (msg: string) => void) => {
    onProgress?.('دریافت تنظیمات فروشگاه…');
    const settings = await cachedGet<ShopSettings>('shop-settings.php', CacheKeys.shopSettings());
    setShopSettings(settings.data);
    onProgress?.('دریافت کاتالوگ…');
    const products = await cachedGet<{ items: Product[]; total?: number }>(
      'products.php?page=1&per_page=2000&catalog=1',
      CacheKeys.catalogSnapshot(),
    );
    const series = await cachedGet<{ items: Series[] }>('product-series-catalog.php', 'series_catalog');
    await cacheSet(CacheKeys.catalogSnapshot(), {
      items: products.data.items || [],
      series: series.data.items || [],
      total: products.data.total,
    } satisfies CatalogSnapshot);
    onProgress?.('دریافت فیلترها…');
    await Promise.all([
      cachedGet<{ items: NamedItem[] }>('factories.php', CacheKeys.factories()),
      cachedGet<{ items: NamedItem[] }>('categories.php', CacheKeys.categories()),
      cachedGet<{ items: NamedItem[] }>('car-models.php?all=1', CacheKeys.carModels()),
    ]);
    const urls = [
      ...(products.data.items || []).map((p) => p.display_image || p.image),
      ...(series.data.items || []).map((s) => s.image),
    ].filter(Boolean) as string[];
    onProgress?.('ذخیره تصاویر…');
    await Promise.allSettled(urls.slice(0, 80).map((u) => fetch(u.startsWith('http') || u.startsWith('/') ? u : `/test2/${u}`)));
  }, []);

  const loadFilters = useCallback(async () => {
    const [f, c, m, s] = await Promise.all([
      cachedGet<{ items: NamedItem[] }>('factories.php', CacheKeys.factories()),
      cachedGet<{ items: NamedItem[] }>('categories.php', CacheKeys.categories()),
      cachedGet<{ items: NamedItem[] }>('car-models.php?all=1', CacheKeys.carModels()),
      cachedGet<ShopSettings>('shop-settings.php', CacheKeys.shopSettings()),
    ]);
    setFactories(f.data.items || []);
    setCategories(c.data.items || []);
    setCarModels(m.data.items || []);
    setShopSettings(s.data);
    setFromCache(f.fromCache || c.fromCache || m.fromCache || s.fromCache);
  }, []);

  const loadCatalog = useCallback(async (params?: Record<string, string>) => {
    const query = qs({ page: '1', per_page: '10', ...params });
    const key = `catalog_page_${query}`;
    const [prod, series] = await Promise.all([
      cachedGet<{ items: Product[]; total?: number }>(`products.php${query}`, key),
      cachedGet<{ items: Series[] }>(`product-series-catalog.php${query}`, `series_${query}`),
    ]);
    const snap = await cacheGet<CatalogSnapshot>(CacheKeys.catalogSnapshot());
    let items = prod.data.items || [];
    let seriesItems = series.data.items || [];
    let total = prod.data.total || items.length;
    let from = prod.fromCache || series.fromCache;
    if (from && snap && (!params || Object.keys(params).every((k) => !params[k]))) {
      items = snap.items.slice(0, 10);
      seriesItems = snap.series;
      total = snap.items.length;
    }
    return { items, series: seriesItems, total, fromCache: from };
  }, []);

  const loadProduct = useCallback(async (id: number) => {
    const res = await cachedGet<{ item: Product }>(`product.php?id=${id}`, CacheKeys.productDetail(id));
    return { item: res.data.item, fromCache: res.fromCache };
  }, []);

  const loadSeries = useCallback(async (id: number) => {
    const res = await cachedGet<{ item?: Series }>(`product-series-detail.php?id=${id}`, CacheKeys.seriesDetail(id));
    const item = (res.data.item || (res.data as unknown as Series)) as Series;
    return { item, fromCache: res.fromCache };
  }, []);

  const addToCart = useCallback((line: CartLine) => {
    setCart((prev) => {
      const idx = prev.findIndex((x) => x.id === line.id && (x.flash_deal_id || 0) === (line.flash_deal_id || 0));
      let next: CartLine[];
      if (idx >= 0) {
        next = prev.map((x, i) => (i === idx ? { ...x, quantity: Math.min(99, x.quantity + line.quantity) } : x));
      } else {
        next = [...prev, line];
      }
      void cacheSet(CacheKeys.cart(), next);
      return next;
    });
  }, []);

  const setQty = useCallback((id: number, flashDealId: number | null | undefined, qty: number) => {
    setCart((prev) => {
      const next = prev
        .map((x) => (x.id === id && (x.flash_deal_id || 0) === (flashDealId || 0) ? { ...x, quantity: qty } : x))
        .filter((x) => x.quantity > 0);
      void cacheSet(CacheKeys.cart(), next);
      return next;
    });
  }, []);

  const clearCart = useCallback(() => {
    void persistCart([]);
  }, [persistCart]);

  const submitOrder = useCallback(async () => {
    if (!navigator.onLine || !server) throw new Error('نیاز به اینترنت');
    const body = (await apiFetch('sales-orders.php', {
      method: 'POST',
      body: JSON.stringify({
        items: cart,
        client_order_uuid: uuid(),
      }),
    })) as { order: OrderDetail };
    await persistCart([]);
    return body.order;
  }, [cart, persistCart, server]);

  const loadOrders = useCallback(async () => {
    const res = await cachedGet<{ orders: OrderListRow[] }>('sales-orders.php', CacheKeys.ordersList());
    return { orders: res.data.orders || [], fromCache: res.fromCache };
  }, []);

  const loadOrder = useCallback(async (id: number) => {
    const res = await cachedGet<{ order: OrderDetail }>(`sales-orders.php?id=${id}`, CacheKeys.orderDetail(id));
    return { order: res.data.order, fromCache: res.fromCache };
  }, []);

  const cancelOrder = useCallback(async (id: number) => {
    const body = (await apiFetch('sales-order-cancel.php', {
      method: 'POST',
      body: JSON.stringify({ order_id: id }),
    })) as { order: OrderDetail };
    return body.order;
  }, []);

  const confirmAdjustments = useCallback(async (id: number) => {
    const body = (await apiFetch('sales-order-confirm-adjustments.php', {
      method: 'POST',
      body: JSON.stringify({ order_id: id }),
    })) as { order: OrderDetail };
    return body.order;
  }, []);

  const submitPayment = useCallback(async (id: number, note: string, reference: string, files: File[]) => {
    const fd = new FormData();
    fd.set('order_id', String(id));
    fd.set('note', note);
    fd.set('payment_reference', reference);
    fd.set('keep_files', '[]');
    files.forEach((f) => fd.append('files[]', f));
    const body = (await apiFetch('sales-order-payment.php', { method: 'POST', body: fd })) as { order: OrderDetail };
    return body.order;
  }, []);

  const loadMessages = useCallback(async (orderId: number) => {
    const res = await cachedGet<{ messages: Array<Record<string, unknown>> }>(
      `sales-order-messages.php?order_id=${orderId}`,
      CacheKeys.orderMessages(orderId),
    );
    return { messages: res.data.messages || [], fromCache: res.fromCache };
  }, []);

  const sendOrderMessage = useCallback(async (orderId: number, body: string, image?: File | null) => {
    if (image) {
      const fd = new FormData();
      fd.set('order_id', String(orderId));
      fd.set('body', body);
      fd.set('image', image);
      await apiFetch('sales-order-messages.php', { method: 'POST', body: fd });
      return;
    }
    await apiFetch('sales-order-messages.php', {
      method: 'POST',
      body: JSON.stringify({ order_id: orderId, body }),
    });
  }, []);

  const loadDirect = useCallback(async () => {
    if (!user) return { messages: [], fromCache: false };
    const res = await cachedGet<{ messages: Array<Record<string, unknown>>; unread_count?: number }>(
      'sales-direct-messages.php?timeline=1',
      CacheKeys.directTimeline(user.id),
    );
    setUnread(0);
    return { messages: res.data.messages || [], fromCache: res.fromCache };
  }, [user]);

  const sendDirect = useCallback(async (body: string, image?: File | null) => {
    if (image) {
      const fd = new FormData();
      fd.set('body', body);
      fd.set('image', image);
      await apiFetch('sales-direct-messages.php', { method: 'POST', body: fd });
      return;
    }
    await apiFetch('sales-direct-messages.php', {
      method: 'POST',
      body: JSON.stringify({ body }),
    });
  }, []);

  const loadFlash = useCallback(async () => {
    const res = await cachedGet<{ offers: FlashOffer[]; server_time?: string }>(
      'sales-flash-deals.php',
      CacheKeys.flashDeals(),
    );
    return { offers: res.data.offers || [], fromCache: res.fromCache, serverTime: res.data.server_time };
  }, []);

  const loadFestival = useCallback(async () => {
    const res = await cachedGet<{ festivals: Array<Record<string, unknown>> }>(
      'sales-festival.php',
      CacheKeys.festivalSnapshot(),
    );
    return { festivals: res.data.festivals || [], fromCache: res.fromCache };
  }, []);

  const loadCheques = useCallback(async () => {
    const res = await cachedGet<{ cheques: Array<Record<string, unknown>> }>(
      'sales-cheques.php',
      CacheKeys.chequesList(),
    );
    return { cheques: res.data.cheques || [], fromCache: res.fromCache };
  }, []);

  const syncPrices = useCallback(async () => {
    const meta = await cacheGet<{ since?: string }>(CacheKeys.priceSyncMeta());
    const path = meta?.since ? `client-price-sync.php?since=${encodeURIComponent(meta.since)}` : 'client-price-sync.php';
    const body = (await apiFetch(path)) as { products?: Product[]; synced_at?: string; product_count?: number };
    const prev = (await cacheGet<Product[]>(CacheKeys.priceCache())) || [];
    const map = new Map(prev.map((p) => [p.id, p]));
    for (const p of body.products || []) map.set(p.id, p);
    const next = [...map.values()];
    await cacheSet(CacheKeys.priceCache(), next);
    await cacheSet(CacheKeys.priceSyncMeta(), { since: body.synced_at });
    return `${body.product_count || 0} قیمت به‌روز شد`;
  }, []);

  const loadPriceCache = useCallback(async () => {
    const cached = await cacheGet<Product[]>(CacheKeys.priceCache());
    if (cached && cached.length) return cached;
    const snap = await cacheGet<CatalogSnapshot>(CacheKeys.catalogSnapshot());
    return snap?.items || [];
  }, []);

  const value = useMemo<Ctx>(
    () => ({
      bootstrapped,
      user,
      setupDone,
      internet,
      server,
      cart,
      unread,
      fromCache,
      setFromCache,
      login,
      logout,
      completeSetup,
      prefetchCatalog,
      loadCatalog,
      loadProduct,
      loadSeries,
      shopSettings,
      factories,
      categories,
      carModels,
      loadFilters,
      addToCart,
      setQty,
      clearCart,
      submitOrder,
      loadOrders,
      loadOrder,
      cancelOrder,
      confirmAdjustments,
      submitPayment,
      loadMessages,
      sendOrderMessage,
      loadDirect,
      sendDirect,
      loadFlash,
      loadFestival,
      loadCheques,
      syncPrices,
      loadPriceCache,
      onlineForWrite: internet && server,
    }),
    [
      bootstrapped,
      user,
      setupDone,
      internet,
      server,
      cart,
      unread,
      fromCache,
      login,
      logout,
      completeSetup,
      prefetchCatalog,
      loadCatalog,
      loadProduct,
      loadSeries,
      shopSettings,
      factories,
      categories,
      carModels,
      loadFilters,
      addToCart,
      setQty,
      clearCart,
      submitOrder,
      loadOrders,
      loadOrder,
      cancelOrder,
      confirmAdjustments,
      submitPayment,
      loadMessages,
      sendOrderMessage,
      loadDirect,
      sendDirect,
      loadFlash,
      loadFestival,
      loadCheques,
      syncPrices,
      loadPriceCache,
    ],
  );

  return <AppCtx.Provider value={value}>{children}</AppCtx.Provider>;
}
