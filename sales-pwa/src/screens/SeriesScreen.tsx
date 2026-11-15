import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import type { Series } from '../api/types';
import { OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { mediaUrl } from '../utils/media';

export function SeriesScreen() {
  const { seriesId } = useParams();
  const id = Number(seriesId);
  const { loadSeries, addToCart } = useApp();
  const nav = useNavigate();
  const [item, setItem] = useState<Series | null>(null);
  const [fromCache, setFromCache] = useState(false);
  const [qty, setQty] = useState(1);

  useEffect(() => {
    if (!id) return;
    void loadSeries(id).then((r) => {
      setItem(r.item);
      setFromCache(r.fromCache);
    });
  }, [id, loadSeries]);

  if (!item) return <Shell footer={false}><p className="muted">در حال بارگذاری…</p></Shell>;
  const cartId = -Math.abs(item.id);

  return (
    <Shell footer={false}>
      <OfflineBanner show={fromCache} />
      <img src={mediaUrl(item.image)} alt="" style={{ borderRadius: 12, maxHeight: 220, objectFit: 'cover', width: '100%' }} />
      <h1 className="h1">{item.name}</h1>
      <div className="price">{item.price_text}</div>
      {item.description ? <p>{item.description}</p> : null}
      <h2 className="h1">قطعات کیت</h2>
      {(item.products || []).map((p) => (
        <Link key={p.id} className="card row" to={`/product/${p.id}`}>
          {p.image ? <img className="thumb" src={mediaUrl(p.image)} alt="" /> : null}
          <div>{p.name}</div>
        </Link>
      ))}
      {item.orderable ? (
        <div className="card">
          <div className="stepper">
            <button type="button" onClick={() => setQty(Math.max(1, qty - 1))}>−</button>
            <span>{qty}</span>
            <button type="button" onClick={() => setQty(qty + 1)}>+</button>
          </div>
          <button
            className="btn"
            type="button"
            onClick={() => {
              addToCart({
                id: cartId,
                name: item.name,
                slug: item.slug,
                quantity: qty,
                unit_type: 'piece',
                pack_size: item.pack_size || null,
                price_text: item.price_text || null,
                image: item.image || null,
                visual_id: item.visual_id || null,
                orderable: true,
              });
              nav('/cart');
            }}
          >
            افزودن به سبد
          </button>
        </div>
      ) : (
        <button className="btn" disabled>در حال حاضر قابل سفارش نیست</button>
      )}
    </Shell>
  );
}
