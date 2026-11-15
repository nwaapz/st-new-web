import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { NeedNet, OfflineBanner, Shell } from '../components/Chrome';
import { useApp } from '../state/AppState';
import { formatJalali } from '../utils/jalali';
import { mediaUrl } from '../utils/media';

export function MessagesScreen() {
  const { loadDirect, sendDirect, onlineForWrite, server } = useApp();
  const nav = useNavigate();
  const [messages, setMessages] = useState<Array<Record<string, unknown>>>([]);
  const [fromCache, setFromCache] = useState(false);
  const [text, setText] = useState('');
  const [err, setErr] = useState('');

  const reload = () => {
    void loadDirect().then((r) => {
      setMessages(r.messages);
      setFromCache(r.fromCache);
    });
  };

  useEffect(() => {
    reload();
    const t = window.setInterval(reload, 4000);
    return () => window.clearInterval(t);
  }, [loadDirect]);

  return (
    <Shell>
      <h1 className="h1">پیام‌ها / دفتر</h1>
      <p className="muted">{server ? 'آنلاین' : 'در حال اتصال'}</p>
      <OfflineBanner show={fromCache} />
      {err ? <div className="warn">{err}</div> : null}
      {!onlineForWrite ? <NeedNet /> : null}
      {messages.map((m) => {
        const orderId = Number(m.order_id || 0);
        return (
          <div
            key={String(m.id)}
            className={`msg ${m.sender_role === 'sales' ? 'me' : 'them'}`}
            onClick={() => { if (orderId) nav(`/orders/${orderId}`); }}
          >
            <div className="muted">{String(m.sender_name || '')} · {formatJalali(String(m.created_at || ''))}</div>
            {m.image ? <img src={mediaUrl(String(m.image))} alt="" /> : null}
            <div>{String(m.body || m.text || '')}</div>
          </div>
        );
      })}
      <div className="composer">
        <input className="input" value={text} onChange={(e) => setText(e.target.value)} placeholder="پیام متنی" />
        <button
          className="btn"
          disabled={!onlineForWrite || !text.trim()}
          type="button"
          onClick={() => {
            const body = text.trim();
            setText('');
            void sendDirect(body).then(reload).catch((e: Error) => setErr(e.message));
          }}
        >
          ارسال
        </button>
      </div>
    </Shell>
  );
}
