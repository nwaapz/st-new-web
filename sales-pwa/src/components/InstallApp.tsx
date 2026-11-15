import { useEffect, useState } from 'react';
import { canPromptInstall, isStandalone, promptInstall } from '../install';

export function InstallApp() {
  const [ready, setReady] = useState(canPromptInstall());
  const [done, setDone] = useState(isStandalone());
  const [msg, setMsg] = useState('');

  useEffect(() => {
    const sync = () => {
      setReady(canPromptInstall());
      setDone(isStandalone());
    };
    window.addEventListener('pwa-installable', sync);
    return () => window.removeEventListener('pwa-installable', sync);
  }, []);

  if (done) return null;

  return (
    <div className="card">
      <strong>نصب اپ روی گوشی</strong>
      <p className="muted">نصب واقعی حافظهٔ آفلاین دارد. میانبر صفحهٔ اصلی این کار را نمی‌کند.</p>
      {ready ? (
        <button
          className="btn"
          type="button"
          onClick={() => {
            void promptInstall().then((outcome) => {
              if (outcome === 'accepted') setDone(true);
            });
          }}
        >
          نصب اپ
        </button>
      ) : (
        <p className="muted">{msg || 'اگر دکمه نیامد، صفحه را یک بار تازه‌سازی کنید و از منوی Chrome گزینه Install app را بزنید، نه Add to Home screen.'}</p>
      )}
      {!ready ? (
        <button className="btn ghost" type="button" onClick={() => { setMsg('در حال تازه‌سازی…'); location.reload(); }}>
          تازه‌سازی برای فعال شدن نصب
        </button>
      ) : null}
    </div>
  );
}
