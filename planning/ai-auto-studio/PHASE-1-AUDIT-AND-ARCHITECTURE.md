# AI Auto Studio: Phase 1 Repository Audit & Product Architecture

**Status:** Draft for approval. No implementation has started.
**Date:** 2026-11-16
**Scope:** Repository audit, architecture recommendation, AI pipeline strategy, data model, storage, security, costs, roadmap, risks.

---

## 0. Executive summary

1. **The repository has no application in it.** `chickmundi/docs` is the Mintlify documentation starter kit plus Claude Code plugin/skill configuration. There is no framework, auth, database, storage or deployment to keep. AI Auto Studio is a **greenfield build**, and it should live in its **own repository**. This repo can later host the product's public docs.
2. **The core AI decision: do not let a generative model redraw the car.** The reliable and cheap pipeline is *segment → matte → composite the original vehicle pixels onto a pre-built studio plate → synthesize a contact shadow*. This keeps the vehicle unchanged, pixel for pixel, by construction rather than by hoping a model behaves. Generative AI is used **offline to make background plates**, not on each customer photo.
3. **Estimated AI cost per vehicle is about US$0.20–0.45** for a 16-photo vehicle (8 exterior replacements + 8 interior enhancements + bilingual listing text) on the recommended pipeline. A per-image generative pipeline would cost about **$1.00–3.00**, and the car could change.
4. **The biggest product risk is quality, not code.** Reflections of the original lot in the paint, glass and windows, and camera/plate perspective mismatch decide whether the output looks "professional" or "photoshopped". **Run a 2-week pipeline-quality spike on real dealer photos before (or in parallel with) Phase 2.** The business case depends on its result.
5. **The market is crowded** (Spyne, CarCutter, Photoroom's automotive offering, Glo3D, AutoBG, CarPixAI and others). Differentiation has to come from small-dealer pricing, true French/English parity (Quebec/France), extreme simplicity, and the listing and marketing workflow. Background removal alone will not differentiate the product.

---

## 1. Repository audit

| Area | Finding |
|---|---|
| Framework | None (application). Mintlify docs site: `mint.json`, `*.mdx`, sample OpenAPI "Plant Store" spec. |
| Architecture | Static docs only. |
| Authentication | None. |
| Database | None. |
| File storage | None (static images under `images/`, `logo/`). |
| Components | Mintlify MDX sample pages only. |
| Dependencies | No `package.json`. Python `requirements.txt` files exist only inside `.claude/skills/*` (Claude Code tooling, not product code). |
| Deployment | Mintlify GitHub App (docs auto-deploy). No app hosting config. |
| Other | `.claude/` holds the oh-my-claudecode plugin config and ~80 marketing/sales/legal/GEO agent skills. They are unrelated to the product and should not be mixed into the app codebase. |
| Git history | 1 template commit (2024) + 5 tooling commits (Nov 2026). |

**Conclusion:** there is no working infrastructure to preserve or replace. Recommendation: create a new repository (e.g. `ai-auto-studio`) as a TypeScript monorepo plus a Python image worker. Keep `docs` as the future product/help-centre site, since Mintlify is a good fit for bilingual help docs and API docs later.

---

## 2. Architecture recommendation (Q1)

### 2.1 Challenge: native app vs. web app

The prompt says "mobile-first" and "smartphone camera". A native app is **not** required for v1.

- **PWA / mobile web (recommended for v1):** `getUserMedia` gives a live camera preview with SVG angle-guide overlays on iOS Safari and Android Chrome. `<input type="file" accept="image/*" capture>` is the fallback. Dealers onboard from a link with no App Store friction. One codebase serves phone, tablet and desktop.
- **Known PWA limits:** no manual exposure/ISO, limited torch control on iOS, no background upload when the tab is closed, iOS storage eviction. Mitigations: capture-then-upload queue in IndexedDB, resumable uploads, "keep this screen open" UX.
- **Path to native:** wrap the same app with **Capacitor** later for native camera control and background uploads. That stays cheap if the camera module sits behind an interface from day one.

### 2.2 Recommended stack

| Layer | Recommendation | Why / trade-off |
|---|---|---|
| Frontend | **Next.js (App Router) + React + TypeScript**, Tailwind + Radix/shadcn primitives with a custom automotive design system, `next-intl` for FR/EN | Mature, SSR for marketing pages, one codebase for dealer app + admin. |
| API | Next.js route handlers / server actions for CRUD; **typed service layer** (no business logic in route files) | One deployable for the UI and API. Domain services stay testable. |
| Database | **PostgreSQL (Supabase)** with **Drizzle ORM** + SQL migrations | Postgres RLS gives a second wall for tenant isolation. Supabase also provides Auth and Realtime. A Supabase connector is already linked to your Claude account. |
| Auth | **Supabase Auth** (email+password, magic link, Google; MFA for owners/admins) | Avoids building auth. JWT carries `user_id` only. Org/role are resolved server-side from `memberships`. |
| Object storage | **Cloudflare R2** (S3 API, private buckets, presigned URLs) | **Zero egress fees.** This matters a lot for an image product where dealers download ZIPs and re-post photos. Supabase Storage is an acceptable MVP shortcut, but egress costs grow with usage. |
| Job queue | **pg-boss** (Postgres-backed queue) driven by a Node orchestrator worker | No extra infrastructure, transactional enqueue with the DB write, retries/backoff/cron built in. Revisit Trigger.dev/Inngest if workflows get complex. |
| Image worker | **Python service** (libvips via `pyvips`, OpenCV, NumPy) on Fly.io/Railway/Cloud Run (CPU) | Compositing, enhancement, quality checks and shadows are CPU work. Python has the best imaging ecosystem. |
| GPU inference (later) | **Modal** or RunPod serverless running BiRefNet / SAM 2 | Only once volume justifies replacing a per-image API (see §5). |
| LLM (listings) | **Claude API** (Sonnet 5.5 for quality; Haiku 5.5 for cheap tasks such as angle classification) behind a provider adapter | Strong FR/EN copy, structured outputs for grounded generation. |
| Billing (Phase 7) | **Stripe Billing** (subscriptions + usage meters), Stripe Tax | Standard. No fake transactions before then. |
| Realtime job status | Supabase Realtime on `processing_jobs` (polling fallback) | Non-blocking UI. |
| Observability | Sentry (errors), OpenTelemetry/structured logs, cost dashboards from the DB | Cost per job is a first-class metric. |
| Hosting | Vercel (web) + Fly.io/Railway (workers) + Supabase + R2, all in **one chosen region** (see §8 data residency) | |

### 2.3 Application module map

```
apps/
  web/                      Next.js dealer app + platform admin + marketing site
  worker-orchestrator/      Node: pg-boss consumers, provider calls, credit ledger
services/
  image-worker/             Python: decode/validate, quality analysis, enhancement,
                            compositing, shadows, branding render, exports
packages/
  domain/                   Pure TS: entities, RBAC policies, entitlement engine, naming rules
  db/                       Drizzle schema, migrations, RLS policies, seed (angles, presets, plans)
  ai-providers/             Adapter interfaces + implementations (segmentation, generation, LLM)
  i18n/                     FR/EN message catalogs (UI, errors, emails, generated-content prompts)
  ui/                       Design system (charcoal / white / silver / electric-blue)
  camera/                   Capture abstraction (web now, Capacitor later), on-device quality checks
```

Domain modules map to the prompt's modules: **Onboarding & Tenancy**, **Capture**, **Photo Studio (AI)**, **Enhancement**, **Branding**, **Inventory**, **Listing Generator**, **Marketing Studio**, **Exports**, **Billing & Entitlements**, **Platform Admin**, **Audit**.

---

## 3. AI providers and models for automotive background replacement (Q2)

### 3.1 Capability evaluation

| Capability | Recommended approach | Candidates | Notes |
|---|---|---|---|
| **Vehicle segmentation / matting** | Commercial API first, self-hosted later | **Photoroom Remove Background API** (~$0.02/img), **Bria RMBG 2.0 via fal** (~$0.018/img), Pixelcut (~$0.016/img), **BiRefNet** (open weights, MIT; fal compute-billed or self-host), SAM 2 for interactive touch-up | Evaluate on *cars* specifically: windows, antennas, mirrors, wheel arches, roof racks. Bria RMBG 2.0 **open weights are non-commercial**, so self-hosting it needs a Bria licence; via API is fine. |
| **Glass / window handling** | Matte with partial alpha for glass + "window fill" option | Same models; some automotive vendors specialize here | Hard problem. Through-window views show the old lot. Options: keep (honest), tint/darken glass region only, or replace the see-through region with the plate. Never touch the body. |
| **Background replacement** | **Deterministic compositing onto pre-built plates** | Plates authored once per preset × camera height/angle class (generated with an image model and/or 3D-rendered, then curated) | Per-image cost ≈ CPU only. Unlimited previews: one matte, many plates. |
| **Custom dealership background** | Dealer uploads a photo of their own showroom/lot → we turn it into a plate (crop/horizon level/blur) | — | Very marketable ("your own showroom"). No generation needed. |
| **Shadow generation** | Synthetic contact + ambient-occlusion shadow from mask geometry (tire contact points, bottom edge), optional floor reflection for studio presets | Photoroom AI Shadows ($0.10/img, Plus plan) as a premium fallback | Deterministic shadows are cheap and cannot change the car. |
| **Lighting harmonization** | Global colour/tone transfer applied **to the background plate to match the car**, not the other way round | — | Keeps vehicle colours true. |
| **Image enhancement** | Deterministic pipeline: auto-exposure, white balance, local contrast (CLAHE), denoise, unsharp mask, lens/perspective correction | libvips, OpenCV | Natural/Professional/Premium = three parameter sets, all global/photometric. |
| **Upscaling** | Classical (Lanczos) only for vehicle pixels | — | **Reject generative super-resolution on the vehicle**: it invents badge text, wheel spokes and textures. |
| **Quality analysis** | **On-device first** (instant feedback, works offline), server re-check | Laplacian variance (blur), histogram clipping (exposure), pHash (duplicates), lightweight on-device car detector (framing/cut-off) | Free and instant. Only ambiguous cases go server-side. |
| **Angle classification / validation** | Small vision call per photo | Claude Haiku 5.5 vision (~$0.0002/img) or a fine-tuned small classifier later | Auto-files uploads into the right angle slot; flags "this looks like rear-left, not front-left". |
| **Listing / caption text** | LLM with structured output, grounded strictly on supplied fields | Claude Sonnet 5.5 ($2 / $10 per M tokens), Haiku 5.5 ($0.10 / $0.50) | Plus a post-generation "fact check" (see §4.4). |
| **VIN decode** | NHTSA vPIC (free, North America) at launch; paid EU decoder later | — | Only verified data enters prompts. |
| **Full-service automotive vendors** | Do **not** build the core on them | Spyne, CarCutter, Glo3D, AutoBG | Pricing is mostly "on request". They are direct competitors, so building on them caps your margins and hands them your roadmap. Useful as quality benchmarks. |

### 3.2 Why not "general-purpose image generation" for each photo

Instruction-based editors (FLUX Kontext, Gemini/GPT image models, Photoroom AI Backgrounds) re-render the whole image. Even when they look faithful they can silently change badge lettering, wheel spoke count, grille mesh, plate characters, panel gaps and, critically, **remove dents and scratches**. That breaks your integrity requirement and creates **misleading-advertising liability for the dealer**. At ~$0.04–0.10+ per call, multiplied by previews and retries, they are also the most expensive option. Use them only for:

- creating and curating **background plates** (one-off, offline), and
- an optional, clearly labelled premium "creative scene" feature that **must pass the identity guard** (§4.3) or is rejected automatically.

---

## 4. Preserving the original vehicle accurately (Q3)

### 4.1 Principle: preservation by construction

```
original.jpg ──► [photometric correction: global exposure/WB only]
                          │
                          ├──► segmentation ──► alpha matte (+ glass alpha)
                          │
plate (preset, matched) ──┴──► composite:  out = car·α + plate·(1−α) + shadow
```

- Vehicle pixels in the output are **copied from the source** (after optional global, uniform corrections). No model ever "draws" the car.
- Matte edges are the only blended region (1–3 px feather, plus glass).

### 4.2 Allowed vs. forbidden operations (enforced in code, not in prompts)

| Allowed on vehicle region | Forbidden on vehicle region |
|---|---|
| Global exposure, white balance, contrast, saturation within bounded ranges | Any generative/inpainting operation |
| Uniform noise reduction and sharpening | Local retouching, healing, clone tools |
| Geometric: rotate/level, crop, lens distortion correction, uniform scale | Generative upscaling |
| Glass treatment (only inside the glass mask, opt-in) | Paint colour changes, wheel changes, plate replacement |

The pipeline is described as a list of operations with an explicit `scope` (`background`, `vehicle_global`, `glass`). The worker refuses unknown or out-of-scope operations.

### 4.3 Identity guard (automated verification on every output)

For each final image, compare the vehicle region of the output to the vehicle region of the (photometrically corrected) source:

- **Deterministic pipeline:** pixel difference inside `α > 0.98` must be ~0. Any deviation means a bug, so the job fails.
- **Any generative path:** SSIM / LPIPS inside the mask, edge-map correlation, and OCR of plate/badge regions. Below threshold means automatic rejection and fallback to the deterministic composite.
- Store guard metrics on the job for audit ("we can prove we didn't alter the vehicle").

### 4.4 Text integrity (listing generator)

- The prompt receives only verified fields plus dealer-entered notes, and is told never to add specs.
- Output uses **structured fields** (title, description, highlights[] with `source_field` references).
- A post-check extracts all numbers, years, mileage, prices and feature keywords from the output and **rejects anything not traceable to an input field**. The user edits and approves before publishing. Approval is stored with the version.

### 4.5 Honest limitation to accept and design around

Paint and chrome **reflect the original surroundings** (parking lot, buildings, sky). We cannot remove those reflections without altering the vehicle. Consequences:

- A dark car shot in a cluttered lot will never look like a true studio shot. Capture guidance must therefore coach "shoot in open space, overcast light, avoid busy reflections". That coaching is product value, not just a tooltip.
- Background presets should *suit* outdoor-lit cars (bright neutral studio, outdoor dealership plate) more than dramatic dark studios, which expose mismatched reflections.

---

## 5. Most cost-effective AI image-processing pipeline (Q4)

### 5.1 Recommended pipeline per photo

| Step | Where | Cost driver |
|---|---|---|
| 1. On-device checks (blur, exposure, duplicate, framing) | Phone | Free |
| 2. Client-side resize to ≤ 12 MP JPEG q≈90, HEIC→JPEG, EXIF GPS stripped from derivatives | Phone | Free; saves upload time on slow connections |
| 3. Direct resumable upload to R2 (presigned multipart) | Phone → R2 | Storage |
| 4. Validate (magic bytes, decode with libvips, dimension/size caps), create web preview | CPU worker | ~$0.0002 |
| 5. Angle classification (if uploaded from gallery) | Haiku 5.5 vision | ~$0.0002 |
| 6. **Segmentation** (exterior only) | Photoroom / Bria API → later self-hosted BiRefNet | **$0.016–0.02 (API)** → ~$0.001–0.003 (self-host at scale) |
| 7. Matte refine + glass mask | CPU worker | ~$0.0005 |
| 8. Preview composites for N presets (low-res) | CPU worker | ~$0.0002 each (cheap, so previews can be unlimited) |
| 9. Final composite + shadow + enhancement at full res | CPU worker | ~$0.001 |
| 10. Branding render (on demand, cached) | CPU worker | ~$0.0005 |
| 11. Identity guard | CPU worker | ~$0.0005 |

**Key cost lever:** the expensive step (segmentation) runs **once per photo**. Every preview, preset change, branding change and export reuses the stored matte. Charge credits for the matte, not for each preview.

### 5.2 Build vs. buy for segmentation

- **0 → ~50k exterior images/month:** buy (Photoroom or Bria via API). No GPU ops, predictable cost.
- **Beyond that:** self-host BiRefNet on serverless GPU. At ~1 s/image on a mid-range GPU at ~$1–2/hour, that is ~$0.0005–0.001/image plus cold-start overhead and engineering time. The provider-adapter design makes this a configuration change, A/B-tested with the identity guard and a human-rated quality set.

---

## 6. Database schema proposal (Q5)

All tenant-owned tables carry `organization_id` (FK, indexed) and have **RLS policies** of the form `organization_id IN (select org ids where the user has an active membership)`. Workers use a scoped service role and always filter by `organization_id` explicitly. UUIDv7 primary keys, `created_at`/`updated_at`, and soft-delete (`deleted_at`) where useful.

### Tenancy & identity
- `organizations`: dealership account (legal name, display name, country, default locale `fr|en`, currency, timezone, data region, status).
- `branches`: locations (address, phone, email, website, timezone).
- `users`: profile mirror of the auth user (name, locale, phone).
- `memberships`: (user, organization, role `owner|admin|photographer|sales`, optional `branch_ids[]` scope, status).
- `invitations`: (email, role, token hash, expires_at, invited_by).
- `platform_admins`: super-admin allow-list (separate from tenant roles; elevated actions audited).
- `onboarding_state`: wizard progress per organization.

### Branding
- `brand_profiles`: one or more per org (name, colours[], contact block, slogan, default flag).
- `brand_assets`: logos (primary/secondary), custom background plates, fonts. Points to `storage_objects`.
- `branding_templates`: placement (TL/TR/BL/BR), size %, opacity, show_name/phone/website/slogan, border style, safe-zone rules (JSON, versioned).

### Inventory
- `vehicles`: title, make, model, year, trim, mileage + unit, VIN, transmission, fuel_type, exterior/interior colour, asking_price + currency, condition, description, status (`draft|photographing|processing|ready|published|sold|archived`), branch_id, created_by. Unique `(organization_id, vin)` where VIN is not null.
- `vehicle_status_history`
- `vin_decodes`: raw decoded payload + source (verified data provenance).

### Photography
- `angle_definitions`: static seed (code, category exterior/interior, required/optional, order, FR/EN labels, guide overlay asset).
- `photo_sessions`: (vehicle, started_by, status, started/completed, device info).
- `photos`: one logical photo per vehicle angle slot (vehicle, session, angle_code, is_approved, is_branding_enabled, sort_order, current_version ids).
- `image_assets`: **immutable versions** of a photo (`kind`: original | normalized | matte | enhanced | composite | branded | export; parent_asset_id; storage_object_id; width/height/bytes/format; checksum; pipeline_settings JSON; created_by_job_id). The original is never overwritten; this table is the lineage graph.
- `quality_reports`: (asset, blur score, exposure score, framing score, duplicate_of, detected_angle + confidence, verdict, recommendations[]).
- `storage_objects`: (bucket, key, content_type, bytes, checksum, organization_id, retention_class).

### AI processing
- `ai_providers`, `ai_models`: name, capability, version, pricing metadata, enabled flag, config (no secrets; secrets live in the secret manager).
- `background_presets`: system and org-custom (code, FR/EN names, plate assets per angle class, shadow/reflection params).
- `processing_batches`: (vehicle, requested_by, preset, mode, total/succeeded/failed counts, status).
- `processing_jobs`: id, batch_id, photo_id, input_asset_id, output_asset_id, job_type, **status** (`pending|uploading|processing|completed|failed|cancelled`), settings JSON, provider_id, model_id, attempts, last_error_code/message, idempotency_key, cost_usd_estimated/actual, credits_reserved/consumed, guard_metrics JSON, queued/started/finished timestamps.
- `processing_job_attempts`: per-try provider request id, latency, cost, error.

### Content & marketing
- `generated_contents`: (vehicle, type title|description|short|highlights|facebook|instagram|headline, locale, body, input_snapshot JSON, model, status draft|approved, approved_by/at, version).
- `marketing_templates`: system and org (type for_sale|offer|new_arrival|price_drop|featured|sold, layout JSON).
- `marketing_graphics`: (template, vehicle, photo asset, overrides JSON, format preset, output asset).
- `exports`: (vehicle, scope, variant original|enhanced|branded, format, resolution, zip asset, status, expires_at).

### Billing & entitlements
- `plans`, `plan_entitlements`: (plan, feature_key, limit_value / boolean, period).
- `subscriptions`: (organization, plan, status, period start/end, stripe ids nullable until Phase 7).
- `entitlement_overrides`: per-org grants and limits (sales deals, support credits).
- `usage_ledger`: **append-only** (organization, feature_key, quantity ±, reason reserve|commit|release|grant|expire, job_id, period).
- `usage_counters`: materialized per-period totals for fast checks.

### Governance
- `audit_logs`: (organization, actor, action, entity, before/after hash, ip, user_agent).
- `data_deletion_requests`: (scope user|organization, status, requested/completed, report).
- `api_rate_limits` (or Redis): only if Postgres-based limiting is chosen.

---

## 7. Image storage (Q6)

- **Buckets:** `originals` (private, write-once), `derived` (private), `public-assets` (only explicitly published marketing assets, optional).
- **Key layout:** `org/{org_id}/veh/{vehicle_id}/photo/{photo_id}/{kind}/{asset_id}.{ext}`. `org_id` is part of every key, and server code always derives keys from DB records, never from client input.
- **Uploads:** client requests an upload → server checks authz, entitlements and file size → returns a **presigned multipart URL** (short TTL, exact key, content-type and max-length constraints) → client uploads directly → server finalizes, validates by decoding (not by extension), and rejects and deletes on failure. Limits: e.g. 25 MB/file, JPEG/PNG/HEIC/WebP only, max 50 MP.
- **Downloads:** always via **short-lived signed URLs** (e.g. 5–15 min) issued after an authorization check, or proxied ZIP streams. No public bucket listing.
- **Originals:** immutable; derivatives are regenerable and can carry a shorter retention.
- **Retention policy (cost control):** keep originals and final outputs while the vehicle is active. 90 days after `sold/archived`, delete intermediate derivatives (keep originals + finals). Org deletion triggers a full purge job with a report.
- **Size estimate:** ~16 photos × (3–4 MB original + matte + composite + branded + web sizes) ≈ **120–200 MB per vehicle**. On R2 (~$0.015/GB-month) that is under $0.003/vehicle/month. Storage is not the cost problem; **egress would be on S3/Supabase**, which is why R2 is recommended.
- **Image delivery for the UI:** pre-generated WebP thumbnails (320/800/1600 px) so galleries stay fast on 3G/4G.

---

## 8. Security, privacy and integrity architecture

- **Tenant isolation, two walls:** (1) the service layer checks every query through `requireMembership(org, permission)` and (2) Postgres RLS. Automated tests attempt cross-tenant reads/writes on every endpoint and storage path (Phase 8, but the harness is written in Phase 2).
- **RBAC:** permission matrix in `packages/domain` (e.g. `vehicle.update`, `photo.process`, `billing.manage`, `team.invite`). Roles map to permission sets; branch scoping for multi-location orgs. Platform Super Admin uses separate auth plus MFA, and "impersonation" requires a reason and is audited.
- **Secrets:** AI keys live only in worker/server environments (secret manager). Never in `NEXT_PUBLIC_*`. Provider calls happen only in the worker.
- **Rate limiting:** per user, per org and per IP on auth, upload and generation endpoints. Per-org concurrency caps on processing jobs.
- **Uploads:** decode-based validation, re-encode outputs, strip EXIF GPS from all derivatives (dealer lot location is sensitive), malware is not a concern for re-encoded images, but SVG logos must be sanitized or rasterized.
- **Audit logging:** role changes, invitations, deletions, exports, billing changes, branding changes, admin impersonation.
- **Privacy & compliance:** FR/EN support points at **Canada (Quebec)** and/or **France/EU**:
  - **Quebec Law 25** (privacy) and the **Charter of the French Language (Bill 96)**: French must be at least equivalent in the UI, contracts and support for Quebec customers.
  - **GDPR** for France/EU: DPA with sub-processors (AI providers), data-subject deletion and export, EU data residency option.
  - **Decide the primary launch region**, because it determines DB/storage region and which AI providers can be used (provider data-retention terms must allow no training on customer images).
- **Image integrity / advertising:** store the full edit lineage and identity-guard metrics per image. Optionally embed **C2PA content credentials** ("background replaced by AI Auto Studio") later, as a trust feature for dealers facing misleading-ad scrutiny.
- **License plates:** the requirement says preserve them. Note that many dealers *want* plates blurred for privacy. Recommend an **explicit, opt-in** "blur plate" correction (a background-scope-like, audited operation), off by default.

---

## 9. Batch image processing (Q7)

- **Model:** user action → `processing_batch` + one `processing_job` per photo, inserted **in the same transaction** as the pg-boss enqueue (no lost jobs).
- **Job graph per photo:** `validate → (classify) → segment → refine → preview-composites` (on upload), then `final-composite → guard → brand → export` (on approval). Steps after segmentation are cheap and re-runnable.
- **Fairness:** per-org concurrency limit (e.g. 4 concurrent photos on Starter, 16 on Business) so one large dealer cannot starve others. Global per-provider rate limiter (token bucket) respects API limits.
- **Retries:** exponential backoff with jitter, max 3 attempts for transient errors (timeouts, 429, 5xx). Permanent errors (invalid image, policy refusal) fail immediately with a FR/EN user-facing reason. Then dead-letter plus an admin view.
- **Idempotency:** `idempotency_key = hash(input_asset_id, step, settings, model_version)`, so a duplicate request reuses the result and costs no extra credits.
- **Credits:** **reserve** at enqueue, **commit** on success, **release** on failure/cancel (ledger entries tied to `job_id`).
- **Cancellation:** pending jobs are cancelled immediately; running jobs finish their current step, then stop.
- **UI:** optimistic per-photo progress via Realtime; the user can leave the screen. A notification ("BMW X5: 8 photos ready") arrives when the batch completes.
- **Provider fallback:** if the primary segmentation provider fails twice, route to the secondary adapter (and record it).

---

## 10. Estimated AI processing costs per vehicle (Q8)

Assumptions: 8 exterior photos (background replacement) + 8 interior (enhancement only), 30% retake/reprocess overhead on exteriors, bilingual listing set generated twice (initial + one regenerate). Prices are as of Nov 2026 and need confirming before launch.

| Cost item | Recommended pipeline (API segmentation) | Self-hosted segmentation (scale) | Generative-per-image (not recommended) |
|---|---|---|---|
| Segmentation / generation (8 × 1.3) | ~$0.17–0.21 | ~$0.01–0.03 | $1.04 (Photoroom AI Backgrounds $0.10) to $3.00+ with previews/shadows |
| Enhancement + compositing + guard + branding (16 photos, CPU) | ~$0.02–0.04 | ~$0.02–0.04 | ~$0.02 |
| Angle classification (Haiku 5.5 vision, 16 imgs) | ~$0.003 | ~$0.003 | ~$0.003 |
| Listing text FR+EN, all formats ×2 (Sonnet 5.5, ~3k in / 3k out per run) | ~$0.07 | ~$0.07 | ~$0.07 |
| Storage (first year, R2) | ~$0.03 | ~$0.03 | ~$0.03 |
| **Total per vehicle** | **≈ $0.30–0.35** | **≈ $0.15** | **≈ $1.20–3.20+** |

Using Haiku 5.5 instead of Sonnet 5.5 for listings cuts text cost to under $0.01, but evaluate French copy quality before choosing.

Infrastructure fixed costs at launch (Vercel, Supabase Pro, worker hosting, Sentry): roughly **$150–400/month**. That is negligible per vehicle once there are a few dozen paying dealers.

---

## 11. Subscriptions and AI credits (Q9)

### 11.1 Design

- **Centralized entitlement engine** (`packages/domain/entitlements`): `can(org, feature_key)`, `limit(org, feature_key)` and `consume(org, feature_key, qty, job_id)`. Values come from `plan_entitlements` + `entitlement_overrides`. **No plan names in application code.**
- **Feature keys** (examples): `vehicles.active.max`, `ai.exterior_replacements.monthly`, `ai.enhancements.monthly`, `storage.gb.max`, `seats.max`, `branches.max`, `branding.templates.max`, `marketing.graphics.enabled`, `ai.premium_scenes.enabled`, `listing.generator.enabled`, `export.highres.enabled`.
- **Credits users understand:** sell **vehicles**, not tokens. 1 "vehicle credit" = a full photo set (up to ~20 photos, unlimited previews, re-branding and re-exports). Track AI units internally for cost control. Dealers think in cars per month, not in API calls.
- **Overage:** soft limit with clear upsell; optional prepaid credit packs. No surprise bills for small dealers.

### 11.2 Illustrative plans (to validate with customer interviews)

| Plan | Price (USD/CAD/EUR-equivalent) | Vehicles/month | Seats | Branches | Notes |
|---|---|---|---|---|---|
| Starter | ~$49/mo | 20 | 2 | 1 | 1 brand template, standard presets |
| Pro | ~$129/mo | 75 | 5 | 1 | Custom showroom background, marketing studio |
| Business | ~$299/mo | 250 | 15 | 5 | Multi-branch, priority processing |
| Enterprise / Group | Custom | Custom | Custom | Custom | SSO, data residency, SLA |

At ≈ $0.35 AI cost per vehicle, Starter's AI cost is ~$7 against $49 revenue (~85% gross margin before infrastructure and support). Compare against public small-dealer price points (e.g. CarPixAI starter ~$29/mo for 50 photos) before finalizing.

### 11.3 Stripe readiness

Stripe customer/subscription IDs are nullable columns. The `usage_ledger` maps one-to-one onto future Stripe meter events. Until Phase 7, plans are assigned by a platform admin (manual or trial). **No fake checkout.**

---

## 12. Major technical risks (Q10)

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| 1 | **Output doesn't look professional** (paint reflections, perspective mismatch between phone height and plate, flat lighting) | Product fails regardless of features | 2-week quality spike on 100+ real dealer photos; plates per angle class and camera height; capture coaching; human-rated quality benchmark used for every pipeline change |
| 2 | **Glass / see-through windows** reveal the old background | Uncanny results | Glass alpha + window-fill option; benchmark per provider |
| 3 | **Segmentation failures** on dark cars at night, cars partially out of frame, cars touching other cars | Bad cut-outs | On-device framing checks; confidence scoring; manual touch-up brush (SAM 2 point prompts) as a later feature |
| 4 | **Provider dependency / price changes** (Photoroom repriced its API in 2026) | Margin erosion | Adapter layer, second provider wired from day one, self-hosted BiRefNet path |
| 5 | **Model licensing** (e.g. RMBG-2.0 open weights are non-commercial) | Legal exposure | License review per model before self-hosting |
| 6 | **iOS PWA camera/upload limits** | Frustrated photographers | IndexedDB queue, resumable uploads, Capacitor wrapper as planned escape hatch |
| 7 | **Slow lot Wi-Fi / 4G** with 16 × 4 MB photos | Upload stalls | Client-side resize, resumable multipart, background-sync where supported |
| 8 | **Misleading advertising / integrity claims** | Dealer legal risk, reputational risk | Preservation-by-construction, identity guard, edit lineage, optional C2PA |
| 9 | **Cross-tenant data leak** | Existential | RLS + service checks + automated cross-tenant test suite + signed URLs only |
| 10 | **Crowded market** | Low acquisition, price pressure | Small-dealer price point, FR/EN parity, simplicity, listing and marketing workflow, custom-showroom feature |
| 11 | **LLM hallucinating vehicle facts** | Legal/consumer risk | Grounded structured output + numeric/feature trace check + mandatory human approval |
| 12 | **Scope**: 9 modules × FR/EN × mobile/desktop × admin | Slow time-to-market | Cut the MVP hard (see §13) |

---

## 13. What to build first (Q11) and development roadmap

### 13.1 Recommendation: add a "Phase 1.5" quality spike, and cut the MVP

**Phase 1.5: pipeline quality spike (1–2 weeks, can run in parallel with early Phase 2)**
- Collect 100–200 real smartphone photos from 3–5 target dealers (varied cars, colours, lighting).
- Run segmentation through Photoroom, Bria and BiRefNet. Composite onto 3 candidate plate styles with synthetic shadows.
- Score with a written rubric (edge quality, glass, shadow realism, "would you post this?") by dealers, not just engineers.
- Output: a provider choice, a plate design spec, capture-coaching rules, and a go/no-go on the quality bar.

**MVP (what a dealer pays for on day one):**
capture/upload with guidance → background replacement (presets + custom showroom) → enhancement → logo branding → ZIP export with naming convention → FR/EN listing text. That is Phases 2–5 plus the listing generator and exports from Phase 6.

**Defer:** full marketing graphics studio, multi-branch billing nuances, platform usage analytics beyond basics, premium generative scenes.

### 13.2 Phased roadmap (indicative, 1–2 full-stack engineers)

| Phase | Content | Indicative duration |
|---|---|---|
| 1.5 | Pipeline quality spike (above) | 1–2 wks |
| 2 Foundation | New repo/monorepo, CI, Supabase (auth, DB, RLS), R2, i18n FR/EN, org registration + onboarding wizard, roles/invitations, branding profile + logo upload, vehicle CRUD + statuses, secure upload/download service, audit log, entitlement engine (admin-assigned plans), cross-tenant test harness, design system + app shell (mobile/desktop nav) | 4–6 wks |
| 3 Smart Photography | Camera module + angle overlays, session progress, partial sessions/skip optional, gallery upload + angle auto-classification, on-device quality checks + retake prompts, upload queue | 3–4 wks |
| 4 AI Photo Studio | Worker services, pg-boss jobs, segmentation adapters ×2, plates/presets, compositing + shadows + glass, enhancement modes, previews, apply-to-all, before/after slider, identity guard, approval workflow, cost tracking, admin job console | 4–6 wks |
| 5 Branding Engine | Templates, placement/safe-zones (avoid covering the car, using the mask), batch branding, per-image toggle | 2 wks |
| 6 Marketing Studio | Listing generator (FR/EN, grounded), exports/ZIP/naming, then marketing graphics templates and social formats | 3–4 wks |
| 7 Monetization | Stripe Billing, checkout/portal, meters from ledger, dunning, tax | 2–3 wks |
| 8 Production readiness | E2E (Playwright, mobile emulation), cross-browser, load tests on the job pipeline, AI failure/retry chaos tests, security review/pen test, entitlement tests | 2–3 wks (continuous from Phase 2) |

Testing is not saved for Phase 8. Unit tests for domain/entitlements/RBAC, RLS tests, and pipeline golden-image tests are written alongside each phase.

---

## 14. Decisions needed from you before Phase 2

1. **Approve a new repository** (e.g. `ai-auto-studio`) instead of building inside `chickmundi/docs`.
2. **Primary launch market:** Quebec/Canada, France/EU, or both. This sets data region, legal texts and default currency.
3. **Web app first (PWA, Capacitor later)** vs. native app from day one.
4. **Stack approval:** Next.js + Supabase (Postgres/Auth) + Cloudflare R2 + pg-boss + Python image worker.
5. **Run the Phase 1.5 quality spike?** If yes: can you source 100+ real photos from dealers you know?
6. **Plate blurring:** keep "preserve plates" as default with an opt-in blur?

---

### Sources (pricing checked 2026-11-16; confirm before budgeting)

- Photoroom API pricing: [docs.photoroom.com](https://docs.photoroom.com/remove-background-api-basic-plan/pricing), [photoroom.com/api/api-pricing-transition](https://photoroom.com/api/api-pricing-transition), [photoroom.com/api/pricing](https://www.photoroom.com/api/pricing), [usagepricing.com/blueprint/photoroom](https://usagepricing.com/blueprint/photoroom)
- fal background-removal models (Bria RMBG 2.0, BiRefNet, Pixelcut): [fal.ai background remover APIs](https://fal.ai/explore/background-remover-apis), [fal.ai best background remover APIs 2026](https://fal.ai/learn/tools/best-background-remover-apis-2026), [fal.ai BiRefNet](https://fal.ai/models/fal-ai/birefnet/llms.txt)
- Automotive competitors: [cloudpano.com comparison](https://www.cloudpano.com/blog/best-car-image-processing-apis-dealerships), [photoroom.com Spyne alternatives](https://www.photoroom.com/blog/best-ai-automotive-photography-tools-and-spyne-alternatives), [getapp.com CarPixAI](https://www.getapp.com/all-software/a/carpixai/)
- Claude API pricing: Anthropic model pricing table (Sonnet 5.5 $2/$10, Haiku 5.5 $0.10/$0.50 per million tokens).
