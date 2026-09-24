# AGENTS.md

WordPress plugin (Trufi Route Pages) with **no build tooling**: no composer, no autoloader, no tests, no linter, no CI. CONTRIBUTING.md mentions "test suite"/"lint check", but no such tooling exists in this repo. All changes are verified in a real WordPress install against a Trufi GraphQL endpoint: hit `/wp-sitemap-routes-1.xml` for the sitemap, `/{base}` for the routes index page, or a rendered route page at `/{base}/<name>/<route-code>`.

Working-tree note: `trufi-website-modules/` is a **separate, untracked git repo** (a local Docker dev stack: wordpress/mysql/phpmyadmin). It is unrelated to the plugin — don't edit, inspect, or commit it. The plugin repo has its own tracked dev stack instead: `docker-compose.dev.yml` (see README.md). It isn't named `docker-compose.yml` because `.gitignore` excludes that exact filename and any `docker`/`.docker` path — a rule already in this repo, kept for ad-hoc local files.

## Bootstrap & adding new code

- Entry point is `trufi-maps.php` (the plugin file). It `require`s every PHP file **in a fixed order**; namespaced classes (`App\...`) are NOT autoloaded.
- New classes/files must be added to the require chain in `trufi-maps.php`, or required at point of use (see `functions/sitemap-provider.php:7`).
- The main file refuses to load below `Requires PHP: 8.1` (guarded by `version_compare`). Code may use typed properties and union types.
- Admin-only files are loaded only when `is_admin()`.

## How route pages work (virtual posts)

- Requests match rewrite rule `^{base}/([^/]+)/([^/]+)/?` in `functions/rewrite-rules.php`, mapping to query vars `trufi_map_name` + `trufi_map_id`.
- No real post exists. `functions/template-redirect.php` (on `template_redirect`) fabricates a global `$post`, stamps `is_page`/`is_singular` on `$wp_query`, and injects minified template HTML into `$post->post_content`. `the_title` is filtered to the route name.
- `{base}` defaults to `routes` but is derived from the "Base Page for Maps" setting via the shared `trufi_get_base_path()` helper (`functions/functions.php`), used by both the rewrite rule and the sitemap provider — keep any future URL-generation code going through that helper rather than re-deriving the base path, or it'll drift again (it used to: the sitemap called `get_page_uri()` while the rewrite rule called `get_post_field('post_name', ...)`, diverging for non-top-level base pages).
- Route URL slug = `sanitize_title(`longName with `→` replaced by a locale word`)` — see `App/Utility.php::replaceArrowWithWord`.
- The base page itself (`GET /{base}`, i.e. the page set as "Base Page for Maps", no route segments) renders a **routes index**: searchable sidebar + map of all lines via `trufi_maps_render_routes_index()` (`functions/template-redirect.php`) + `templates/routes-index-template.html`. It calls `remove_all_filters('the_content')` before injecting; WP's default content filters (wpautop, shortcodes, emoji…) corrupt the embedded JS — keep bypassing them if you touch that function. The virtual route page (`templates/map-template.html`, injected by the same file's route branch) does the same for the same reason: its inline script passes HTML strings (e.g. Leaflet `divIcon`s) that wpautop would mangle.
- The REST endpoint and the page both `rawurldecode` the pattern id captured from the URL segment: the index JS sends it `encodeURIComponent`-encoded (`:` → `%3A`) and WP doesn't decode path segments, so an undecoded id 404s.
- The index fetches per-pattern geometry on demand from `GET /wp-json/trufi/v1/pattern/{id}` (`functions/rest-api.php`), which shares the same `trufi_route_data_{mapId}` transient as the virtual route page (so both stay in sync and both are cleared by the cache cleanup).

## Templates & rendering

- `templates/map-template.html` is a static shell with `{{placeholder}}` tokens; `template-redirect.php` does a `str_replace` with values fetched from settings (line color, store URLs, etc.) and injects the full GraphQL `{{routeData}}` JSON into a `<script>const data = ...;</script>` block. `templates/routes-index-template.html` follows the same token/`str_replace` pattern for the index page.
- Map template + header tags hardcode Leaflet 1.7.1 from unpkg and OpenStreetMap tiles (functions/functions.php, map-template.html). No bundler; edit in place.

## Caching & settings

- Three transient caches exist (all keep the `trufi_route%` prefix so the cache cleanup catches them):
  - Per-route: `trufi_route_data_{mapId}` in `template-redirect.php` and `rest-api.php` (shared key)
  - Routes index list: `trufi_routes_index_list` in `template-redirect.php`
  - Sitemap: `trufi_routes_sitemap_xml` in the sitemap provider
- TTL comes from the `trufi_cache_ttl` option (hours). The admin "Clear Cache" page (`delete_trufi_transients()` in admin/transient-cache-clear.php) clears via raw `option_name LIKE` SQL and only catches the `trufi_route%` prefix — any newly named transient must keep that prefix or be added to the query.
- All option names are `TRUFI_*_OPTION` constants centralized in `functions/constants.php` — reuse, don't string-literal option names.
- Saving settings flushes rewrite rules and clears transients (`admin/admin-settings.php:62-63`).

## Conventions

- Text domain is inconsistent: `TrufiApi-maps` (plugin header, most files) vs `TrufiApi-routes` (admin menu pages). Match the file you're editing; don't "fix" it silently.
- Distribution is a zip named `TrufiApi-maps` (`README.md` install steps).
