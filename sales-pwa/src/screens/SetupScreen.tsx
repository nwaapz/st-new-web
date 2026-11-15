import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useApp } from '../state/AppState';

export function SetupScreen() {
  const { prefetchCatalog, completeSetup, onlineForWrite } = useApp();
  const nav = useNavigate();
  const [msg, setMsg] = useState('برای کار آفلاین، کاتالوگ را یک‌بار دریافت کنید.');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');

  return (
    <div className="app-shell">
      <div className="app-body no-footer">
        <h1 className="h1">راه‌اندازی اولیه</h1>
        <p className="muted">نسخه وب پیامک GSM ندارد. فقط دریافت اطلاعات فروشگاه لازم است.</p>
        {err ? <div className="warn">{err}</div> : null}
        <div className="card">{msg}</div>
        <button
          className="btn"
          disabled={busy || !onlineForWrite}
          onClick={() => {
            setBusy(true);
            setErr('');
            void prefetchCatalog(setMsg)
              .then(() => completeSetup())
              .then(() => nav('/shop', { replace: true }))
              .catch((e: Error) => setErr(e.message))
              .finally(() => setBusy(false));
          }}
        >
          دریافت اطلاعات
        </button>
        {!onlineForWrite ? <p className="muted">برای دریافت کاتالوگ به اینترنت نیاز است.</p> : null}
      </div>
    </div>
  );
}
