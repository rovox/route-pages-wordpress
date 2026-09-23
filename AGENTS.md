# AGENTS.md

WordPress plugin (Trufi Route Pages) with **no build tooling**: no composer, no autoloader, no tests, no linter, no CI. CONTRIBUTING.md mentions "test suite"/"lint check", but no such tooling exists in this repo. All changes are verified in a real WordPress install against a Trufi GraphQL endpoint: hit `/wp-sitemap-routes-1.xml` for the sitemap, or a rendered route page at `/{base}/<name>/<route-code>`.

Working-tree note: `trufi-website-modules/` is a **separate, untracked git repo** (a local Docker dev stack: wordpress/mysql/phpmyadmin). It is unrelated to the plugin — don't edit, inspect, or commit it.

## Bootstrap & adding new code

- Entry point is `trufi-maps.php` (the plugin file). It `require`s every PHP file **in a fixed order**; namespaced classes (`App\...`) are NOT autoloaded.
- New classes/files must be added to the require chain in `trufi-maps.php`, or required at point of use (see `functions/sitemap-provider.php:7`).
- The main file refuses to load below `Requires PHP: 8.1` (guarded by `version_compare`). Code may use typed properties and union types.
- Admin-only files are loaded only when `is_admin()`.

## How route pages work (virtual posts)

- Requests match rewrite rule `^{base}/([^/]+)/([^/]+)/?` in `functions/rewrite-rules.php`, mapping to query vars `trufi_map_name` + `trufi_map_id`.
- No real post exists. `functions/template-redirect.php` (on `template_redirect`) fabricates a global `$post`, stamps `is_page`/`is_singular` on `$wp_query`, and injects minified template HTML into `$post->post_content`. `the_title` is filtered to the route name.
- `{base}` defaults to `routes` but is derived from the "Base Page for Maps" setting. **Gotcha:** the sitemap reads it via `get_page_uri($map_page_id)` (App/Providers/TrufiRoutesSitemapProvider.php:24) while rewrite rules use `get_post_field('post_name', ...)` (functions/rewrite-rules.php:7). Keep them in sync when touching URL generation.
- Route URL slug = `sanitize_title(`longName with `→` replaced by a locale word`)` — see `App/Utility.php::replaceArrowWithWord`.

## Templates & rendering

- `templates/map-template.html` is a static shell with `{{placeholder}}` tokens; `template-redirect.php` does a `str_replace` with values fetched from settings (line color, store URLs, etc.) and injects the full GraphQL `{{routeData}}` JSON into a `<script>const data = ...;</script>` block.
- Map template + header tags hardcode Leaflet 1.7.1 from unpkg and OpenStreetMap tiles (functions/functions.php, map-template.html). No bundler; edit in place.

## Caching & settings

- Two transient caches exist:
  - Per-route: `trufi_route_data_{mapId}` in `template-redirect.php`
  - Sitemap: `trufi_routes_sitemap_xml` in the sitemap provider
- TTL comes from the `trufi_cache_ttl` option (hours). The admin "Clear Cache" page (`delete_trufi_transients()` in admin/transient-cache-clear.php) clears via raw `option_name LIKE` SQL and only catches the `trufi_route%` prefix — any newly named transient must keep that prefix or be added to the query.
- All option names are `TRUFI_*_OPTION` constants centralized in `functions/constants.php` — reuse, don't string-literal option names.
- Saving settings flushes rewrite rules and clears transients (`admin/admin-settings.php:50-51`).

## Conventions

- Text domain is inconsistent: `TrufiApi-maps` (plugin header, most files) vs `TrufiApi-routes` (admin menu pages). Match the file you're editing; don't "fix" it silently.
- Distribution is a zip named `TrufiApi-maps` (`README.md` install steps).
