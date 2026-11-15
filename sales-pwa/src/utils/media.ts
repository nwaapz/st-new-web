export const SITE_BASE = '/test2';
export const API_BASE = `${SITE_BASE}/api`;

export function mediaUrl(path?: string | null): string {
  if (!path) return '';
  if (/^https?:\/\//i.test(path) || path.startsWith('data:')) return path;
  if (path.startsWith('/test2/')) return path;
  if (path.startsWith('/')) return `${SITE_BASE}${path}`;
  return `${SITE_BASE}/${path.replace(/^\.\//, '')}`;
}

export function uuid(): string {
  if (crypto.randomUUID) return crypto.randomUUID();
  return `pwa-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
