-- Row-level security: the public storefront reads published catalog data and public settings;
-- only active staff read and write everything else. Orders are created by the checkout
-- function (service role), never directly by browsers.

alter table staff enable row level security;
alter table departments enable row level security;
alter table categories enable row level security;
alter table brands enable row level security;
alter table products enable row level security;
alter table product_categories enable row level security;
alter table product_options enable row level security;
alter table product_variants enable row level security;
alter table product_images enable row level security;
alter table inventory_lots enable row level security;
alter table stock_movements enable row level security;
alter table customers enable row level security;
alter table customer_addresses enable row level security;
alter table coupons enable row level security;
alter table promotions enable row level security;
alter table orders enable row level security;
alter table order_items enable row level security;
alter table payments enable row level security;
alter table order_events enable row level security;
alter table settings enable row level security;
alter table homepage_sections enable row level security;
alter table audit_log enable row level security;

-- Public catalog reads
create policy "public read departments" on departments for select using (true);
create policy "public read categories" on categories for select using (visible or is_staff());
create policy "public read brands" on brands for select using (true);
create policy "public read products" on products for select using (status = 'published' or is_staff());
create policy "public read product_categories" on product_categories for select using (true);
create policy "public read product_options" on product_options for select using (true);
create policy "public read product_variants" on product_variants for select using (active or is_staff());
create policy "public read product_images" on product_images for select using (true);
create policy "public read promotions" on promotions for select using (active or is_staff());
create policy "public read settings" on settings for select using (is_public or is_staff());
create policy "public read homepage" on homepage_sections for select using (visible or is_staff());

-- Staff writes on catalog and content
create policy "staff write departments" on departments for all using (is_manager()) with check (is_manager());
create policy "staff write categories" on categories for all using (is_staff()) with check (is_staff());
create policy "staff write brands" on brands for all using (is_staff()) with check (is_staff());
create policy "staff write products" on products for all using (is_staff()) with check (is_staff());
create policy "staff write product_categories" on product_categories for all using (is_staff()) with check (is_staff());
create policy "staff write product_options" on product_options for all using (is_staff()) with check (is_staff());
create policy "staff write product_variants" on product_variants for all using (is_staff()) with check (is_staff());
create policy "staff write product_images" on product_images for all using (is_staff()) with check (is_staff());
create policy "staff all inventory_lots" on inventory_lots for all using (is_staff()) with check (is_staff());
create policy "staff all stock_movements" on stock_movements for all using (is_staff()) with check (is_staff());
create policy "staff write promotions" on promotions for all using (is_manager()) with check (is_manager());
create policy "staff all coupons" on coupons for all using (is_manager()) with check (is_manager());
create policy "staff read coupons" on coupons for select using (is_staff());
create policy "manager write settings" on settings for all using (is_manager()) with check (is_manager());
create policy "staff write homepage" on homepage_sections for all using (is_staff()) with check (is_staff());

-- Customers: staff see all; a signed-in customer sees only their own record
create policy "staff all customers" on customers for all using (is_staff()) with check (is_staff());
create policy "customer reads self" on customers for select using (auth_user_id = auth.uid());
create policy "staff all addresses" on customer_addresses for all using (is_staff()) with check (is_staff());
create policy "customer reads own addresses" on customer_addresses for select
  using (customer_id in (select id from customers where auth_user_id = auth.uid()));

-- Orders: staff manage; customers read their own; inserts come from the checkout function only
create policy "staff all orders" on orders for all using (is_staff()) with check (is_staff());
create policy "customer reads own orders" on orders for select
  using (customer_id in (select id from customers where auth_user_id = auth.uid()));
create policy "staff all order_items" on order_items for all using (is_staff()) with check (is_staff());
create policy "customer reads own order items" on order_items for select
  using (order_id in (select o.id from orders o join customers c on c.id = o.customer_id where c.auth_user_id = auth.uid()));
create policy "staff all payments" on payments for all using (is_staff()) with check (is_staff());
create policy "staff all order_events" on order_events for all using (is_staff()) with check (is_staff());

-- Staff table: staff see the team; only the owner/managers change it
create policy "staff read staff" on staff for select using (is_staff());
create policy "manager write staff" on staff for all using (is_manager()) with check (is_manager());

create policy "staff read audit" on audit_log for select using (is_manager());
create policy "staff write audit" on audit_log for insert with check (is_staff());

-- ---------- Order rules enforced in the database ----------

-- Log every fulfillment/payment status change.
create or replace function log_order_status() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if new.fulfillment_status is distinct from old.fulfillment_status then
    insert into order_events (order_id, kind, from_value, to_value, actor)
    values (new.id, 'status', old.fulfillment_status::text, new.fulfillment_status::text, auth.uid());
    if new.fulfillment_status in ('delivered', 'collected') then
      new.completed_at = coalesce(new.completed_at, now());
    end if;
  end if;
  if new.payment_status is distinct from old.payment_status then
    insert into order_events (order_id, kind, from_value, to_value, actor)
    values (new.id, 'payment', old.payment_status::text, new.payment_status::text, auth.uid());
  end if;
  return new;
end $$;
create trigger orders_status_log before update on orders for each row execute function log_order_status();

-- Payment status follows the recorded payments (never "paid" just because an order exists).
create or replace function sync_payment_status() returns trigger
language plpgsql security definer set search_path = public as $$
declare
  oid bigint := coalesce(new.order_id, old.order_id);
  paid integer;
  refunded integer;
  total integer;
begin
  select coalesce(sum(amount_cents) filter (where status = 'succeeded' and amount_cents > 0), 0),
         coalesce(-sum(amount_cents) filter (where status = 'succeeded' and amount_cents < 0), 0)
    into paid, refunded from payments where order_id = oid;
  select total_cents into total from orders where id = oid;
  update orders set payment_status = case
      when paid = 0 then (case when exists (select 1 from payments where order_id = oid and status = 'pending') then 'pending' else 'unpaid' end)::payment_status
      when refunded >= paid then 'refunded'
      when refunded > 0 then 'partially_refunded'
      when paid >= total then 'paid'
      else 'pending'
    end
  where id = oid;
  return null;
end $$;
create trigger payments_sync after insert or update or delete on payments for each row execute function sync_payment_status();

-- Put stock back when an order is cancelled.
create or replace function restock_on_cancel() returns trigger
language plpgsql security definer set search_path = public as $$
declare
  it record;
begin
  if new.fulfillment_status = 'cancelled' and old.fulfillment_status <> 'cancelled' then
    for it in select * from order_items where order_id = new.id loop
      if it.variant_id is not null then
        update product_variants set stock_qty = stock_qty + it.quantity where id = it.variant_id and track_stock;
      elsif it.product_id is not null then
        update products set stock_qty = stock_qty + it.quantity where id = it.product_id and track_stock;
      end if;
      insert into stock_movements (product_id, variant_id, change, reason, order_id, actor)
      select it.product_id, it.variant_id, it.quantity, 'cancel', new.id, auth.uid() where it.product_id is not null;
    end loop;
  end if;
  return new;
end $$;
create trigger orders_restock after update on orders for each row execute function restock_on_cancel();

-- Keep a variable product's "from" price in sync with its variations.
create or replace function sync_from_price() returns trigger
language plpgsql security definer set search_path = public as $$
declare
  pid bigint := coalesce(new.product_id, old.product_id);
begin
  update products p set
    price_cents = sub.min_price,
    in_stock = sub.any_stock
  from (
    select min(price_cents) as min_price,
           bool_or(active and (case when track_stock then coalesce(stock_qty, 0) > 0 else in_stock end)) as any_stock
    from product_variants where product_id = pid and active
  ) sub
  where p.id = pid and p.has_variants;
  return null;
end $$;
create trigger variants_from_price after insert or update or delete on product_variants for each row execute function sync_from_price();
