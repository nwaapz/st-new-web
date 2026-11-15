import type { ReactNode } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useApp } from '../state/AppState';

export function OfflineBanner({ show }: { show: boolean }) {
  if (!show) return null;
  return <div className="banner">این داده‌ها از حافظهٔ دستگاه است</div>;
}

export function Header() {
  const { internet, server, cart } = useApp();
  const navigate = useNavigate();
  const count = cart.reduce((n, l) => n + l.quantity, 0);
  return (
    <header className="header">
      <img className="logo" src="/test2/images/logo.png" alt="StarTech" />
      <div className="dots">
        <span className={`dot ${internet ? 'on' : 'off'}`}>
          <i /> اینترنت
        </span>
        <span className={`dot ${server ? 'on' : 'off'}`}>
          <i /> سرور
        </span>
      </div>
      <div className="grow" />
      <button className="icon-btn" type="button" onClick={() => navigate('/profile')} aria-label="پروفایل">
        👤
      </button>
      <button className="icon-btn" type="button" onClick={() => navigate('/cart')} aria-label="سبد خرید">
        🛒
        {count > 0 ? <span className="badge">{count}</span> : null}
      </button>
    </header>
  );
}

export function Footer() {
  const loc = useLocation();
  const { unread } = useApp();
  const tabs = [
    { to: '/shop', label: 'فروشگاه' },
    { to: '/reports', label: 'گزارش‌ها' },
    { to: '/messages', label: 'پیام‌ها', badge: unread },
    { to: '/discounts', label: 'تخفیف‌ها' },
  ];
  return (
    <nav className="footer">
      {tabs.map((t) => (
        <Link key={t.to} to={t.to} className={loc.pathname.startsWith(t.to) ? 'active' : ''}>
          {t.label}
          {t.badge ? <span className="badge">{t.badge}</span> : null}
        </Link>
      ))}
    </nav>
  );
}

export function Shell({ children, footer = true }: { children: ReactNode; footer?: boolean }) {
  return (
    <div className="app-shell">
      <Header />
      <div className={`app-body ${footer ? '' : 'no-footer'}`}>{children}</div>
      {footer ? <Footer /> : null}
    </div>
  );
}

export function NeedNet() {
  return <div className="warn">نیاز به اینترنت — این عملیات در نسخه وب بدون پیامک انجام نمی‌شود.</div>;
}
