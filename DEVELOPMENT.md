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

1. **Rewrite** (`functions/rewrite-rules.php`): `^{base}/([^/]+)/([^/]+)/?` maps to query vars `trufi_map_name` + `trufi_map_id`. `{base}` defaults to `routes` but is derived from the **Base Page for Maps** setting (`get_post_field('post_name', ...)`).
2. **Render** (`functions/template-redirect.php`, on `template_redirect`): fabricates a global `$post`, stamps `is_page` / `is_singular` on `$wp_query`, and injects the minified template HTML into `$post->post_content`. `the_title` is filtered to the route name (with an add/remove hack around nav menus). A companion `pre_get_posts` filter (`disable_other_posts`) prevents unrelated posts leaking into the main query.
3. Route data comes from the GraphQL query `pattern(id) { route { id, shortName, longName }, geometry { lat, lon } }` (`App/Api/TrufiApi.php`).

**URL gotcha to keep in sync:** the sitemap builds `{base}` via `get_page_uri($map_page_id)` (`App/Providers/TrufiRoutesSitemapProvider.php:24`), while the rewrite rule uses `get_post_field('post_name', ...)` (`functions/rewrite-rules.php:7`). They only agree when the Base Page is a top-level page with a matching slug.

**Slug convention:** route URL slug = `sanitize_title(longName with `→` replaced by a locale word)` via `App/Utility.php::replaceArrowWithWord` (ES→`a`, DE→`zu`, EN→`to`, …).

### Sitemap

- `functions/sitemap-provider.php` registers `App\Providers\TrufiRoutesSitemapProvider` on `init`.
- The provider queries `patterns { code, route { longName } }`, builds `https://<home>/<base>/<slug>/<code>` URLs, and caches the list in the transient `trufi_routes_sitemap_xml`.
- Served by WordPress core at `/wp-sitemap-routes-1.xml` (requires pretty permalinks).

### Templates & rendering

- `templates/map-template.html` is a static shell with `{{placeholder}}` tokens. `template-redirect.php` does a `str_replace` with values fetched from the settings (line color, store URLs, API URL, …) and injects the full GraphQL response as JSON into a `<script>const data = ...;</script>` block.
- `minify_html()` (`functions/functions.php`) strips HTML comments and whitespace at render time.
- Leaflet 1.7.1 (unpkg) and OpenStreetMap tiles are hardcoded in the `wp_head` tags and the template. There is **no bundler** — edit in place.

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

`trufi-website-modules/` is a **separate, uncommitted git clone** of the official `trufi-association/trufi-website-modules` repo, providing WordPress + MySQL + phpMyAdmin containers (docker compose). See `PLAN.md` for the exact bring-up steps run during this build-out.

Quick reference:

- WordPress: `http://localhost:8081` (plugin live-mounted from the repo into `wp-content/plugins/trufi-route-pages`).
- phpMyAdmin: `http://localhost:8080` (login as user `wordpress`).
- Network: all modules join the shared `trufi-website` bridge so WordPress can reach MySQL at host `mysql`.

### Working Trufi GraphQL endpoint (validated)

| Property | Value |
| --- | --- |
| Endpoint | `https://otp281.trufi.app/otp/routers/default/index/graphql` |
| Data | Public Trufi/OpenTripPlanner 2.8 instance for **Cochabamba, Bolivia** |
| Sitemap query | `{ patterns { code, route { longName } } }` |
| Route query | `query($id: String!) { pattern(id: $id) { route { id, shortName, longName }, geometry { lat, lon } } }` |

Configure it in **Settings → Trufi Routes → GraphQL server URL**. Note: the old OTP 1.5 dev server `otp150.trufi.app` is currently unresponsive; use the OTP 2.8 endpoint above (it serves the plugin's legacy-style GraphQL at `/otp/routers/default/index/graphql`).

Because this is a public dev server, don't rely on it for automated tests; for reproducible work, run your own OTP instance (see "Where to find information").

## Where to find information

In-repo:

- `README.md` — install/configuration steps and the basic sitemap smoke test.
- `CONTRIBUTING.md` — contribution process.
- `AGENTS.md` — concise agent-facing notes and gotchas (kept up to date).
- `PLAN.md` — the local testing bring-up plan (Docker + WordPress setup).
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
