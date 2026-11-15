import { initializeApp } from 'firebase/app';
import { getMessaging, getToken, isSupported, onMessage } from 'firebase/messaging';
import { apiFetch } from './api/client';

type WebConfig = {
  ok?: boolean;
  firebase?: {
    apiKey: string;
    authDomain: string;
    projectId: string;
    storageBucket?: string;
    messagingSenderId: string;
    appId: string;
  } | null;
  vapidKey?: string | null;
};

let registeredToken = '';

export async function setupPush(): Promise<void> {
  try {
    if (!(await isSupported()) || !('Notification' in window) || !('serviceWorker' in navigator)) return;
    const cfg = (await apiFetch('sales-web-config.php')) as WebConfig;
    if (!cfg.firebase?.apiKey || !cfg.vapidKey) return;
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;
    const app = initializeApp(cfg.firebase);
    const messaging = getMessaging(app);
    const sw = await navigator.serviceWorker.ready;
    sw.active?.postMessage({ type: 'FIREBASE_CONFIG', firebase: cfg.firebase });
    const token = await getToken(messaging, { vapidKey: cfg.vapidKey, serviceWorkerRegistration: sw });
    if (!token) return;
    registeredToken = token;
    await apiFetch('sales-push-register.php', {
      method: 'POST',
      body: JSON.stringify({ token, fcm_token: token, platform: 'web' }),
    });
    onMessage(messaging, (payload) => {
      const title = payload.notification?.title || 'StarTech';
      const body = payload.notification?.body || '';
      const data = (payload.data || {}) as Record<string, string>;
      void sw.showNotification(title, { body, data, icon: '/test2/images/logo.png' });
    });
  } catch {
    /* push is optional */
  }
}

export async function unregisterPush(): Promise<void> {
  if (!registeredToken) return;
  try {
    await apiFetch('sales-push-register.php', {
      method: 'DELETE',
      body: JSON.stringify({ token: registeredToken, fcm_token: registeredToken }),
    });
  } catch {
    /* ignore */
  }
  registeredToken = '';
}
