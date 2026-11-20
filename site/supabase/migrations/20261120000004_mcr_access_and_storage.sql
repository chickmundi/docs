-- Staff access requests, owner bootstrap and product image storage.

-- A signed-in user asks for access to the back office.
-- * The very first account becomes the owner, but only for the business email, once confirmed.
-- * Everyone else is added as a deactivated employee until the owner or a manager approves them.
create or replace function request_staff_access(full_name text)
returns text language plpgsql security definer set search_path = public, auth as $$
declare
  uid uuid := auth.uid();
  u record;
begin
  if uid is null then raise exception 'not signed in'; end if;
  if exists (select 1 from staff where user_id = uid) then
    return (select case when active then 'active' else 'pending' end from staff where user_id = uid);
  end if;
  select email, email_confirmed_at into u from auth.users where id = uid;
  if not exists (select 1 from staff)
     and lower(u.email) = 'sc@marchecheminduroy.com'
     and u.email_confirmed_at is not null then
    insert into staff (user_id, full_name, role, active) values (uid, coalesce(nullif(trim(full_name), ''), 'Propriétaire'), 'owner', true);
    insert into audit_log (actor, action, entity, entity_id) values (uid, 'staff.owner_bootstrap', 'staff', uid::text);
    return 'owner';
  end if;
  insert into staff (user_id, full_name, role, active) values (uid, coalesce(nullif(trim(full_name), ''), u.email), 'staff', false);
  insert into audit_log (actor, action, entity, entity_id, details) values (uid, 'staff.request', 'staff', uid::text, jsonb_build_object('email', u.email));
  return 'pending';
end $$;
revoke all on function request_staff_access(text) from public, anon;
grant execute on function request_staff_access(text) to authenticated;

-- Staff can see the email of team members (for the Équipe page).
create or replace function staff_emails()
returns table (user_id uuid, email text) language sql stable security definer set search_path = public, auth as $$
  select s.user_id, u.email::text from staff s join auth.users u on u.id = s.user_id where is_staff();
$$;
revoke all on function staff_emails() from public, anon;
grant execute on function staff_emails() to authenticated;

-- Only the owner can change or remove the owner.
create or replace function protect_owner() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  if (tg_op = 'DELETE' and old.role = 'owner') or (tg_op = 'UPDATE' and old.role = 'owner' and (new.role <> 'owner' or not new.active)) then
    if not exists (select 1 from staff where user_id = auth.uid() and role = 'owner' and active) or old.user_id = auth.uid() and (tg_op = 'DELETE' or not new.active) then
      raise exception 'Seul un autre propriétaire peut modifier ou retirer un propriétaire.';
    end if;
  end if;
  return coalesce(new, old);
end $$;
create trigger staff_protect_owner before update or delete on staff for each row execute function protect_owner();

-- Public bucket for product and category images; only staff can write.
insert into storage.buckets (id, name, public, file_size_limit, allowed_mime_types)
values ('catalog', 'catalog', true, 5242880, array['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
on conflict (id) do nothing;

create policy "public read catalog images" on storage.objects for select using (bucket_id = 'catalog');
create policy "staff upload catalog images" on storage.objects for insert to authenticated with check (bucket_id = 'catalog' and public.is_staff());
create policy "staff update catalog images" on storage.objects for update to authenticated using (bucket_id = 'catalog' and public.is_staff());
create policy "staff delete catalog images" on storage.objects for delete to authenticated using (bucket_id = 'catalog' and public.is_staff());
