import { useNavigate } from 'react-router-dom';
import { Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';

export function ProfileScreen() {
  const { user, logout } = useApp();
  const nav = useNavigate();
  return (
    <Shell>
      <h1 className="h1">پروفایل</h1>
      <div className="card">
        <div><strong>{user?.display_name || user?.username}</strong></div>
        <div className="muted" dir="ltr">{user?.username}</div>
        {user?.branch_id ? <div className="muted">شناسه نماینده: {user.branch_id}</div> : null}
      </div>
      <button className="btn ghost" type="button" onClick={() => nav('/reports')}>سفارش‌های من</button>
      <button className="btn ghost" type="button" onClick={() => nav('/cheques')}>چک‌ها</button>
      <button className="btn ghost" type="button" onClick={() => nav('/price-sheet')}>لیست قیمت</button>
      <button
        className="btn danger"
        type="button"
        onClick={() => {
          void logout().then(() => nav('/login', { replace: true }));
        }}
      >
        خروج
      </button>
    </Shell>
  );
}
