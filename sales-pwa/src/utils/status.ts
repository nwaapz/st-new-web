export const STATUS_LABELS: Record<string, string> = {
  submitted: 'ارسال‌شده از مشتری',
  accepted: 'تأیید انبار',
  rejected: 'بسته شده — رد انبار',
  cancelled: 'لغو شده',
  payment_proof_sent: 'مدارک پرداخت',
  paid: 'پرداخت شده',
  shipped: 'ارسال مرسوله',
  not_received: 'هنوز دریافت نشده',
  returned_to_origin: 'برگشت به مبدأ',
  lost: 'مفقود',
  received: 'دریافت‌شده',
};

export const PIPELINE = [
  { key: 'submitted', label: 'ثبت سفارش' },
  { key: 'accepted', label: 'بررسی انبار' },
  { key: 'paid', label: 'پرداخت' },
  { key: 'shipped', label: 'ارسال' },
  { key: 'received', label: 'دریافت' },
];

const PIPE_INDEX: Record<string, number> = {
  submitted: 0,
  accepted: 1,
  payment_proof_sent: 1,
  paid: 2,
  shipped: 3,
  not_received: 3,
  returned_to_origin: 3,
  lost: 3,
  received: 4,
};

export function pipelineState(status: string): Array<'done' | 'now' | 'pending'> {
  const idx = PIPE_INDEX[status];
  if (idx === undefined) return PIPELINE.map(() => 'pending');
  return PIPELINE.map((_, i) => (i < idx ? 'done' : i === idx ? 'now' : 'pending'));
}

export function parseToman(text?: string | null): number {
  if (!text) return 0;
  const digits = text.replace(/[^\d]/g, '');
  return digits ? Number(digits) : 0;
}
