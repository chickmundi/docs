-- Marché Chemin-du-Roy: core commerce schema.
-- Product, variation and category IDs keep the WooCommerce IDs of the existing catalog.
-- Money is stored in cents (CAD). All timestamps are timestamptz.

-- ---------- Staff and roles ----------
create type staff_role as enum ('owner', 'manager', 'staff');

create table staff (
  user_id uuid primary key references auth.users (id) on delete cascade,
  full_name text not null,
  role staff_role not null default 'staff',
  active boolean not null default true,
  created_at timestamptz not null default now()
);

create or replace function is_staff() returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from staff where user_id = auth.uid() and active);
$$;

create or replace function is_manager() returns boolean
language sql stable security definer set search_path = public as $$
  select exists (select 1 from staff where user_id = auth.uid() and active and role in ('owner', 'manager'));
$$;

create or replace function touch_updated_at() returns trigger language plpgsql as $$
begin
  new.updated_at = now();
  return new;
end $$;

-- ---------- Catalog ----------
create table departments (
  id smallint primary key,
  slug text not null unique,
  name_fr text not null,
  name_en text not null,
  sort smallint not null default 0
);

create table categories (
  id bigint primary key,                       -- WooCommerce term ID for existing categories
  slug text not null unique,
  name_fr text not null,
  name_en text,
  description_fr text,
  description_en text,
  parent_id bigint references categories (id) on delete set null,
  department_id smallint references departments (id),
  image_url text,
  icon text,                                   -- storefront icon name
  sort integer not null default 0,
  visible boolean not null default true,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create sequence categories_id_seq start 100000 owned by categories.id;
alter table categories alter column id set default nextval('categories_id_seq');
create trigger categories_touch before update on categories for each row execute function touch_updated_at();

create table brands (
  id bigserial primary key,
  name text not null unique,
  slug text not null unique,
  created_at timestamptz not null default now()
);

create type product_status as enum ('published', 'draft', 'archived');
create type sale_unit as enum ('unit', 'package', 'weight', 'other');
create type cold_chain as enum ('ambient', 'refrigerated', 'frozen');

create table products (
  id bigint primary key,                       -- WooCommerce product ID for existing products
  slug text not null unique,
  legacy_slug text,                            -- original WooCommerce slug, for redirects
  status product_status not null default 'published',
  name_fr text not null,
  name_en text,
  short_description_fr text,
  short_description_en text,
  description_fr text,
  description_en text,
  department_id smallint references departments (id),
  brand_id bigint references brands (id) on delete set null,
  has_variants boolean not null default false,
  -- Price of the simple product, or "from" price of a product with variations (kept in sync).
  price_cents integer check (price_cents >= 0),
  regular_price_cents integer check (regular_price_cents >= 0),
  sale_starts_at timestamptz,
  sale_ends_at timestamptz,
  -- How it is sold
  sale_unit sale_unit not null default 'unit',
  unit_label text,                             -- e.g. "par kg", "paquet de 6"
  -- Inventory (simple products; variations have their own)
  sku text,
  barcode text,
  track_stock boolean not null default false,
  stock_qty numeric(12, 3),
  low_stock_threshold numeric(12, 3),
  in_stock boolean not null default true,      -- used when stock is not tracked
  track_expiry boolean not null default false, -- perishable: lots with expiry dates
  -- Grocery attributes
  weight_value numeric(12, 3),
  net_quantity text,
  measure_unit text check (measure_unit in ('g', 'kg', 'ml', 'L', 'units')),
  packaging text,
  country_of_origin text,
  ingredients text,
  allergens text,
  storage_instructions text,
  cold_chain cold_chain,
  -- Beauty and hair attributes (product-level; variations override color/length/etc.)
  hair_type text,
  hair_length text,
  hair_color text,
  hair_texture text,
  material text,
  style text,
  product_size text,
  extra_attributes jsonb not null default '{}'::jsonb,
  tags text[] not null default '{}',
  -- SEO
  seo_title text,
  seo_description text,
  featured boolean not null default false,
  source_url text,                             -- page on the WooCommerce site it was imported from
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  constraint sale_window check (sale_ends_at is null or sale_starts_at is null or sale_ends_at > sale_starts_at)
);
create sequence products_id_seq start 100000 owned by products.id;
alter table products alter column id set default nextval('products_id_seq');
create unique index products_sku_key on products (sku) where sku is not null and sku <> '';
create index products_department_idx on products (department_id);
create index products_status_idx on products (status);
create trigger products_touch before update on products for each row execute function touch_updated_at();

create table product_categories (
  product_id bigint references products (id) on delete cascade,
  category_id bigint references categories (id) on delete cascade,
  primary key (product_id, category_id)
);
create index product_categories_category_idx on product_categories (category_id);

create table product_options (
  id bigserial primary key,
  product_id bigint not null references products (id) on delete cascade,
  name text not null,                          -- e.g. "Couleur", "Longueur"
  values_list text[] not null default '{}',
  sort smallint not null default 0,
  unique (product_id, name)
);

create table product_variants (
  id bigint primary key,                       -- WooCommerce variation ID for existing variations
  product_id bigint not null references products (id) on delete cascade,
  options jsonb not null default '{}'::jsonb,  -- {"Couleur": "noir", "Longueur": "24\""}
  sku text,
  barcode text,
  price_cents integer check (price_cents >= 0),
  regular_price_cents integer check (regular_price_cents >= 0),
  track_stock boolean not null default false,
  stock_qty numeric(12, 3),
  low_stock_threshold numeric(12, 3),
  in_stock boolean not null default true,
  image_url text,
  active boolean not null default true,
  sort integer not null default 0,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create sequence product_variants_id_seq start 100000 owned by product_variants.id;
alter table product_variants alter column id set default nextval('product_variants_id_seq');
create unique index product_variants_sku_key on product_variants (sku) where sku is not null and sku <> '';
create index product_variants_product_idx on product_variants (product_id);
create trigger product_variants_touch before update on product_variants for each row execute function touch_updated_at();

create table product_images (
  id bigserial primary key,
  product_id bigint not null references products (id) on delete cascade,
  variant_id bigint references product_variants (id) on delete cascade,
  url text not null,
  alt text,
  source_url text,
  sort integer not null default 0
);
create index product_images_product_idx on product_images (product_id);

-- Lots and expiry dates for perishable products (optional per product)
create table inventory_lots (
  id bigserial primary key,
  product_id bigint not null references products (id) on delete cascade,
  variant_id bigint references product_variants (id) on delete cascade,
  lot_number text,
  expiry_date date,
  quantity numeric(12, 3) not null default 0,
  received_at date default current_date,
  notes text,
  created_at timestamptz not null default now()
);
create index inventory_lots_expiry_idx on inventory_lots (expiry_date) where quantity > 0;

-- Every stock change, for traceability
create table stock_movements (
  id bigserial primary key,
  product_id bigint not null references products (id) on delete cascade,
  variant_id bigint references product_variants (id) on delete cascade,
  change numeric(12, 3) not null,
  reason text not null,                        -- 'order', 'cancel', 'adjustment', 'receiving', 'import'
  order_id bigint,
  note text,
  actor uuid,
  created_at timestamptz not null default now()
);

-- ---------- Customers ----------
create table customers (
  id uuid primary key default gen_random_uuid(),
  auth_user_id uuid unique references auth.users (id) on delete set null,
  first_name text,
  last_name text,
  email text,
  phone text,
  preferred_language text not null default 'fr',
  marketing_consent boolean not null default false,
  notes text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);
create index customers_email_idx on customers (lower(email));
create index customers_phone_idx on customers (phone);
create trigger customers_touch before update on customers for each row execute function touch_updated_at();

create table customer_addresses (
  id bigserial primary key,
  customer_id uuid not null references customers (id) on delete cascade,
  label text,
  line1 text not null,
  line2 text,
  city text not null,
  postal_code text,
  province text not null default 'QC',
  lat double precision,
  lon double precision,
  is_default boolean not null default false
);

-- ---------- Promotions and coupons ----------
create type discount_type as enum ('percent', 'fixed', 'free_delivery');

create table coupons (
  id bigserial primary key,
  code text not null unique,
  description text,
  discount_type discount_type not null,
  value numeric(10, 2) not null default 0,     -- percent (e.g. 10) or dollars (e.g. 5.00)
  min_subtotal_cents integer not null default 0,
  starts_at timestamptz,
  ends_at timestamptz,
  usage_limit integer,
  usage_count integer not null default 0,
  per_customer_limit integer,
  category_ids bigint[] not null default '{}', -- empty = whole store
  active boolean not null default true,
  created_at timestamptz not null default now()
);

create table promotions (
  id bigserial primary key,
  name text not null,
  percent_off numeric(5, 2) not null check (percent_off > 0 and percent_off < 100),
  category_ids bigint[] not null default '{}',
  product_ids bigint[] not null default '{}',
  starts_at timestamptz not null,
  ends_at timestamptz not null,
  active boolean not null default true,
  created_at timestamptz not null default now()
);

-- ---------- Orders ----------
create type fulfillment_method as enum ('delivery', 'pickup');
create type fulfillment_status as enum (
  'received', 'confirmed', 'preparing', 'ready_for_pickup', 'out_for_delivery', 'delivered', 'collected', 'cancelled'
);
create type payment_method as enum ('card_online', 'pay_on_delivery', 'pay_in_store');
create type payment_status as enum ('unpaid', 'pending', 'paid', 'partially_refunded', 'refunded', 'failed');
create type order_channel as enum ('web', 'whatsapp', 'email', 'phone', 'in_store');

create sequence order_number_seq start 10001;

create table orders (
  id bigserial primary key,
  number text not null unique default ('MCR-' || nextval('order_number_seq')),
  customer_id uuid references customers (id) on delete set null,
  channel order_channel not null default 'web',
  contact_name text not null,
  contact_phone text not null,
  contact_email text,
  fulfillment_method fulfillment_method not null,
  fulfillment_status fulfillment_status not null default 'received',
  payment_method payment_method not null,
  payment_status payment_status not null default 'unpaid',
  subtotal_cents integer not null default 0,
  discount_cents integer not null default 0,
  delivery_fee_cents integer not null default 0,
  tax_cents integer not null default 0,
  total_cents integer not null default 0,
  coupon_code text,
  -- Delivery
  delivery_address jsonb,                      -- {line1, line2, city, postal_code, lat, lon}
  delivery_distance_km numeric(6, 2),
  distance_method text,                        -- 'road', 'straight_line', 'manual'
  delivery_fee_confirmed boolean not null default true,
  requested_for timestamptz,                   -- desired delivery/pickup time
  pickup_slot_start timestamptz,
  pickup_slot_end timestamptz,
  customer_note text,
  internal_note text,
  cancelled_reason text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  completed_at timestamptz
);
create index orders_created_idx on orders (created_at desc);
create index orders_status_idx on orders (fulfillment_status);
create trigger orders_touch before update on orders for each row execute function touch_updated_at();

create table order_items (
  id bigserial primary key,
  order_id bigint not null references orders (id) on delete cascade,
  product_id bigint references products (id) on delete set null,
  variant_id bigint references product_variants (id) on delete set null,
  name text not null,                          -- snapshot at time of order
  options jsonb,
  sku text,
  department_id smallint,
  category_id bigint,
  unit_price_cents integer not null,
  quantity numeric(12, 3) not null check (quantity > 0),
  unit_label text,
  line_total_cents integer not null
);
create index order_items_order_idx on order_items (order_id);
create index order_items_product_idx on order_items (product_id);

create table payments (
  id bigserial primary key,
  order_id bigint not null references orders (id) on delete cascade,
  method payment_method not null,
  provider text,                               -- 'stripe', 'cash', 'debit_terminal', ...
  provider_reference text,                     -- Stripe payment intent ID, receipt number, ...
  amount_cents integer not null,               -- negative for refunds
  status text not null default 'succeeded',    -- 'pending', 'succeeded', 'failed', 'refunded'
  recorded_by uuid,
  note text,
  created_at timestamptz not null default now()
);
create index payments_order_idx on payments (order_id);

create table order_events (
  id bigserial primary key,
  order_id bigint not null references orders (id) on delete cascade,
  kind text not null,                          -- 'status', 'payment', 'note', 'notification'
  from_value text,
  to_value text,
  note text,
  actor uuid,
  created_at timestamptz not null default now()
);
create index order_events_order_idx on order_events (order_id);

-- ---------- Settings and content ----------
create table settings (
  key text primary key,                        -- 'store', 'store_hours', 'delivery', 'pickup', 'payments', 'announcement'
  value jsonb not null,
  is_public boolean not null default true,     -- readable by the storefront
  updated_at timestamptz not null default now(),
  updated_by uuid
);

create table homepage_sections (
  key text primary key,                        -- 'hero', 'categories', 'promotions', 'new', 'epicerie', ...
  title_fr text,
  title_en text,
  eyebrow_fr text,
  eyebrow_en text,
  body_fr text,
  mode text not null default 'auto' check (mode in ('auto', 'manual')),
  category_id bigint references categories (id) on delete set null,
  product_ids bigint[] not null default '{}',
  max_items smallint not null default 12,
  visible boolean not null default true,
  sort smallint not null default 0,
  updated_at timestamptz not null default now()
);

create table audit_log (
  id bigserial primary key,
  actor uuid,
  action text not null,
  entity text not null,
  entity_id text,
  details jsonb,
  created_at timestamptz not null default now()
);
