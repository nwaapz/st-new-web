import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import type { OrderDetail } from '../api/types';
import { NeedNet, OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { formatJalali } from '../utils/jalali';
import { mediaUrl } from '../utils/media';
import { PIPELINE, pipelineState, STATUS_LABELS } from '../utils/status';

export function OrderScreen() {
  const { orderId } = useParams();
  const id = Number(orderId);
  const [sp] = useSearchParams();
  const { loadOrder, loadMessages, sendOrderMessage, cancelOrder, confirmAdjustments, submitPayment, addToCart, onlineForWrite } =
    useApp();
  const nav = useNavigate();
  const [order, setOrder] = useState<OrderDetail | null>(null);
  const [fromCache, setFromCache] = useState(false);
  const [messages, setMessages] = useState<Array<Record<string, unknown>>>([]);
  const [text, setText] = useState('');
  const [note, setNote] = useState('');
  const [ref, setRef] = useState('');
  const [files, setFiles] = useState<File[]>([]);
  const [err, setErr] = useState('');

  const refresh = () => {
    if (!id) return;
    void loadOrder(id).then((r) => {
      setOrder(r.order);
      setFromCache(r.fromCache);
    });
    void loadMessages(id).then((r) => setMessages(r.messages));
  };

  useEffect(() => {
    refresh();
    const t = window.setInterval(refresh, 4000);
    return () => window.clearInterval(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  useEffect(() => {
    if (sp.get('focus') === 'chat') {
      document.getElementById('chat')?.scrollIntoView({ behavior: 'smooth' });
    }
  }, [sp, messages.length]);

  if (!order) return <Shell footer={false}><p className="muted">در حال بارگذاری…</p></Shell>;
  const pipe = pipelineState(order.status);
  const closed = ['rejected', 'cancelled', 'received'].includes(order.status);
  const canPay = order.payment_method === 'cash' && ['accepted', 'payment_proof_sent'].includes(order.status);

  return (
    <Shell footer={false}>
      <OfflineBanner show={fromCache} />
      {err ? <div className="warn">{err}</div> : null}
      <h1 className="h1">{order.public_code}</h1>
      <div className="status-pill">{STATUS_LABELS[order.status] || order.status}</div>
      {order.sms_channel ? <p className="muted">این سفارش از مسیر پیامک ثبت شده است.</p> : null}
      <div className="timeline">
        {PIPELINE.map((p, i) => (
          <span key={p.key} className={pipe[i]}>{p.label}</span>
        ))}
      </div>
      {order.payment_warning ? <div className="warn">{order.payment_warning}</div> : null}
      {order.items_adjustment_pending ? (
        <div className="card">
          <p>انبار تعداد اقلام را تغییر داده است. تأیید کنید.</p>
          <button className="btn" disabled={!onlineForWrite} type="button" onClick={() => void confirmAdjustments(id).then(setOrder).catch((e: Error) => setErr(e.message))}>
            تأیید تعداد اقلام
          </button>
        </div>
      ) : null}
      {(order.items || []).map((it) => {
        const pid = it.product_id as number | null;
        const inner = (
          <>
            <div>
              <strong>{String(it.name)}</strong>
              <div className="muted">{String(it.quantity)} {it.unit_type === 'pack' ? 'بسته' : 'عدد'}</div>
              {it.quantity_adjusted ? <div className="muted">تعداد تعدیل شده (قبل: {String(it.original_quantity)})</div> : null}
            </div>
            <div className="price">{String(it.price_text || '')}</div>
          </>
        );
        return pid ? (
          <Link key={String(it.id)} className="card row" to={pid < 0 ? `/series/${Math.abs(pid)}` : `/product/${pid}`}>{inner}</Link>
        ) : (
          <div key={String(it.id)} className="card row">{inner}</div>
        );
      })}
      {(order.cheques || []).length > 0 ? (
        <div className="card">
          <strong>چک‌ها</strong>
          {(order.cheques || []).map((c, i) => (
            <p key={i} className="muted">
              {String(c.serial || c.cheque_serial || '')} · {String(c.amount_text || c.amount || '')} · {String(c.status || '')}
            </p>
          ))}
        </div>
      ) : null}
      {order.pre_invoice_file ? <a className="btn ghost" href={mediaUrl(order.pre_invoice_file)} target="_blank" rel="noreferrer">پیش‌فاکتور</a> : null}
      {order.final_invoice_file ? <a className="btn ghost" href={mediaUrl(order.final_invoice_file)} target="_blank" rel="noreferrer">فاکتور</a> : null}
      {canPay ? (
        <div className="card">
          <h2 className="h1">مدارک پرداخت نقدی</h2>
          <textarea className="textarea" placeholder="توضیح" value={note} onChange={(e) => setNote(e.target.value)} />
          <input className="input" placeholder="شماره پیگیری" value={ref} onChange={(e) => setRef(e.target.value)} />
          <input className="input" type="file" multiple accept="image/*,.pdf" onChange={(e) => setFiles(Array.from(e.target.files || []).slice(0, 9))} />
          <button className="btn" disabled={!onlineForWrite} type="button" onClick={() => void submitPayment(id, note, ref, files).then(setOrder).catch((e: Error) => setErr(e.message))}>ارسال مدارک</button>
        </div>
      ) : null}
      <div className="chip-row">
        <button
          className="chip"
          type="button"
          onClick={() => {
            for (const it of order.items || []) {
              const pid = Number(it.product_id || 0);
              if (!pid) continue;
              addToCart({
                id: pid,
                name: String(it.name),
                quantity: Number(it.quantity || 1),
                unit_type: it.unit_type === 'pack' ? 'pack' : 'piece',
                pack_size: (it.pack_size as number) || null,
                price_text: (it.price_text as string) || null,
                image: (it.image as string) || null,
              });
            }
            nav('/cart');
          }}
        >
          خرید مجدد
        </button>
        {['submitted', 'accepted'].includes(order.status) ? (
          <button className="chip" type="button" disabled={!onlineForWrite} onClick={() => { if (window.confirm('لغو شود؟')) void cancelOrder(id).then(setOrder).catch((e: Error) => setErr(e.message)); }}>لغو سفارش</button>
        ) : null}
      </div>
      {!onlineForWrite ? <NeedNet /> : null}
      <h2 className="h1" id="chat">گفتگو با انبار</h2>
      {messages.map((m) => (
        <div key={String(m.id)} className={`msg ${m.sender_role === 'sales' ? 'me' : 'them'}`}>
          <div className="muted">{String(m.sender_name || '')} · {formatJalali(String(m.created_at || ''))}</div>
          {m.image ? <img src={mediaUrl(String(m.image))} alt="" /> : null}
          <div>{String(m.body || '')}</div>
        </div>
      ))}
      {!closed ? (
        <div className="composer no-tab">
          <input className="input" value={text} onChange={(e) => setText(e.target.value)} placeholder="پیام" />
          <button
            className="btn"
            disabled={!onlineForWrite || !text.trim()}
            type="button"
            onClick={() => {
              const body = text.trim();
              setText('');
              void sendOrderMessage(id, body).then(refresh).catch((e: Error) => setErr(e.message));
            }}
          >
            ارسال
          </button>
        </div>
      ) : null}
    </Shell>
  );
}
