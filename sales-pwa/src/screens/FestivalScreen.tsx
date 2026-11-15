import { useEffect, useState } from 'react';
import { OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';

export function FestivalScreen() {
  const { loadFestival } = useApp();
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([]);
  const [fromCache, setFromCache] = useState(false);

  useEffect(() => {
    void loadFestival().then((r) => {
      setRows(r.festivals);
      setFromCache(r.fromCache);
    });
  }, [loadFestival]);

  return (
    <Shell>
      <h1 className="h1">جشنواره</h1>
      <OfflineBanner show={fromCache} />
      {rows.map((f) => (
        <div key={String(f.id)} className="card">
          <strong>{String(f.title || f.name || 'جشنواره')}</strong>
          <p>{String(f.description || '')}</p>
          <div className="price">{String(f.total_text || f.total_toman || 0)}</div>
          {f.remaining_to_next_text ? <p className="muted">تا سطح بعد: {String(f.remaining_to_next_text)}</p> : null}
          {f.is_live ? <span className="status-pill">فعال</span> : <span className="muted">زمان‌بندی شده</span>}
        </div>
      ))}
      {rows.length === 0 ? <div className="empty">جشنواره‌ای نیست</div> : null}
    </Shell>
  );
}
