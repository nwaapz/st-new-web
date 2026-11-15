import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { NeedNet, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { mediaUrl } from '../utils/media';
import { parseToman } from '../utils/status';

export function CartScreen() {
  const { cart, setQty, clearCart, submitOrder, onlineForWrite, shopSettings } = useApp();
  const nav = useNavigate();
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const hidePrice = !!shopSettings?.call_for_price;
  const total = cart.reduce((n, l) => n + parseToman(l.price_text) * l.quantity, 0);

  return (
    <Shell footer={false}>
      <h1 className="h1">سبد خرید</h1>
      {err ? <div className="warn">{err}</div> : null}
      {!onlineForWrite ? <NeedNet /> : null}
      {cart.length === 0 ? <div className="empty">سبد خالی است</div> : null}
      {cart.map((l) => (
        <div key={`${l.id}-${l.flash_deal_id || 0}`} className="card row">
          {l.image ? <img className="thumb" src={mediaUrl(l.image)} alt="" /> : <div className="thumb" />}
          <div className="grow">
            <strong>{l.name}</strong>
            <div className="muted">{l.unit_type === 'pack' ? 'بسته' : 'عدد'} {l.flash_deal_id ? '· پیشنهاد ویژه' : ''}</div>
            {!hidePrice ? <div className="price">{l.price_text}</div> : null}
            <div className="stepper">
              <button type="button" onClick={() => setQty(l.id, l.flash_deal_id, l.quantity - 1)}>−</button>
              <span>{l.quantity}</span>
              <button type="button" onClick={() => setQty(l.id, l.flash_deal_id, l.quantity + 1)}>+</button>
            </div>
          </div>
        </div>
      ))}
      {!hidePrice && cart.length > 0 ? <p className="price">جمع: {total.toLocaleString('fa-IR')}</p> : null}
      {cart.length > 0 ? (
        <>
          <button className="btn ghost" type="button" onClick={() => { if (window.confirm('سبد پاک شود؟')) clearCart(); }}>حذف همه</button>
          <button
            className="btn"
            disabled={busy || !onlineForWrite}
            type="button"
            onClick={() => {
              setBusy(true);
              setErr('');
              void submitOrder()
                .then((order) => nav(`/orders/${order.id}`))
                .catch((e: Error) => setErr(e.message))
                .finally(() => setBusy(false));
            }}
          >
            ثبت سفارش
          </button>
        </>
      ) : null}
    </Shell>
  );
}
