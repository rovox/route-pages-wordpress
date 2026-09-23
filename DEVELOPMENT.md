# Trufi Route Pages — Development Guide

A WordPress plugin that renders **route pages** for Trufi maps as virtual posts and generates a **WordPress core sitemap** from route data served by a Trufi / OpenTripPlanner GraphQL endpoint. Built for the Trufi Association.

## How the plugin works

### Bootstrap — no autoloader

- Entry point: `trufi-maps.php`. It refuses to load on PHP < 8.1 (`version_compare` guard) and `require`s every PHP file **in a fixed order**. Namespaced classes (`App\...`) are **not** autoloaded.
- To add code: register the file in the require chain of `trufi-maps.php`, or require it at point of use (see `functions/sitemap-provider.php:7`).
- Admin-only files (`admin/*`) are loaded only when `is_admin()`.
- All option names are `TRUFI_*_OPTION` constants centralized in `functions/constants.php` — reuse them, never string-literal option names.

### Route pages (virtual posts)

There is no real post for a route. The whole feature is a fabrication pipeline:

1. **Rewrite** (`functions/rewrite-rules.php`): `^{base}/([^/]+)/([^/]+)/?` maps to query vars `trufi_map_name` + `trufi_map_id`. `{base}` defaults to `routes` but is derived from the **Base Page for Maps** setting via `trufi_get_base_path()` (`functions/functions.php`).
2. **Render** (`functions/template-redirect.php`, on `template_redirect`): fabricates a global `$post`, stamps `is_page` / `is_singular` on `$wp_query`, and injects the minified template HTML into `$post->post_content`. `the_title` is filtered to the route name (with an add/remove hack around nav menus). A companion `pre_get_posts` filter (`disable_other_posts`) prevents unrelated posts leaking into the main query.
3. Route data comes from the GraphQL query `pattern(id) { route { id, shortName, longName }, geometry { lat, lon } }` (`App/Api/TrufiApi.php`).

**URL base path — single source of truth:** both the rewrite rule (`functions/rewrite-rules.php`) and the sitemap provider (`App/Providers/TrufiRoutesSitemapProvider.php`) call the shared `trufi_get_base_path()` helper (`functions/functions.php`), which resolves to `get_post_field('post_name', $map_page_id)`. They used to diverge — the sitemap called `get_page_uri()` instead, which returns the full hierarchical path for a child page and silently produced sitemap URLs that didn't match the rewrite rule (404s) unless the Base Page was top-level. Fixed by routing both through the same helper; **still create the Base Page as top-level** since a page's slug isn't guaranteed unique across the site otherwise.

**Slug convention:** route URL slug = `sanitize_title(longName with `→` replaced by a locale word)` via `App/Utility.php::replaceArrowWithWord` (ES→`a`, DE→`zu`, EN→`to`, …).

### Sitemap

- `functions/sitemap-provider.php` registers `App\Providers\TrufiRoutesSitemapProvider` on `init`.
- The provider queries `patterns { code, route { longName } }`, builds `https://<home>/<base>/<slug>/<code>` URLs, and caches the full list in the transient `trufi_routes_sitemap_xml`.
- Pagination is real, not hardcoded: `get_max_num_pages()` / `get_url_list()` both go through `wp_sitemaps_get_max_urls('routes')` (core default: 2000 URLs/page, filterable via the `wp_sitemaps_max_urls` filter) and slice the cached list per page. A network with more routes than that will correctly get `/wp-sitemap-routes-2.xml`, etc., instead of one oversized sitemap.
- Served by WordPress core at `/wp-sitemap-routes-1.xml` (requires pretty permalinks).

### Templates & rendering

- `templates/map-template.html` is a static shell with `{{placeholder}}` tokens. `template-redirect.php` does a `str_replace` with values fetched from the settings (line color, store URLs, API URL, …) and injects the full GraphQL response as JSON into a `<script>const data = ...;</script>` block. It also injects `{{routeName}}` / `{{routeShortName}}` / `{{routeDescription}}` (route-specific, `esc_html()`-escaped text) so the rendered page has real per-route visible content instead of a generic, identical-on-every-page blurb.
- `minify_html()` (`functions/functions.php`) strips HTML comments and whitespace at render time.
- Leaflet 1.7.1 (unpkg) and OpenStreetMap tiles are hardcoded in the `wp_head` tags and the template. There is **no bundler** — edit in place.
- `trufi_add_header_tags()` (`functions/functions.php`) prints the `<head>` SEO tags: meta description, `robots`, `canonical`, Open Graph/Twitter card tags, and a `BreadcrumbList` JSON-LD block (Home → base page → route). See the "SEO & AI-crawler notes" section of `README.md` for the rationale.

### Caching & settings

- Two transient caches:
  - Per-route: `trufi_route_data_{mapId}` (in `template-redirect.php`).
  - Sitemap: `trufi_routes_sitemap_xml` (in the sitemap provider).
- TTL comes from the `trufi_cache_ttl` option (hours).
- The admin **Clear Cache** page (`delete_trufi_transients()` in `admin/transient-cache-clear.php`) clears via raw `option_name LIKE` SQL and only catches the `trufi_route%` prefix — any newly named transient must keep that prefix or be added to the query.
- Saving **Settings → Trufi Routes** flushes rewrite rules and clears transients (`admin/admin-settings.php:50-51`).

## Development workflow

This repo has **no build, test, lint, typecheck, or CI tooling** (CONTRIBUTING.md mentions a "test suite"/"lint check", but nothing exists here). All changes are verified manually in a real WordPress install against a Trufi GraphQL endpoint.

Verification surface:

- Sitemap: `GET /wp-sitemap-routes-1.xml`
- A rendered route page: `GET /{base}/<name>/<route-code>`

Conventions:

- Text domain is inconsistent on purpose: `TrufiApi-maps` (plugin header, most files) vs `TrufiApi-routes` (admin menu pages). Match the file you are editing; don't "fix" it silently.
- The distribution artifact is a zip named `TrufiApi-maps` (see README install steps).

## Local testing with Docker

The tracked, recommended stack is `docker-compose.dev.yml` at the repo root (WordPress + MySQL 8.4 + phpMyAdmin, plugin live-mounted). Full walkthrough: see "Local development with Docker" in `README.md`. Quick start:

```bash
docker compose -f docker-compose.dev.yml up -d
```

- WordPress: `http://localhost:8081` (plugin live-mounted from the repo into `wp-content/plugins/trufi-route-pages`).
- phpMyAdmin: `http://localhost:8080` (login as user `wordpress`).

It's named `docker-compose.dev.yml`, not `docker-compose.yml`, because this repo's `.gitignore` excludes any plain `docker-compose.yml` / `docker` path — that rule predates this stack and was left in place to keep ad-hoc local compose files untracked.

**Alternative:** `trufi-website-modules/` (if present locally) is a **separate, uncommitted git clone** of the official `trufi-association/trufi-website-modules` repo — a different WordPress + MySQL + phpMyAdmin stack maintained outside this plugin's repo. It is unrelated to this codebase; per `AGENTS.md`, don't edit, inspect, or commit it. `PLAN.md` documents the bring-up steps that were used with it in an earlier session, kept for historical reference.

### Working Trufi GraphQL endpoint (validated)

| Property | Value |
| --- | --- |
| Endpoint | `https://otp281.trufi.app/otp/routers/default/index/graphql` |
| Data | Public Trufi/OpenTripPlanner 2.8 instance for **Cochabamba, Bolivia** |
| Sitemap query | `{ patterns { code, route { longName } } }` |
| Route query | `query($id: String!) { pattern(id: $id) { route { id, shortName, longName }, geometry { lat, lon } } }` |

Configure it in **Settings → Trufi Routes → GraphQL server URL**.

#### Origin & provenance

The endpoint wasn't guessed; it is traced as follows:

1. **Host** — `otp281.trufi.app` is the default endpoint of the `Otp28RoutingProvider` in the Trufi Association's `trufi-core` example app configuration (`apps/example/lib/main.dart`, constant `_otp28Endpoint`). It is Trufi Association's **public OpenTripPlanner 2.8 dev instance** (the loaded graph is Cochabamba, Bolivia). The same file also documents the related public dev hosts `otp150.trufi.app` (OTP 1.5), `photon.trufi.app` (geocoding), `maps.trufi.app` (map tiles/styles) and `planner.trufi.app` (web planner).
2. **Path** — `/otp/routers/default/index/graphql` is OpenTripPlanner's **Legacy GraphQL API** endpoint. It's the same URL shape this plugin's README describes for the "GraphQL server URL" setting, and it is the documented convention for exposing OTP's GraphQL schema under a router named `default`.
3. **Validation** — the endpoint was confirmed live by POSTing the plugin's two exact GraphQL documents (`patterns { code, route { longName } }` and `pattern(id) { route {...}, geometry {...} }`) and receiving the standard GraphQL `{"data": {...}}` JSON envelope. At validation time `otp150.trufi.app` (the OTP 1.5 instance) was unresponsive, so it is **not** usable.

Because this is a public dev server, it may change or be rate-limited. Verify it's still up before relying on it:

```bash
curl -sS -m 15 -X POST "https://otp281.trufi.app/otp/routers/default/index/graphql" \
  -H 'Content-Type: application/json' -d '{"query":"{ patterns { code, route { longName } } }"}'
```

Don't build automated tests against it; for reproducible work, run your own OTP instance (see "Where to find information").

## Where to find information

In-repo:

- `README.md` — install/configuration steps, the Docker quick start, and the SEO/AI-crawler notes.
- `docker-compose.dev.yml` + `.env.dev.example` — the tracked local dev stack.
- `CONTRIBUTING.md` — contribution process.
- `AGENTS.md` — concise agent-facing notes and gotchas (kept up to date).
- `PLAN.md` — an earlier session's local testing bring-up plan against `trufi-website-modules/` (historical; superseded by `docker-compose.dev.yml` for new setups).
- Code:
  - `App/Api/TrufiApi.php` — the GraphQL client and the two queries used.
  - `App/Utility.php` — locale-dependent slug word (`replaceArrowWithWord`).
  - `App/Providers/TrufiRoutesSitemapProvider.php` — sitemap URL generation + caching.
  - `functions/*.php` — rewrite rules, template redirect, sitemap registration, constants, header tags.
  - `admin/*.php` — settings page, clear-cache page.
  - `templates/map-template.html` — the rendered route page shell.

External (Trufi Association / OpenTripPlanner):

- `github.com/trufi-association` — ecosystem: `trufi-core` (Flutter app; its example config documents the public endpoints `otp281.trufi.app`, `otp150.trufi.app`, `photon.trufi.app`), `trufi-server-modules`, `trufi-server-resources`, `trufi-server-multi`.
- OpenTripPlanner **Legacy GraphQL API** docs (`/otp/routers/{routerId}/index/graphql`) — the schema behind the plugin's queries.
- OpenTripPlanner official docs (`docs.opentripplanner.org`) for running your own instance.
