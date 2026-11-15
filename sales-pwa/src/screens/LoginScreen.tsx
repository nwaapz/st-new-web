import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useApp } from '../state/AppState';

export function LoginScreen() {
  const { login, setupDone } = useApp();
  const nav = useNavigate();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);

  return (
    <div className="app-shell">
      <div className="app-body no-footer" style={{ paddingTop: 48 }}>
        <img src="/test2/images/logo.png" alt="" style={{ height: 40, margin: '0 auto 16px' }} />
        <h1 className="h1" style={{ textAlign: 'center' }}>ورود</h1>
        {err ? <div className="warn">{err}</div> : null}
        <input className="input" placeholder="نام کاربری" value={username} onChange={(e) => setUsername(e.target.value)} dir="ltr" />
        <input className="input" placeholder="رمز عبور" type="password" value={password} onChange={(e) => setPassword(e.target.value)} dir="ltr" />
        <button
          className="btn"
          disabled={busy}
          onClick={() => {
            setBusy(true);
            setErr('');
            void login(username.trim(), password)
              .then(() => nav(setupDone ? '/shop' : '/setup', { replace: true }))
              .catch((e: Error) => setErr(e.message))
              .finally(() => setBusy(false));
          }}
        >
          ورود
        </button>
      </div>
    </div>
  );
}
