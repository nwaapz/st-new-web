import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import type { Product, Series } from '../api/types';
import { OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { mediaUrl } from '../utils/media';

export function ShopScreen() {
  const { loadCatalog, loadFilters, shopSettings, addToCart, setFromCache } = useApp();
  const nav = useNavigate();
  const [sp] = useSearchParams();
  const [items, setItems] = useState<Product[]>([]);
  const [series, setSeries] = useState<Series[]>([]);
  const [q, setQ] = useState('');
  const [banner, setBanner] = useState('');
  const [fromCache, setLocalCache] = useState(false);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);

  const refresh = (nextPage = 1, extra?: Record<string, string>) => {
    void loadCatalog({
      page: String(nextPage),
      per_page: '10',
      q,
      banner,
      factory_id: sp.get('factory_id') || '',
      car_model_id: sp.get('car_model_id') || '',
      category_ids: sp.get('category_ids') || '',
      ...extra,
    }).then((r) => {
      setItems((prev) => (nextPage === 1 ? r.items : [...prev, ...r.items]));
      setSeries(r.series);
      setTotal(r.total);
      setLocalCache(r.fromCache);
      setFromCache(r.fromCache);
      setPage(nextPage);
    });
  };

  useEffect(() => {
    void loadFilters();
    refresh(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sp]);

  return (
    <Shell>
      <OfflineBanner show={fromCache} />
      {shopSettings?.header_image ? (
        <img src={mediaUrl(shopSettings.header_image)} alt="" style={{ borderRadius: 12, marginBottom: 10, maxHeight: 140, objectFit: 'cover', width: '100%' }} />
      ) : null}
      <h1 className="h1">{shopSettings?.title || 'فروشگاه'}</h1>
      {shopSettings?.explanation ? <p className="muted">{shopSettings.explanation}</p> : null}
      <p className="muted">{total} کالا</p>
      <input className="input" placeholder="جستجو نام، کد، کارخانه…" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && refresh(1)} />
      <div className="chip-row">
        {[
          ['', 'همه'],
          ['new', 'جدید'],
          ['off', 'تخفیف'],
        ].map(([v, l]) => (
          <button key={v} className={`chip ${banner === v ? 'on' : ''}`} type="button" onClick={() => { setBanner(v); refresh(1, { banner: v }); }}>
            {l}
          </button>
        ))}
        <button className="chip" type="button" onClick={() => nav('/shop/filters')}>فیلترها</button>
        <button className="chip" type="button" onClick={() => nav('/price-sheet')}>لیست قیمت</button>
      </div>
      {series.map((s) => (
        <Link key={`s-${s.id}`} className="card row" to={`/series/${s.id}`}>
          {s.image ? <img className="thumb" src={mediaUrl(s.image)} alt="" /> : <div className="thumb" />}
          <div>
            <strong>{s.name}</strong>
            <div className="muted">کیت</div>
            <div className="price">{s.price_text || ''}</div>
          </div>
        </Link>
      ))}
      <div className="grid">
        {items.map((p) => (
          <div key={p.id} className="card product-card">
            <Link to={`/product/${p.id}`}>
              <img src={mediaUrl(p.display_image || p.image)} alt="" />
              <strong>{p.name}</strong>
            </Link>
            <div className="muted">{p.visual_id}</div>
            <div className="price">{p.price_text}</div>
            {p.orderable ? (
              <button
                className="btn"
                type="button"
                onClick={() =>
                  addToCart({
                    id: p.id,
                    name: p.name,
                    slug: p.slug,
                    quantity: 1,
                    unit_type: 'piece',
                    pack_size: p.pack_size || null,
                    price_text: p.price_text || null,
                    image: p.display_image || p.image || null,
                    visual_id: p.visual_id || null,
                    factory_name: p.factory_name || null,
                    category_name: p.category_name || null,
                    orderable: true,
                  })
                }
              >
                افزودن
              </button>
            ) : (
              <div className="muted">ناموجود</div>
            )}
          </div>
        ))}
      </div>
      {items.length < total ? (
        <button className="btn ghost" type="button" onClick={() => refresh(page + 1)}>بیشتر</button>
      ) : null}
    </Shell>
  );
}
