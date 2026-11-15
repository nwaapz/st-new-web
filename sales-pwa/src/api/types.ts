export type SalesUser = {
  id: number;
  username: string;
  display_name: string;
  sms_phone?: string;
  branch_id: number | null;
};

export type ShopSettings = {
  call_for_price?: boolean;
  call_for_price_label?: string;
  header_image?: string | null;
  title?: string;
  explanation?: string;
};

export type Product = {
  id: number;
  name: string;
  slug?: string;
  visual_id?: string | null;
  price_text?: string | null;
  image?: string | null;
  display_image?: string | null;
  shop_display_image?: string | null;
  description?: string | null;
  factory_name?: string | null;
  model_name?: string | null;
  category_name?: string | null;
  category_ids?: number[];
  factory_id?: number;
  car_model_id?: number;
  banner?: string;
  pack_size?: number | null;
  orderable?: boolean;
  dim_length?: string | null;
  dim_width?: string | null;
  dim_height?: string | null;
  dim_weight?: string | null;
  [key: string]: unknown;
};

export type Series = {
  id: number;
  name: string;
  slug?: string;
  visual_id?: string | null;
  price_text?: string | null;
  image?: string | null;
  description?: string | null;
  pack_size?: number | null;
  banner?: string;
  category_ids?: number[];
  category_name?: string | null;
  orderable?: boolean;
  product_ids?: number[];
  parts?: Array<Record<string, unknown>>;
  products?: Product[];
  [key: string]: unknown;
};

export type NamedItem = { id: number; name: string; image?: string | null; factory_id?: number };

export type CartLine = {
  id: number;
  name: string;
  slug?: string;
  quantity: number;
  unit_type: 'piece' | 'pack';
  pack_size?: number | null;
  price_text?: string | null;
  image?: string | null;
  factory_name?: string | null;
  model_name?: string | null;
  category_name?: string | null;
  visual_id?: string | null;
  flash_deal_id?: number | null;
  expected_promo_toman?: number | null;
  orderable?: boolean;
};

export type OrderListRow = {
  id: number;
  public_code: string;
  status: string;
  phone?: string;
  created_at?: string;
  item_count?: number;
  first_item_name?: string | null;
  has_flash?: boolean;
  [key: string]: unknown;
};

export type OrderDetail = OrderListRow & {
  items?: Array<Record<string, unknown>>;
  events?: Array<Record<string, unknown>>;
  cheques?: Array<Record<string, unknown>>;
  payment_method?: string | null;
  payment_warning?: string | null;
  payment_note?: string | null;
  payment_files?: string[];
  payment_reference?: string | null;
  pre_invoice_file?: string | null;
  final_invoice_file?: string | null;
  items_adjustment_pending?: boolean;
  sms_channel?: boolean;
};

export type ChatMessage = {
  id: number;
  body?: string;
  image?: string | null;
  sender_name?: string;
  sender_role?: string;
  created_at?: string;
  [key: string]: unknown;
};

export type FlashOffer = {
  flash_deal_id: number;
  name: string;
  image?: string | null;
  purchasable?: boolean;
  is_pack?: boolean;
  promo_price_text?: string;
  regular_price_text?: string;
  promo_unit_price?: number;
  ends_at?: string;
  ends_at_jalali?: string;
  products?: Array<Record<string, unknown>>;
  [key: string]: unknown;
};

export type Cached<T> = { data: T; fromCache: boolean };
