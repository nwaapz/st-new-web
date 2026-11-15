import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { formatJalali } from '../utils/jalali';

export function ChequesScreen() {
  const { loadCheques } = useApp();
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([]);
  const [fromCache, setFromCache] = useState(false);

  useEffect(() => {
    void loadCheques().then((r) => {
      setRows(r.cheques);
      setFromCache(r.fromCache);
    });
  }, [loadCheques]);

  return (
    <Shell>
      <h1 className="h1">چک‌ها</h1>
      <OfflineBanner show={fromCache} />
      {rows.map((c, i) => {
        const oid = Number(c.order_id || 0);
        const inner = (
          <>
            <strong>{String(c.serial || c.cheque_serial || 'چک')}</strong>
            <div className="price">{String(c.amount_text || c.amount || '')}</div>
            <div className="muted">{String(c.bank_result || c.status || '')} · سررسید {formatJalali(String(c.due_at || c.due_date || ''))}</div>
          </>
        );
        return oid ? (
          <Link key={i} className="card" to={`/orders/${oid}`}>{inner}</Link>
        ) : (
          <div key={i} className="card">{inner}</div>
        );
      })}
      {rows.length === 0 ? <div className="empty">چکی ثبت نشده</div> : null}
    </Shell>
  );
}
