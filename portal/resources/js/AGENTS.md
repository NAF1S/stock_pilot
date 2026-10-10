# portal/resources/js — React SPA

React 19 + TypeScript + Vite 8 + Tailwind CSS v4 + TanStack Query v5 + React Router v7 + axios.
`app.tsx` mounts the app into `#app` from [../views/app.blade.php](../views/app.blade.php), which is
loaded through the `@vite` directive.

## Build & type-check

```bash
npm run dev     # Vite dev server with HMR (laravel-vite-plugin)
npm run build   # -> portal/public/build (git-ignored, rebuilt by the bundle script)
npx tsc --noEmit
```

`npm run build` runs `vite build` only — it does **not** type-check, so run `npx tsc --noEmit` before
finishing a change. The `@/*` → `resources/js/*` path alias exists in `tsconfig.json`, but existing code
uses relative imports; match the surrounding file.

## Data layer

- [bootstrap.ts](bootstrap.ts) — the single axios instance: `withCredentials`, `withXSRFToken`,
  `baseURL: '/'`, plus `getCsrfCookie()` which GETs `/sanctum/csrf-cookie`. Auth calls must await it first
  (the CSRF token is needed for the login/register POSTs).
- [lib/api.ts](lib/api.ts) — `apiGet`, `apiPost`, `apiPut`, `apiPatch`, `apiDelete`, all rooted at `/api`,
  plus `errMsg(error)` which unwraps Laravel validation errors for display.
- [lib/auth.tsx](lib/auth.tsx) — `AuthProvider` + `useAuth()` (`user`, `loading`, `login`, `register`,
  `logout`, `refresh`) and `can()`. `AuthUser.role` is `'admin' | 'manager' | 'staff'`; format money with
  `user.organization.currency`.
- Routing: `app.tsx` defines `<Routes>`; `SetupGate` renders the wizard while `/setup/status` reports the
  app is not installed, and `<Protected />` redirects unauthenticated users to `/login`.

## UI conventions

- Reuse the primitives in [components/ui.tsx](components/ui.tsx): `Badge`, `Button`, `Input`, `Select`,
  `Card`, `StatCard`, `Spinner`, `FullPageSpinner`, `Modal`, `PageHeader`, `Table`, `Pagination`,
  `EmptyState`, `Flash`. Add a variant to `ui.tsx` rather than restyling a button ad hoc.
- Style with inline Tailwind utility classes; join conditional classes with `cn()` from [lib/cn.ts](lib/cn.ts).
  Indigo is the primary colour and `bg-gray-50` the page background.
- Format values through [lib/format.ts](lib/format.ts): `money(value, currency)`, `number(value, digits)`,
  `date()`, `dateTime()`.

## Page pattern (copy an existing page such as `pages/Items.tsx`)

1. Declare a local `interface` for the API shape — snake_case, exactly as the controller's `payload()` emits.
2. Fetch with `useQuery({ queryKey: ['items', page, search], queryFn: () => apiGet<T>('/items', {...}) })`.
3. Mutate with `useMutation`, then `queryClient.invalidateQueries({ queryKey: ['items'] })`; surface errors
   with `errMsg(e)`.
4. Use local `flash` / `error` state with `<Flash>` (via `components/ui.tsx`), `<Modal>` for create/edit
   forms and `<Pagination meta={...}>` for paged lists.
5. [components/Layout.tsx](components/Layout.tsx) owns the `nav` array (note the `adminOnly` flag) — a new
   page must be registered there **and** as a `<Route>` in `app.tsx` under the `<Protected />` element.

## Adding a feature end-to-end

1. Backend: controller action + `routes/api.php` entry with the matching `role:` middleware
   (see [../../routes/AGENTS.md](../../routes/AGENTS.md)).
2. Frontend: page component → route in `app.tsx` → nav entry in `Layout.tsx`.
3. Verify: `npx tsc --noEmit` and `npm run build`, then exercise the flow in the browser.
