import { CacheKeys } from '../cache/keys';
import { cacheGet, cacheSet } from '../cache/store';
import { API_BASE } from '../utils/media';
import type { Cached } from './types';

export class ApiError extends Error {
  status: number;
  body: unknown;
  constructor(message: string, status: number, body?: unknown) {
    super(message);
    this.status = status;
    this.body = body;
  }
}

type LoginPair = { username: string; password: string };

let relogin: LoginPair | null = null;

export function setRelogin(pair: LoginPair | null) {
  relogin = pair;
}

async function parseJson(res: Response): Promise<unknown> {
  const text = await res.text();
  try {
    return JSON.parse(text) as unknown;
  } catch {
    throw new ApiError('پاسخ سرور نامعتبر است', res.status, text.slice(0, 200));
  }
}

function errorMessage(body: unknown, fallback: string): string {
  if (body && typeof body === 'object' && 'error' in body && typeof (body as { error: unknown }).error === 'string') {
    return (body as { error: string }).error;
  }
  return fallback;
}

export async function apiFetch(path: string, init: RequestInit = {}, triedRelogin = false): Promise<unknown> {
  const headers = new Headers(init.headers);
  if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }
  const res = await fetch(`${API_BASE}/${path}`, {
    ...init,
    headers,
    credentials: 'include',
  });
  const body = await parseJson(res);
  if (res.status === 401 && !triedRelogin && relogin) {
    const loginRes = await fetch(`${API_BASE}/sales-auth-login.php`, {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(relogin),
    });
    if (loginRes.ok) {
      return apiFetch(path, init, true);
    }
  }
  if (!res.ok) {
    throw new ApiError(errorMessage(body, `خطای ${res.status}`), res.status, body);
  }
  return body;
}

export async function cachedGet<T>(
  path: string,
  cacheKey: string,
  init?: RequestInit,
): Promise<Cached<T>> {
  try {
    const data = (await apiFetch(path, { method: 'GET', ...init })) as T;
    await cacheSet(cacheKey, data);
    return { data, fromCache: false };
  } catch (err) {
    const cached = await cacheGet<T>(cacheKey);
    if (cached !== undefined) {
      return { data: cached, fromCache: true };
    }
    throw err;
  }
}

export async function pingServer(): Promise<boolean> {
  try {
    const ctrl = new AbortController();
    const t = window.setTimeout(() => ctrl.abort(), 4000);
    const res = await fetch(`${API_BASE}/ping.php`, { signal: ctrl.signal, cache: 'no-store' });
    window.clearTimeout(t);
    return res.ok;
  } catch {
    return false;
  }
}

export { CacheKeys };
