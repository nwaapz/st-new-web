import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import type { Product } from '../api/types';
import { NeedNet, OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { mediaUrl } from '../utils/media';

export function ProductScreen() {
  const { productId } = useParams();
  const id = Number(productId);
  const { loadProduct, addToCart, onlineForWrite } = useApp();
  const nav = useNavigate();
  const [item, setItem] = useState<Product | null>(null);
  const [fromCache, setFromCache] = useState(false);
  const [qty, setQty] = useState(1);
  const [unit, setUnit] = useState<'piece' | 'pack'>('piece');
  const [added, setAdded] = useState(false);

  useEffect(() => {
    if (!id) return;
    void loadProduct(id).then((r) => {
      setItem(r.item);
      setFromCache(r.fromCache);
    });
  }, [id, loadProduct]);

  if (!item) return <Shell><p className="muted">در حال بارگذاری…</p></Shell>;
  const pack = item.pack_size && item.pack_size > 0 ? item.pack_size : 0;

  return (
    <Shell footer={false}>
      <OfflineBanner show={fromCache} />
      <img src={mediaUrl(item.display_image || item.image)} alt="" style={{ borderRadius: 12, maxHeight: 240, objectFit: 'cover', width: '100%' }} />
      <h1 className="h1">{item.name}</h1>
      <div className="muted">{item.visual_id} · {item.factory_name} · {item.category_name}</div>
      <div className="price" style={{ margin: '8px 0' }}>{item.price_text}</div>
      {item.description ? <p>{item.description}</p> : null}
      <p className="muted">ابعاد: {item.dim_length || '—'} × {item.dim_width || '—'} × {item.dim_height || '—'} وزن {item.dim_weight || '—'}</p>
      {item.orderable ? (
        <div className="card">
          {pack ? (
            <div className="chip-row">
              <button className={`chip ${unit === 'piece' ? 'on' : ''}`} type="button" onClick={() => setUnit('piece')}>عدد</button>
              <button className={`chip ${unit === 'pack' ? 'on' : ''}`} type="button" onClick={() => setUnit('pack')}>بسته ({pack})</button>
            </div>
          ) : null}
          <div className="stepper">
            <button type="button" onClick={() => setQty(Math.max(1, qty - 1))}>−</button>
            <span>{qty}</span>
            <button type="button" onClick={() => setQty(Math.min(99, qty + 1))}>+</button>
          </div>
          <button
            className="btn"
            type="button"
            onClick={() => {
              addToCart({
                id: item.id,
                name: item.name,
                slug: item.slug,
                quantity: qty,
                unit_type: unit,
                pack_size: pack || null,
                price_text: item.price_text || null,
                image: item.image || null,
                visual_id: item.visual_id || null,
                factory_name: item.factory_name || null,
                category_name: item.category_name || null,
                orderable: true,
              });
              setAdded(true);
            }}
          >
            افزودن به سبد
          </button>
          {added ? <button className="btn ghost" type="button" onClick={() => nav('/cart')}>رفتن به سبد خرید</button> : null}
        </div>
      ) : (
        <div className="warn">در حال حاضر قابل سفارش نیست</div>
      )}
      {!onlineForWrite ? <NeedNet /> : null}
    </Shell>
  );
}
