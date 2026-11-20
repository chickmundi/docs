-- Reports (staff only, computed from real orders) and initial settings.

-- Orders that count as sales: not cancelled.
create or replace view sales_orders with (security_invoker = true) as
  select * from orders where fulfillment_status <> 'cancelled';

create or replace function dashboard_summary(tz text default 'America/Toronto')
returns jsonb language plpgsql stable security invoker set search_path = public as $$
declare
  today_start timestamptz := date_trunc('day', now() at time zone tz) at time zone tz;
  week_start timestamptz := date_trunc('week', now() at time zone tz) at time zone tz;
  month_start timestamptz := date_trunc('month', now() at time zone tz) at time zone tz;
  result jsonb;
begin
  if not is_staff() then raise exception 'not allowed'; end if;
  select jsonb_build_object(
    'sales_today_cents', coalesce(sum(total_cents) filter (where created_at >= today_start), 0),
    'sales_week_cents', coalesce(sum(total_cents) filter (where created_at >= week_start), 0),
    'sales_month_cents', coalesce(sum(total_cents) filter (where created_at >= month_start), 0),
    'orders_month', count(*) filter (where created_at >= month_start),
    'avg_order_cents', coalesce(round(avg(total_cents) filter (where created_at >= month_start)), 0),
    'delivery_month', count(*) filter (where created_at >= month_start and fulfillment_method = 'delivery'),
    'pickup_month', count(*) filter (where created_at >= month_start and fulfillment_method = 'pickup')
  ) into result from sales_orders;
  result := result || (
    select jsonb_build_object(
      'awaiting_preparation', count(*) filter (where fulfillment_status in ('received', 'confirmed', 'preparing')),
      'awaiting_pickup', count(*) filter (where fulfillment_status = 'ready_for_pickup'),
      'awaiting_delivery', count(*) filter (where fulfillment_method = 'delivery' and fulfillment_status in ('received', 'confirmed', 'preparing', 'out_for_delivery')),
      'delivered_month', count(*) filter (where fulfillment_status = 'delivered' and completed_at >= month_start),
      'cancelled_month', count(*) filter (where fulfillment_status = 'cancelled' and updated_at >= month_start),
      'unpaid_open', count(*) filter (where payment_status in ('unpaid', 'pending') and fulfillment_status <> 'cancelled')
    ) from orders
  );
  return result;
end $$;

-- Sales per day over a period. Whole orders by default; with a department, only that department's lines.
create or replace function sales_by_day(from_date date, to_date date, dept smallint default null, tz text default 'America/Toronto')
returns table (day date, orders bigint, revenue_cents bigint)
language sql stable security invoker set search_path = public as $$
  with days as (
    select generate_series(from_date, to_date, interval '1 day')::date as d
  ),
  o as (
    select (created_at at time zone tz)::date as d, id, total_cents
    from sales_orders
    where created_at >= (from_date::timestamp at time zone tz)
      and created_at < ((to_date + 1)::timestamp at time zone tz)
  ),
  whole as (
    select d, count(*) as orders, sum(total_cents) as revenue from o group by d
  ),
  by_dept as (
    select o.d, count(distinct o.id) as orders, sum(i.line_total_cents) as revenue
    from o join order_items i on i.order_id = o.id
    where i.department_id = dept
    group by o.d
  )
  select days.d,
         coalesce(case when dept is null then whole.orders else by_dept.orders end, 0)::bigint,
         coalesce(case when dept is null then whole.revenue else by_dept.revenue end, 0)::bigint
  from days
  left join whole on whole.d = days.d
  left join by_dept on by_dept.d = days.d
  order by days.d;
$$;

-- Best sellers over a period, optionally for one department.
create or replace function top_products(from_ts timestamptz, to_ts timestamptz, dept smallint default null, lim integer default 10)
returns table (product_id bigint, name text, quantity numeric, revenue_cents bigint)
language sql stable security invoker set search_path = public as $$
  select i.product_id, max(i.name), sum(i.quantity), sum(i.line_total_cents)::bigint
  from order_items i join sales_orders o on o.id = i.order_id
  where o.created_at >= from_ts and o.created_at < to_ts and (dept is null or i.department_id = dept)
  group by i.product_id
  order by sum(i.line_total_cents) desc
  limit lim;
$$;

create or replace function top_categories(from_ts timestamptz, to_ts timestamptz, lim integer default 10)
returns table (category_id bigint, name text, revenue_cents bigint, orders bigint)
language sql stable security invoker set search_path = public as $$
  select c.id, c.name_fr, sum(i.line_total_cents)::bigint, count(distinct o.id)
  from order_items i
  join sales_orders o on o.id = i.order_id
  join categories c on c.id = i.category_id
  where o.created_at >= from_ts and o.created_at < to_ts
  group by c.id, c.name_fr
  order by 3 desc
  limit lim;
$$;

create or replace function sales_by_department(from_ts timestamptz, to_ts timestamptz)
returns table (department_id smallint, name text, revenue_cents bigint, items numeric)
language sql stable security invoker set search_path = public as $$
  select d.id, d.name_fr, coalesce(sum(i.line_total_cents), 0)::bigint, coalesce(sum(i.quantity), 0)
  from departments d
  left join order_items i on i.department_id = d.id
    and i.order_id in (select id from sales_orders where created_at >= from_ts and created_at < to_ts)
  group by d.id, d.name_fr
  order by d.id;
$$;

-- Low stock (only items whose stock is tracked).
create or replace view low_stock with (security_invoker = true) as
  select p.id as product_id, null::bigint as variant_id, p.name_fr as name, p.sku, p.stock_qty, p.low_stock_threshold
  from products p
  where p.track_stock and not p.has_variants and p.status = 'published'
    and p.stock_qty <= coalesce(p.low_stock_threshold, 5)
  union all
  select v.product_id, v.id, p.name_fr || ' (' || (select string_agg(value, ', ') from jsonb_each_text(v.options)) || ')', v.sku, v.stock_qty, v.low_stock_threshold
  from product_variants v join products p on p.id = v.product_id
  where v.track_stock and v.active and p.status = 'published'
    and v.stock_qty <= coalesce(v.low_stock_threshold, 3);

-- Lots expiring within 14 days or already expired, still in stock.
create or replace view expiring_lots with (security_invoker = true) as
  select l.*, p.name_fr as product_name, (l.expiry_date - current_date) as days_left
  from inventory_lots l join products p on p.id = l.product_id
  where l.quantity > 0 and l.expiry_date is not null and l.expiry_date <= current_date + 14
  order by l.expiry_date;

-- ---------- Initial data ----------
insert into departments (id, slug, name_fr, name_en, sort) values
  (1, 'epicerie', 'Épicerie', 'Grocery', 1),
  (2, 'beaute-cheveux', 'Beauté et cheveux', 'Beauty and hair', 2),
  (3, 'soins-corporels', 'Soins corporels', 'Body care', 3);

insert into settings (key, value, is_public) values
  ('store', jsonb_build_object(
     'name', 'Marché Chemin-du-Roy',
     'phone', '+1 819-372-9966',
     'email', 'sc@marchecheminduroy.com',
     'whatsapp', '15145787442',
     'whatsapp_display', '+1 514-578-7442',
     'address', jsonb_build_object('street', '65 Rue Notre-Dame E', 'city', 'Trois-Rivières', 'region', 'QC', 'postal_code', 'G8T 4B8', 'country', 'Canada'),
     'location', jsonb_build_object('lat', 46.3629863, 'lon', -72.5258898),
     'currency', 'CAD',
     'timezone', 'America/Toronto'
   ), true),
  -- Store opening hours (not delivery hours). Days: 1 = Monday ... 7 = Sunday.
  ('store_hours', jsonb_build_object('days', jsonb_build_array(
     jsonb_build_object('day', 1, 'open', '08:30', 'close', '22:00', 'closed', false),
     jsonb_build_object('day', 2, 'open', '08:30', 'close', '22:00', 'closed', false),
     jsonb_build_object('day', 3, 'open', '08:30', 'close', '22:00', 'closed', false),
     jsonb_build_object('day', 4, 'open', '08:30', 'close', '22:00', 'closed', false),
     jsonb_build_object('day', 5, 'open', '08:30', 'close', '22:00', 'closed', false),
     jsonb_build_object('day', 6, 'open', '08:30', 'close', '22:00', 'closed', false),
     jsonb_build_object('day', 7, 'open', '09:00', 'close', '22:00', 'closed', false)
   )), true),
  ('delivery', jsonb_build_object(
     'enabled', true,
     'days', jsonb_build_array(1, 2, 3, 4, 5, 6, 7),
     'start', '09:00',
     'end', '18:00',
     'distance_method', 'road',           -- 'road' (OSRM), 'straight_line', or 'manual' (fee confirmed by staff)
     'straight_line_factor', 1.3,         -- multiplier applied when distance_method = 'straight_line'
     'max_km', 15,
     'tiers', jsonb_build_array(
       jsonb_build_object('from_km', 0, 'to_km', 5, 'fee_cents', 599),
       jsonb_build_object('from_km', 5, 'to_km', 9, 'fee_cents', 699),
       jsonb_build_object('from_km', 9, 'to_km', 11, 'fee_cents', 799),
       jsonb_build_object('from_km', 11, 'to_km', 13, 'fee_cents', 899),
       jsonb_build_object('from_km', 13, 'to_km', 15, 'fee_cents', 999)
     ),
     'flat_fee_cents', null,
     'free_min_subtotal_cents', 9900,
     'free_radius_km', 15,
     'prep_minutes', 60,
     'eta_text_fr', 'Livraison le jour même pour les commandes passées avant 16 h.',
     'eta_text_en', 'Same-day delivery for orders placed before 4 p.m.',
     'instructions_fr', 'Nous vous appelons avant la livraison. Les produits surgelés sont livrés en sac isotherme.',
     'instructions_en', 'We call before delivering. Frozen products are delivered in an insulated bag.'
   ), true),
  ('pickup', jsonb_build_object(
     'enabled', true,
     'location', '65 Rue Notre-Dame E, Trois-Rivières, QC G8T 4B8',
     'use_store_hours', true,
     'prep_minutes', 45,
     'slots_enabled', false,
     'slot_minutes', 60,
     'instructions_fr', 'Présentez votre numéro de commande à la caisse.',
     'instructions_en', 'Show your order number at the counter.'
   ), true),
  ('payments', jsonb_build_object(
     'card_online', jsonb_build_object('enabled', false, 'provider', 'stripe', 'label_fr', 'Carte de crédit ou débit en ligne', 'note', 'Activez après la configuration de Stripe.'),
     'pay_on_delivery', jsonb_build_object('enabled', true, 'label_fr', 'Paiement à la livraison', 'accepts', jsonb_build_array('cash', 'debit', 'credit')),
     'pay_in_store', jsonb_build_object('enabled', true, 'label_fr', 'Paiement en magasin', 'accepts', jsonb_build_array('cash', 'debit', 'credit'))
   ), true),
  ('taxes', jsonb_build_object(
     'enabled', false,
     'note', 'Taxes (TPS/TVQ) ajoutées à la confirmation. Activez le calcul automatique quand les produits taxables sont identifiés.',
     'gst_rate', 0.05,
     'qst_rate', 0.09975
   ), true),
  ('announcement', jsonb_build_object(
     'enabled', true,
     'text_fr', 'Livraison GRATUITE pour toute commande de 99 $ et plus, jusqu’à 15 km. Passez votre première commande dès maintenant !',
     'text_en', 'FREE delivery on orders of $99 or more, within 15 km.'
   ), true);

insert into homepage_sections (key, title_fr, eyebrow_fr, body_fr, mode, max_items, sort) values
  ('hero', 'Les saveurs d’Afrique et d’ici, livrées chez vous.', 'Trois-Rivières, sur le Chemin du Roy',
   'Épicerie africaine et québécoise, viandes et poissons surgelés, perruques, extensions et soins capillaires.', 'auto', 0, 1),
  ('categories', 'Magasiner par catégorie', 'Une épicerie pas comme les autres', null, 'auto', 0, 2),
  ('promotions', 'Promotions', 'Dans la limite des stocks', null, 'auto', 12, 3),
  ('new', 'Nouveautés', 'Cette semaine', null, 'auto', 12, 4),
  ('epicerie', 'Épicerie et saveurs d’Afrique', 'Garde-manger', null, 'auto', 12, 5),
  ('surgeles', 'Produits congelés', 'Offrez le meilleur à votre famille', null, 'auto', 12, 6),
  ('perruques', 'Nos perruques', 'Naturel, semi-naturel et synthétique', null, 'auto', 12, 7),
  ('extensions-capillaires', 'Extensions et tresses', 'Crochets, mèches, tissages', null, 'auto', 12, 8),
  ('soins-capillaires', 'Soins capillaires', 'Votre routine beauté', null, 'auto', 12, 9),
  ('soins-corporels', 'Soins corporels', 'Prenez soin de vous', null, 'auto', 12, 10);
