# StockPilot — SaaS UI Redesign Plan

> **Status:** Planning only. No code has been changed by this document.
> **Scope:** Full responsive SaaS redesign of the portal frontend — public marketing
> (landing), auth/setup, and every authenticated page — without dropping any page or
> feature.
> **Frontend root:** `portal/resources/` (React SPA in `portal/resources/js`).

This document is a living blueprint. Update it as implementation proceeds; it is the
single source of truth for the redesign.

---

## 1. Goals & principles

1. **SaaS-grade public presence** — a marketing landing page with pricing, features,
   and clear CTAs, because the product currently opens directly into a login wall.
2. **A cohesive, polished, modern product UI** — one design language across
   marketing, auth, and the app, instead of the current "utility" look.
3. **Full responsiveness** — every page usable and pleasant from 320px phones up to
   wide desktop, with a consistent strategy (see §6) rather than per-page ad-hoc fixes.
4. **Zero feature loss** — every existing page, action, filter, bulk operation, and
   deep-link must survive. This is a reskin + layout + UX upgrade, not a feature cut.
5. **Non-negotiables preserved** — the append-only stock ledger and tenant scoping
   (`organization_id`) live in the backend and are untouched by a UI change. The Woo
   plugin's read-only constraint is likewise untouched. See §11.

---

## 2. Current state audit (what exists today)

### 2.1 Stack

| Concern | Value |
|---|---|
| Framework | React 19 + TypeScript |
| Build | Vite 8 + `laravel-vite-plugin` (single bundle) |
| Styling | Tailwind CSS v4 (`@import 'tailwindcss'` in `portal/resources/css/app.css`) |
| Data | TanStack Query v5 |
| Routing | React Router v7 (`portal/resources/js/app.tsx`) |
| HTTP | axios (`portal/resources/js/bootstrap.ts`, `lib/api.ts`) |
| Font | Instrument Sans via Bunny — weights 400/500/600 only |

### 2.2 Entry & routing

- `portal/resources/views/app.blade.php` mounts the SPA into `#app`.
- `portal/routes/web.php` is a catch-all that serves `app` for every non-`api/*` /
  `sanctum/*` GET, so **all routes are React routes** — no new Blade wiring is needed
  to add pages.
- `app.tsx` flow: `QueryClientProvider → BrowserRouter → AuthProvider → SetupGate →
  AppRoutes`.
  - `<Protected />` guards the app shell; unauthenticated users are redirected to `/login`.
  - `SetupGate` shows the install wizard while `/setup/status` reports not-installed.

### 2.3 Routes / pages

**Public / auth (no shell):**
`/login`, `/register`, `/forgot-password`, `/reset-password`, plus the in-app
`Setup` wizard (rendered by `SetupGate`, not a route).

**Authenticated (inside `<Layout/>`):**
`/` Dashboard, `/items`, `/products`, `/orders`, `/movements`, `/forecasts`,
`/activity`, `/reports`, `/stores`, `/settings`, `/users`.

### 2.4 UI primitives (`components/ui.tsx`)

`Badge`, `Button`, `Input`, `Select`, `Card`, `StatCard`, `Spinner`,
`FullPageSpinner`, `Modal`, `PageHeader`, `Table`, `Pagination`, `EmptyState`, `Flash`.
No `Textarea`, `Switch`, `Tabs`, `Tooltip`, `Dropdown`, `Toast`, `Skeleton`,
`Avatar`, or `Tag` — these will be added as needed.

### 2.5 Current gaps (the "why" of this redesign)

| # | Gap | Evidence |
|---|---|---|
| 1 | No public landing page | `web.php` catch-all → `app.blade.php` → `/` is authed-only |
| 2 | Auth pages are flat single-column cards | `Login.tsx` / `Register.tsx` etc. all use the same centered `Card` on `bg-gray-100` |
| 3 | Minimal design tokens | `app.css` sets only `--font-sans`; no brand scale, radii, shadows, dark variant |
| 4 | Only 3 font weights | `vite.config.js` loads Instrument Sans 400/500/600 — no bold/display weights for marketing |
| 5 | Inconsistent responsive handling | `Layout` has a drawer, `Orders` has a card view, but `Items`/`Products`/`Activity`/`Reports`/`Stores`/`Users` fall back to horizontal-scroll tables |
| 6 | No dark mode / theme | `app.css` has no `@custom-variant dark` |
| 7 | No code splitting | `app.tsx` imports every page eagerly; no `React.lazy` |
| 8 | Stale welcome view | `views/welcome.blade.php` references `app.js` and `@fonts` and is not part of the served flow |

---

## 3. Target information architecture

### 3.1 Public site (marketing, no auth)

```
/                  Landing (hero, features, how it works, pricing, testimonials, CTA, footer)
/pricing           Pricing plans (could be a landing section anchor initially)
/features          Feature deep-dive (optional, may be anchor sections on "/")
/login             → existing auth
/register          → existing auth
```

> Decision: keep marketing as **React routes** inside the existing SPA (reuse the
> same bundle and Tailwind). The catch-all already serves the SPA, so no backend
> route changes are required. Marketing routes are added **outside** `<Protected/>`.
> (Alternative — a dedicated Blade template — is recorded in §12 as a fallback if
> SSR/SEO becomes a requirement.)

### 3.2 Authenticated app (inside `<Layout/>`) — unchanged route set

All 11 existing pages remain at their current paths. The sidebar nav order and
labels stay (they are already correct); only styling/behaviour changes.

### 3.3 New app-level surfaces (additive, no route changes)

- **Global command palette / search** (`⌘K`) — jump to any page, search items/orders.
- **Toast notifications** replacing ad-hoc `<Flash>` where appropriate (keep `<Flash>`
  for inline form errors).
- **Persistent topbar** with workspace switcher (future), global search, theme toggle,
  user menu — currently only a mobile header exists.

---

## 4. Design system (tokens & foundations)

### 4.1 Brand palette (extend `@theme` in `app.css`)

| Token | Value | Usage |
|---|---|---|
| `--color-brand-*` (50–950) | indigo-scale mapped to a slightly deeper, more saturated indigo | primary actions, active nav |
| `--color-accent-*` | violet/purple scale | AI/forecasting accents, marketing gradients |
| Neutral | keep Tailwind `gray`; add `--color-slate-*` if a cooler neutral is preferred for marketing |
| Semantic | emerald (positive), red (danger/low), amber (warning), blue (info), purple (secondary) — already used |

Keep the **primary = indigo** convention already established in `AGENTS.md`, but
formalise it as `brand` so marketing and app share one source of truth.

### 4.2 Typography

- Keep **Instrument Sans** for UI text.
- Add **bold/extra-bold weights** (700, 800) to the Bunny font import for marketing
  headlines and stat values.
- Optionally add a **display serif** (e.g. Instrument Serif) for marketing hero only —
  flagged as optional to avoid bundle/font weight creep.
- Define type scale utilities in `@theme` (`.text-display`, `.text-hero`) rather than
  inline `text-5xl` everywhere.

### 4.3 Spacing / layout

- Standard page gutters: `px-4 sm:px-6 lg:px-8`; content max-width already `max-w-6xl`
  in `Layout.tsx` — widen to `max-w-7xl` for desktop comfort and add `xl:max-w-[1440px]`.
- Card padding: unify `p-4 sm:p-6`.
- Radii: `rounded-lg` (controls), `rounded-xl` (cards), `rounded-2xl` (marketing/hero).

### 4.4 Elevation & borders

- Add shadow tokens: `--shadow-soft`, `--shadow-card`, `--shadow-modal`, `--shadow-nav`.
- Replace heavy `border-gray-200` walls with subtle `ring-1 ring-gray-900/5` +
  `shadow-soft` where a lighter, "floating card" look is desired (optional toggle).

### 4.5 Dark mode

- Add `@custom-variant dark (&:where(.dark, .dark *));` and a `.dark` class on `<html>`.
- Add `--color-*` overrides via CSS variables so both themes are token-driven.
- Persist preference in `localStorage` + `prefers-color-scheme` fallback; expose a
  theme toggle in the topbar.

### 4.6 Icons

- Replace emoji-based nav icons (`▦ 📦 🧩 …`) and ad-hoc glyphs (`☰ ✕ 🗑`) with a real
  icon set. Recommend **`lucide-react`** (tree-shakeable, React-native) or inline
  SVG. Emoji in empty states / marketing can stay as flavour, but **functional** icons
  (nav, actions, status) should be crisp vector icons for a professional look.

---

## 5. Component library plan (extend `components/ui.tsx`)

Add new primitives; **do not** restyle buttons/inputs ad hoc (existing convention).

| Component | Notes |
|---|---|
| `Textarea` | multi-line inputs (settings, reasons) |
| `Switch` | toggle for allow-negative, "low stock only" |
| `Tabs` | unified tab primitive (replaces hand-rolled buttons in Dashboard, Reports, Forecasts) |
| `Tooltip` | thresholds, "differs from cached" hints |
| `DropdownMenu` | user menu, row overflow actions |
| `Toast` / `Toaster` | global success/error notifications |
| `Skeleton` | loading placeholders (replace raw `<Spinner>` centering on lists) |
| `Avatar` | team member / created-by initials |
| `EmptyState` | already exists — extend with optional `action` slot |
| `DataTable` | optional wrapper that gives columns a responsive "card" collapse (see §6) |
| `SearchInput` | debounced search with clear button + `⌘K` hint |
| `StatusBadge` | shared status→tone mapping (currently duplicated in Dashboard/Orders/ReservationsPanel) |

> **Refactor opportunity:** extract the duplicated `stateTone` / `compTone` /
> `statusTone` maps into one `lib/status.ts` module used by Dashboard, Orders,
> ReservationsPanel, and Activity.

---

## 6. Responsive strategy (single, consistent system)

Breakpoints: `sm 640 · md 768 · lg 1024 · xl 1280 · 2xl 1536`.

**Shell:**
- `< md`: mobile header + slide-in drawer (already exists) — polish with focus trap,
  `Esc` to close, `aria-modal`, body scroll lock, and an animated backdrop.
- `≥ md`: fixed left sidebar. Add a **collapsible icon-only rail** at `md–lg` if width
  is tight, expanding to full labels at `lg`.
- Add a persistent **topbar** (`≥ md`) for breadcrumbs/page title, global search,
  theme toggle, and user menu.

**Data tables (the main responsive pain point):**
Standardise on one of two patterns per table, chosen by information density:

1. **Card-stack (< `sm`)** — each row becomes a card (already proven in `Orders.tsx`).
   Use for: Orders, Items, Products, Users, Stores.
2. **Horizontal scroll** — keep `<Table>`'s existing `overflow-x-auto`, but add a
   visible scroll affordance (sticky first column optional). Use for: Activity,
   ReservationsPanel, Reports, MovementAnalytics breakdown (wide numeric grids).

To avoid maintaining two layouts per page, build a `ResponsiveTable` (or a
`DataTable` with a `cardRender` prop) so the card view is generated from the same
column definitions.

**Forms/modals:**
- `Modal` already caps at `max-h-[92vh]` with scroll; add bottom-sheet behaviour on
  `< sm` (slide up, full width, rounded top) for a native feel.
- Grids inside forms collapse `sm:grid-cols-2 → 1` (already standard).

**Marketing pages:**
- Mobile-first single column; `md` two-column feature rows; `lg` three-column
  pricing/card grids. Sticky mobile nav with hamburger; desktop nav with CTA.

---

## 7. Page-by-page redesign

### 7.1 NEW — Landing page `/`

Sections (top → bottom):

1. **Sticky marketing nav** — logo, links (Features, How it works, Pricing), "Sign in",
   primary "Start free" → `/register`.
2. **Hero** — headline + subhead, primary CTA (`/register`) + secondary ("See how it
   works"), product screenshot/mockup (use a realistic dashboard screenshot), social
   proof strip (e.g. "Built for WooCommerce kit/assembly sellers").
3. **Trust / value props** — 3–4 cards: real-time stock ledger, multi-level BOM/kits,
   WooCommerce auto-sync, AI forecasting.
4. **How it works** — 3 steps: connect store → map recipes → auto-deduct & forecast.
5. **Features deep-dive** — alternating image/text rows (Dashboard, BOM recipes,
   Movement analytics, AI forecasting).
6. **Pricing** — 2–3 tiers (Free/Pro) with feature lists; CTA to `/register`. Keep
   copy generic; no billing backend is implied.
7. **Testimonials / FAQ** — short social-proof + accordion FAQ (SEO + conversion).
8. **Footer** — product links, legal placeholders, "Sign in" and "Start free" CTAs.

**Routing:** add `<Route path="/" element={<Landing/>}/>` for guests **outside**
`<Protected/>`. Preserve the existing behaviour where an authenticated user at `/`
still sees the Dashboard — implement a `LandingGate`/redirect: if `user` is logged in
and lands on `/`, show Dashboard (or a "Go to app" banner); if guest, show Landing.

> Conflict note: today `/` is inside `<Protected/>`. The redesign must split `/` into
> a public `Landing` route with an auth-aware redirect, and move the app Dashboard to
> `/dashboard` (or keep `/` as the app home and use `/home` for landing). **Recommend
> `/` = landing for guests, `/` = dashboard for authed users** via conditional render;
> the explicit app home moves to `/dashboard` with a redirect shim. Update `Layout`
> nav `Dashboard` link accordingly.

### 7.2 Auth & onboarding pages

Shared shell: **split-panel layout** on `md+` — left brand/feature panel (gradient,
logo, 2–3 bullets/testimonial), right form panel. On `< md`, brand collapses to a
compact header above the form.

| Page | Redesign notes |
|---|---|
| `Login` | Split panel; add "remember me" (cosmetic), social-proof line; keep email/password + forgot + register links; preserve all existing behaviour |
| `Register` | Split panel; keep all fields (name, email, password, org name, currency); improve currency `Select`; add password strength meter (client-only, cosmetic); preserve `timezone` auto-detect |
| `ForgotPassword` | Split panel; keep generic anti-enumeration message behaviour |
| `ResetPassword` | Split panel; keep token/expiry states (invalid link, success) exactly |
| `Setup` (wizard) | Convert 2-step wizard into a **progress-stepper** component (visual only, logic unchanged); split panel on `md+` |

### 7.3 App pages (inside `<Layout/>`)

| Page | Changes (visual/UX only) |
|---|---|
| **Dashboard** | Keep all cards. Use `Tabs` for Materials/Products and Today/Week/Year toggles. Add skeleton loading. Welcome banner → gradient hero. Keep deep-links & counts. |
| **Items** | Card-stack on `< sm`. Keep search/category/low-only filters, CRUD modal, adjust modal, reservations inline panel. Add `Switch` for "low stock only". |
| **Products** | Card-stack on `< sm`. Keep "no recipe yet", CRUD, Reservations panel, `RecipeEditor` (BOM). Improve recipe editor layout (sticky "material cost / unit" summary). |
| **Orders** | Already has card view — unify it through `ResponsiveTable`. Keep all filters, bulk-action bar, order detail modal (items, components, status history, all actions). |
| **MovementAnalytics** | Keep period/entity toggles (convert to `Tabs`), summary cards, timeline bar chart, breakdown table. Make the chart responsive (already proportional). |
| **Activity** | Horizontal-scroll table with sticky first column optional. Keep all four filters + search + pagination. |
| **Reports** | Convert ad-hoc tab buttons to `Tabs`. Keep asset-value & consumption tabs and all sub-cards/tables. |
| **Stores** | Card-stack on `< sm`. Keep connect/rotate/revoke + credentials modal + how-to card. |
| **Settings** | Keep all cards (Company, status→action map, low-stock warnings, test email). Add `Switch` for allow-negative, `Textarea` where relevant. Add section nav (sticky sub-nav) since the page is long. |
| **Users** | Card-stack on `< sm`. Keep add/role/activate/remove. Add `Avatar` + role colour coding. |
| **Forecasts** | Already modern (stat cards + underline tabs). Convert to shared `Tabs`. Keep all three tabs (risks, recommendations, engine) and the ML schema cards. |

---

## 8. Routing & code changes map

| File | Change |
|---|---|
| `portal/resources/js/app.tsx` | Add marketing routes; split `/` into public landing vs authed dashboard; add `React.lazy` + `Suspense` code-splitting per page; add `Toaster` |
| `portal/resources/js/components/Layout.tsx` | Topbar (md+), collapsible rail option, icon set, theme toggle, command palette trigger, drawer a11y |
| `portal/resources/js/components/ui.tsx` | Add `Textarea`, `Switch`, `Tabs`, `Tooltip`, `DropdownMenu`, `Toast`, `Skeleton`, `Avatar`, `ResponsiveTable`, extend `EmptyState`/`Modal` |
| `portal/resources/js/lib/status.ts` (new) | Centralise status→tone/label maps |
| `portal/resources/js/pages/Landing.tsx` (new) | Marketing page (or a `pages/marketing/` folder) |
| `portal/resources/js/pages/*.tsx` | Per-page reskin per §7.3 (no logic changes) |
| `portal/resources/css/app.css` | Add `@theme` tokens, `@custom-variant dark`, type utilities |
| `portal/vite.config.js` | Add font weights (700/800), optional display font |
| `portal/resources/views/welcome.blade.php` | Remove/retire stale view (or leave — it's unused) |

---

## 9. Accessibility (baseline for all new UI)

- Focus-visible rings on all interactive elements; visible focus for keyboard nav.
- `aria-label` on icon-only buttons; `aria-modal` + focus trap + `Esc` on Modal/Drawer.
- Contrast ≥ 4.5:1 for text (verify indigo-600 on white in dark mode too).
- Reduced-motion: respect `prefers-reduced-motion` for drawer/modal transitions.
- Landmark structure: `header`, `nav`, `main`, `footer`; single `h1` per page.
- Forms: labels already wired via `Input`/`Select`; ensure error text is tied via
  `aria-describedby`.

---

## 10. Performance

- **Code-split** every page with `React.lazy` so marketing/auth don't load the full app.
- Keep TanStack Query defaults (`refetchOnWindowFocus: false`) unchanged.
- Load only needed icon imports (tree-shaking).
- Font: add only 2 extra weights; avoid loading a display font unless approved.
- Verify no layout shift on `Skeleton` → content swap (fixed heights).

---

## 11. Constraints & safety (do not break)

1. **Append-only ledger** (`sp_item_movements`) and **tenant scoping**
   (`organization_id`) are backend invariants — this redesign only changes
   presentation and must not alter any API payload shape or mutation semantics.
2. **No route removal** — every existing path and deep-link (`/orders?order=<id>`,
   `/activity?q=…`) must keep working; add redirects where `/` changes.
3. **Woo plugin read-only** contract is unaffected.
4. **No hand-edits** to `vendor/`, `node_modules/`, `portal/public/build/`,
   `portal/storage/`, or applied migrations. All frontend changes are in
   `portal/resources/` + `portal/vite.config.js`.
5. **Type-check gate:** `npx tsc --noEmit` must pass; `npm run build` must succeed
   (Vite build does not type-check, so run both).

---

## 12. Open questions (to decide before/during build)

- [ ] Landing route scheme: `/` = landing-for-guests + dashboard-for-authed (with
  `/dashboard` shim) **vs** `/` stays app and landing lives at `/home`/`/welcome`.
- [ ] Icon library: `lucide-react` vs inline SVG sprite.
- [ ] Dark mode: ship in v1 of the redesign or phase 2?
- [ ] Marketing copy/brand: confirm pricing tiers, testimonials, and screenshots.
- [ ] SEO: is server-rendering the marketing page required (then a Blade template +
  `react` SSR or a static marketing build is needed instead of client-only React)?
- [ ] Display font (Instrument Serif) — yes/no to keep font payload small.

---

## 13. Implementation roadmap (non-destructive, incremental)

> Each phase lands independently and is verifiable; later phases never regress the
> append-only ledger or tenant-scoping behaviour.

- **Phase 0 — Foundations.** Add design tokens + dark variant + font weights to
  `app.css`/`vite.config.js`. Add missing `ui.tsx` primitives. Extract
  `lib/status.ts`. `npx tsc --noEmit` + `npm run build` green.
- **Phase 1 — Marketing & auth shell.** Build `Landing` (+ `/pricing` if split), split
  panel auth pages, Setup stepper, landing-vs-dashboard route split with redirects.
- **Phase 2 — App shell.** Topbar, icon set, collapsible rail, drawer a11y, theme
  toggle, `Toaster`.
- **Phase 3 — Page reskins (one PR per page).** Order: Dashboard → Items → Products →
  Orders → MovementAnalytics → Activity → Reports → Stores → Settings → Users →
  Forecasts, each introducing `ResponsiveTable`/`Tabs`/`Skeleton` as needed.
- **Phase 4 — Polish & perf.** `React.lazy` code-splitting, reduced-motion, final a11y
  audit, cross-browser pass.

---

## 14. Verification checklist

- [ ] `npx tsc --noEmit` passes.
- [ ] `npm run build` succeeds.
- [ ] `php artisan test` still passes (backend unaffected, sanity check).
- [ ] Every route from §2.3 loads at 320px, 768px, 1024px, 1440px.
- [ ] Deep links still resolve: `/orders?order=<id>`, `/activity?q=…`.
- [ ] Guest at `/` sees landing; authed user at `/` sees Dashboard.
- [ ] Keyboard-only pass: tab through nav, modals, drawer, tables.
- [ ] Dark mode (if shipped) has no contrast regressions.
- [ ] No change to `sp_item_movements` semantics or API payload shapes.
