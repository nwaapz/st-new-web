type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
};

let deferred: BeforeInstallPromptEvent | null = null;

export function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

export function canPromptInstall(): boolean {
  return deferred !== null;
}

window.addEventListener('beforeinstallprompt', (event) => {
  event.preventDefault();
  deferred = event as BeforeInstallPromptEvent;
  window.dispatchEvent(new Event('pwa-installable'));
});

window.addEventListener('appinstalled', () => {
  deferred = null;
  window.dispatchEvent(new Event('pwa-installable'));
});

export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
  if (!deferred) return 'unavailable';
  await deferred.prompt();
  const choice = await deferred.userChoice;
  deferred = null;
  window.dispatchEvent(new Event('pwa-installable'));
  return choice.outcome;
}
