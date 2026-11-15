import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import type { Product } from '../api/types';
import { NeedNet, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';

export function PriceSheetScreen() {
  const { loadPriceCache, syncPrices, onlineForWrite, shopSettings } = useApp();
  const [rows, setRows] = useState<Product[]>([]);
  const [msg, setMsg] = useState('');
  const [err, setErr] = useState('');

  useEffect(() => {
    void loadPriceCache().then(setRows);
  }, [loadPriceCache]);

  const groups = useMemo(() => {
    const map = new Map<string, Product[]>();
    for (const p of rows) {
      const k = p.category_name || 'سایر';
      map.set(k, [...(map.get(k) || []), p]);
    }
    return [...map.entries()];
  }, [rows]);

  return (
    <Shell>
      <h1 className="h1">لیست قیمت</h1>
      {!onlineForWrite ? <NeedNet /> : null}
      {msg ? <div className="banner">{msg}</div> : null}
      {err ? <div className="warn">{err}</div> : null}
      <button
        className="btn"
        disabled={!onlineForWrite}
        type="button"
        onClick={() => {
          setErr('');
          void syncPrices()
            .then(async (m) => {
              setMsg(m);
              setRows(await loadPriceCache());
            })
            .catch((e: Error) => setErr(e.message));
        }}
      >
        همگام‌سازی قیمت
      </button>
      {groups.map(([cat, list]) => (
        <div key={cat}>
          <h2 className="h1">{cat}</h2>
          {list.map((p) => (
            <Link key={p.id} className="card row" to={`/product/${p.id}`}>
              <div className="grow">{p.name}<div className="muted">{p.visual_id}</div></div>
              <div className="price">{shopSettings?.call_for_price ? shopSettings.call_for_price_label : p.price_text}</div>
            </Link>
          ))}
        </div>
      ))}
    </Shell>
  );
}
