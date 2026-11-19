# Trufi Route Pages

![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)

Trufi Route Pages is a Wordpress plugin developed by [The Trufi Association](https://trufi-association.org/) to complement the Trufi mobile application. It allows Wordpress to receive a URL request containing a route ID and name and display it as a post. The plugin also generates a dynamic sitemap.xml based on route information from the openGraphql API.

## About The Trufi Association

We are a non-profit NGO founded by a group of German and Bolivian volunteers in 2019. Our mission is to transform mobility in cities with open source apps and open data solutions that enable innovation. We help cities realize a public transport app, even in places where transport is unregulated.

## Plugin Information

* **Plugin Name**: Trufi Route Pages
* **Plugin URI**: [https://trufi-association.org/](https://trufi-association.org/)
* **Description**: A plugin for displaying Trufi Maps.
* **Version**: 1.0
* **Author**: Trufi Association
* **Author URI**: [https://trufi-association.org/](https://trufi-association.org/)
* **License**: GPL2
* **License URI**: [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
* **Text Domain**: TrufiApi-maps

## Installation

1. Build the plugin zip from a git ref (only the plugin's own files, no dev stack/docs). The folder prefix must match the plugin folder of the site you install into (`trufi-maps` on trufi.app):
   ```bash
   git archive --format=zip --prefix=trufi-maps/ -o trufi-maps.zip HEAD \
     admin App assets functions templates trufi-maps.php LICENSE README.md
   ```
2. Use the regular Wordpress plugin installation process to install the .zip file (Plugins → Add New → Upload Plugin).
3. Navigate to Wordpress administration and then configuration.
4. Set the required values for this plugin, such as the Graphql server URL.
   1. If your are using OpenTripPlanner v1, the Graphql URL might be something like `https://yourotp.example.com/otp/routers/default/index/graphql`
   2. You need to create a new empty base page for the routes (its content is replaced by the routes index)

### Updating an installed copy

Upload the new zip the same way and choose **"Replace current with uploaded"** — the zip's folder prefix must equal the installed plugin's folder, or WordPress installs a second copy. Then open the plugin settings and click **Save** (flushes rewrite rules and clears the route caches). Don't use the built-in plugin file editor for releases: it can't add new files and has no rollback.

## Testing

You can test if the plugin works by accessing the URL path `/wp-sitemap-routes-1.xml` of your page (it should list every route, and each URL should answer 200), the base page (routes index), and `/llms.txt`.

## Local development with Docker

There is no build tooling in this repo (no composer, no autoloader, no test suite) — the plugin is verified by running it inside a real WordPress install. `docker-compose.dev.yml` (repo root) spins up that install for you: WordPress + MySQL + phpMyAdmin, with this repo live-mounted as the plugin folder so edits are reflected immediately without reinstalling anything.

Image versions used (checked 2026-09-23):

| Service | Image | Why |
| --- | --- | --- |
| WordPress | `wordpress:latest` | Ships PHP 8.3, well above the plugin's `Requires PHP: 8.1` |
| MySQL | `mysql:8.4` | Current LTS release |
| phpMyAdmin | `phpmyadmin:latest` | DB inspection UI |

### 1. Start the stack

```bash
docker compose -f docker-compose.dev.yml up -d
```

This uses sensible defaults (WordPress on `http://localhost:8081`, phpMyAdmin on `http://localhost:8080`). To override ports or credentials, copy `.env.dev.example` to `.env.dev`, edit it, then run:

```bash
docker compose --env-file .env.dev -f docker-compose.dev.yml up -d
```

### 2. Install & configure WordPress

1. Open `http://localhost:8081` and complete the install wizard.
2. **Settings → Permalinks** → set to **Post name** (required for `/wp-sitemap-*.xml` and for the plugin's rewrite rule to work at all).
3. **Plugins** → activate **Trufi Route Pages** (already present via the live mount).
4. **Pages** → create a new **top-level** page (e.g. "Routes"). It must not be a child page — see the base-path gotcha in [DEVELOPMENT.md](DEVELOPMENT.md).
5. **Settings → Trufi Routes**:
   - **API url**: a Trufi/OpenTripPlanner GraphQL endpoint (see "Testing against a real endpoint" below).
   - **Base Page for Maps**: the page created in step 4.
   - **Cache TTL**: a short value (e.g. 1 hour) while developing — saving flushes rewrite rules and clears caches automatically.

### 3. Verify

```bash
curl -s http://localhost:8081/wp-sitemap-routes-1.xml
curl -s http://localhost:8081/routes/
curl -s http://localhost:8081/routes/<route-name-slug>/<route-code>
```

The first should return an `<urlset>` of route URLs; the second is the routes index page (searchable list + map of all lines); the third is a route detail page (map, stats and stop list). Route geometry and stops are passed to the page scripts through `wp_localize_script` (`trufiRouteConfig` / `trufiIndexConfig`). Use phpMyAdmin (`http://localhost:8080`, user `wordpress`) to inspect the `trufi_route_data_*` and `trufi_routes_sitemap_xml` transients if something looks stale.

### Testing against a real Trufi GraphQL endpoint

The plugin needs a live OpenTripPlanner-compatible GraphQL server to fetch route data from — there's no local fixture/mock included. A validated public endpoint for Cochabamba, Bolivia:

```
https://otp281.trufi.app/otp/routers/default/index/graphql
```

Enter this as the **API url** in step 2 above. It's a public dev server, not guaranteed to be stable — for reproducible testing, run your own OpenTripPlanner instance (see [DEVELOPMENT.md](DEVELOPMENT.md) for links). Do not commit any endpoint that isn't publicly documented by the Trufi Association.

### Stopping / resetting

```bash
docker compose -f docker-compose.dev.yml down        # stop, keep data
docker compose -f docker-compose.dev.yml down -v      # stop and wipe the DB/WP volumes
```

## SEO, AEO/GEO and accessibility

Both virtual pages (index and route) emit:

- A single `<link rel="canonical">` (WordPress's own, which would point every route at the base page, is removed), document title, `lang="es"`, meta description, Open Graph/Twitter. Robots meta is left to WordPress core.
- JSON-LD: `BreadcrumbList` on both; `BusTrip` on route pages (origin/destination `BusStop` with coordinates, sindicato as `provider`); `ItemList` + `FAQPage` on the index.
- Server-rendered content for crawlers that don't run JS: the full list of lines/routes, the stop list in an ordered list, a "Sobre esta ruta" text and an FAQ. The copy states the service's scope: it helps find registered lines and their sindicato and shows the route between points; **hours/24-7 service are not guaranteed**.
- `/llms.txt` (markdown summary + every route link grouped by sindicato), the core sitemap `/wp-sitemap-routes-1.xml` (auto-generated from the API), and `Allow:`/`Sitemap:` lines in WordPress's virtual `robots.txt`.

Accessibility (targets WCAG 2.2 AA): skip links, `role="search"` with visible labels, one `<h1>` per page and an unbroken heading hierarchy (the theme's own title is demoted to a `<div>`), `aria-live` status messages, focusable and labelled scroll regions, stops as real `<button>`s, visible focus rings, targets ≥ 24px, `prefers-reduced-motion`.

Outside the plugin's control, check per site: WordPress's "Discourage search engines" setting must be off, a physical `robots.txt` must not block the plugin's `assets/` or AI crawlers you want, and the sitemap should be submitted to Search Console / Bing.

## Tests

No build step is needed; the checks run with stock tools:

```bash
node --test tests/search.test.js                                   # search/filter rules
wp eval-file wp-content/plugins/trufi-route-pages/tests/logic.php   # PHP logic (inside WordPress)
python3 tests/smoke.py http://localhost:8081                        # semantic HTML / SEO / JSON-LD against a running site
```

## Features

- **Routes index** at the Base Page URL (`/{base}`): a search bar (by line name, plus sindicato and transport-type filters) independent from the map, the list of lines grouped by sindicato, a map of the city and an FAQ.
- **Route detail pages** at `/{base}/<name>/<route-code>`: "Parte desde A hacia el destino B", map with direction arrows and origin/destination markers, an ordered stop list (stepper with scroll indicator) with street names, distance and a share button.
- **"Mi ubicación" button** on both maps (browser geolocation, with an approximate IP-based fallback). It can be disabled in the plugin settings.
- **Core sitemap** at `/wp-sitemap-routes-1.xml`.

CSS and JS live as plain static files under `assets/` (no bundler). See [DEVELOPMENT.md](DEVELOPMENT.md) for architecture details.

## Contributing

We appreciate all contributions! If you're interested in contributing, please see our [contributing guidelines](CONTRIBUTING.md).

## License

This project is licensed under the [GPL v2 License](https://www.gnu.org/licenses/gpl-2.0.html) - see the [LICENSE](LICENSE) file for details.

## Contact

For more information, you can reach us through our [website](https://trufi-association.org/contact).
