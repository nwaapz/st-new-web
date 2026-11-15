import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import type { FlashOffer } from '../api/types';
import { NeedNet, OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { mediaUrl } from '../utils/media';

export function DiscountsScreen() {
  const { loadFlash, addToCart, onlineForWrite } = useApp();
  const nav = useNavigate();
  const [offers, setOffers] = useState<FlashOffer[]>([]);
  const [fromCache, setFromCache] = useState(false);

  useEffect(() => {
    void loadFlash().then((r) => {
      setOffers(r.offers);
      setFromCache(r.fromCache);
    });
  }, [loadFlash]);

  return (
    <Shell>
      <h1 className="h1">تخفیف‌ها</h1>
      <button className="chip" type="button" onClick={() => nav('/festival')}>جشنواره</button>
      <OfflineBanner show={fromCache} />
      {fromCache ? <div className="warn">این فهرست ممکن است قدیمی باشد و تا اتصال مجدد قابل خرید نیست.</div> : null}
      {!onlineForWrite ? <NeedNet /> : null}
      {offers.map((o) => (
        <div key={o.flash_deal_id} className="card">
          {o.image ? <img src={mediaUrl(o.image)} alt="" style={{ borderRadius: 10, maxHeight: 160, objectFit: 'cover', width: '100%' }} /> : null}
          <strong>{o.name}</strong>
          <div><span className="strike">{o.regular_price_text}</span> <span className="price">{o.promo_price_text}</span></div>
          <div className="muted">تا {o.ends_at_jalali || o.ends_at}</div>
          {o.is_pack ? (
            <ul className="muted">
              {(o.products || []).map((p, i) => <li key={i}>{String(p.name)}</li>)}
            </ul>
          ) : null}
          <button
            className="btn"
            disabled={!o.purchasable || fromCache || !onlineForWrite}
            type="button"
            onClick={() => {
              const products = o.products && o.products.length ? o.products : [o];
              for (const p of products) {
                addToCart({
                  id: Number(p.product_id || o.product_id || 0),
                  name: String(p.name || o.name),
                  slug: String(p.slug || ''),
                  quantity: 1,
                  unit_type: 'piece',
                  price_text: String(p.promo_price_text || o.promo_price_text || ''),
                  image: (p.image as string) || o.image || null,
                  flash_deal_id: o.flash_deal_id,
                  expected_promo_toman: Number(p.promo_unit_price || o.promo_unit_price || 0),
                });
              }
              nav('/cart');
            }}
          >
            خرید
          </button>
        </div>
      ))}
      {offers.length === 0 ? <div className="empty">پیشنهاد ویژه‌ای نیست</div> : null}
    </Shell>
  );
}
