import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import type { OrderListRow } from '../api/types';
import { OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { formatJalali } from '../utils/jalali';
import { STATUS_LABELS } from '../utils/status';

const FILTERS = [
  { id: 'all', label: 'همه' },
  { id: 'active', label: 'فعال' },
  { id: 'received', label: 'دریافت‌شده' },
  { id: 'closed', label: 'خاتمه‌یافته' },
];

function bucket(status: string): string {
  if (status === 'received') return 'received';
  if (['rejected', 'cancelled', 'lost'].includes(status)) return 'closed';
  return 'active';
}

export function ReportsScreen() {
  const { loadOrders } = useApp();
  const [orders, setOrders] = useState<OrderListRow[]>([]);
  const [fromCache, setFromCache] = useState(false);
  const [tab, setTab] = useState('all');
  const [q, setQ] = useState('');

  const reload = () => {
    void loadOrders().then((r) => {
      setOrders(r.orders);
      setFromCache(r.fromCache);
    });
  };

  useEffect(() => {
    reload();
  }, [loadOrders]);

  const counts = useMemo(() => {
    const c = { all: orders.length, active: 0, received: 0, closed: 0 };
    for (const o of orders) {
      const b = bucket(o.status);
      if (b === 'active') c.active += 1;
      if (b === 'received') c.received += 1;
      if (b === 'closed') c.closed += 1;
    }
    return c;
  }, [orders]);

  const shown = orders.filter((o) => {
    if (tab !== 'all' && bucket(o.status) !== tab) return false;
    if (!q) return true;
    const hay = `${o.public_code} ${o.phone || ''} ${o.first_item_name || ''}`;
    return hay.includes(q);
  });

  return (
    <Shell>
      <h1 className="h1">گزارش‌ها</h1>
      <OfflineBanner show={fromCache} />
      <input className="input" placeholder="جستجو کد یا تلفن" value={q} onChange={(e) => setQ(e.target.value)} />
      <div className="chip-row">
        {FILTERS.map((f) => (
          <button key={f.id} className={`chip ${tab === f.id ? 'on' : ''}`} type="button" onClick={() => setTab(f.id)}>
            {f.label} {counts[f.id as keyof typeof counts]}
          </button>
        ))}
        <button className="chip" type="button" onClick={reload}>بروزرسانی</button>
      </div>
      {shown.map((o) => (
        <Link key={o.id} className="card" to={`/orders/${o.id}`}>
          <div className="row" style={{ justifyContent: 'space-between' }}>
            <strong>{o.public_code}</strong>
            <span className="status-pill">{STATUS_LABELS[o.status] || o.status}</span>
          </div>
          <div className="muted">{formatJalali(o.created_at)} · {o.item_count || 0} قلم</div>
        </Link>
      ))}
      {shown.length === 0 ? <div className="empty">سفارشی نیست</div> : null}
    </Shell>
  );
}
