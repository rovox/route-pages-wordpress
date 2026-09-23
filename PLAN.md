# Implementation Plan — Trufi Route Pages local testing setup

Plan for **today's session** (2026-09-23). Status: Phases 0–3 pending user-assisted bring-up; Phases 4–5 done (docs + commit).

## Goal

- Open the project in VS Code.
- Bring up and modernize the Docker test infrastructure in `trufi-website-modules/`.
- Verify the plugin end-to-end against a validated public Trufi GraphQL endpoint.
- Write `DEVELOPMENT.md` in English.
- Prepare a commit that updates the GitHub repository (`origin/main` of `rovox/route-pages-wordpress`).

## Validated facts (checked this session)

- **GraphQL endpoint:** `https://otp281.trufi.app/otp/routers/default/index/graphql` answers *both* plugin queries against real Cochabamba data:
  - `patterns { code, route { longName } }` (sitemap)
  - `pattern(id) { route { id, shortName, longName }, geometry { lat, lon } }` (route page)
- `otp150.trufi.app` (the OTP 1.x dev server) is currently unresponsive — do not use it.
- **Docker:** daemon up (v29.8.1, Compose v5.5.1). Only `mysql:8.4` image pulled locally. No `trufi-website` network exists yet. A stale, unrelated container `api-mysql-1` (network `api_default`) is left untouched.
- **Git:** plugin repo `github.com:rovox/route-pages-wordpress.git` on branch `main`; untracked: `AGENTS.md`, `trufi-website-modules/`. The modules folder is a separate clone of `trufi-association/trufi-website-modules`.

## Phase 0 — Open in VS Code

```bash
code /home/robvox/projects/route-pages-wordpress
```

Fallback: `code-insiders` / `codium` if `code` is not on PATH.

## Phase 1 — Docker infra: fix + modernize (`trufi-website-modules`, stays local — no commit there)

### File edits

1. `wordpress/run.env` — fix the typo and set values:

   ```env
   MYSQL_HOST="mysql"
   WP_DBUSER="wordpress"      # previously misspelled as WP_DBUSRER
   WP_DBPASSWORD="changeme"
   WP_DBNAME="wordpress"
   ```

2. `wordpress/docker-compose.yml` — expose a port, join the shared network, live-mount the plugin:

   ```yaml
       ports:
       - 8081:80                          # WP reachable at http://localhost:8081 (8080 is phpMyAdmin)
       volumes:
       - ./data:/var/www/html
       - ../..:/var/www/html/wp-content/plugins/trufi-route-pages   # live-mount of the plugin repo
   networks:
     default:
       name: trufi-website                # was `$projectname` (undefined) → WP could not reach MySQL
   ```

3. Image bumps (approved):
   - `mysql/docker-compose.yml`: `mysql:8.0.29-debian` → `mysql:8.4` (LTS; already used elsewhere on this machine).
   - `php8/docker-compose.yml`: `php:8.0-fpm` (EOL) → `php:8.4-fpm` (plugin requires PHP >= 8.1).
   - Keep `wordpress:latest` and `phpmyadmin:latest`.

### Bring-up commands (each from its module directory)

```bash
# 1. database
docker compose --env-file run.env up -d          # in  mysql/
# 2. adminer (its compose reads ${MYSQL_HOST} but has no run.env)
MYSQL_HOST=mysql docker compose up -d            # in  phpmyadmin/
# 3. wordpress (builds ./src Dockerfile; entrypoint auto-creates DB + user "wordpress")
docker compose --env-file run.env up -d --build  # in  wordpress/
```

## Phase 2 — WordPress install & config (browser, http://localhost:8081)

1. Run the install wizard.
2. Set Permalinks → **Post name** (required for `/wp-sitemap-*.xml`).
3. Activate the **Trufi Route Pages** plugin (already visible via the live mount).
4. Create a top-level page **Routes** (must NOT be a child page — see slug gotcha).
5. **Settings → Trufi Routes**:
   - GraphQL URL: `https://otp281.trufi.app/otp/routers/default/index/graphql`
   - Base Page for Maps: page `Routes`
   - Short cache TTL while developing (e.g. 1 hour). Saving flushes rewrite rules and clears transients.

## Phase 3 — Verification

```bash
curl -s http://localhost:8081/wp-sitemap-routes-1.xml          # expect <urlset> with /routes/<slug>/<code> entries
curl -s http://localhost:8081/routes/bus-colomi/cochabamba:141:0:01   # route page: title + `const data =` JSON (pattern geometry)
```

- phpMyAdmin at `http://localhost:8080` (user `wordpress`) to inspect `trufi_route_data_*` and `trufi_routes_sitemap_xml`.
- Any plugin bug found → fix and re-test (live mount reflects edits immediately).

## Phase 4 — Documentation

`DEVELOPMENT.md` (English, repo root): overview · architecture (no-autoloader bootstrap, virtual posts, sitemap provider, template tokens, caching/settings, admin) · development workflow (no build/test/lint tooling, conventions, adding code) · local testing with Docker (this plan) · where to find information.

## Phase 5 — Commit for GitHub (plugin repo only)

- Stage **only**: `AGENTS.md`, `PLAN.md`, `DEVELOPMENT.md` (plus any plugin fix files from Phase 3).
- Never `git add trufi-website-modules/` (nested repo — stays untracked).
- Message in the repo's imperative style (existing commits like "Update website link in README").
- Push to `origin/main`.

## Out of scope / risks

- No local OTP server, no CI, no commit/push to `trufi-website-modules`.
- `wordpress:latest` is newer than the plugin's `Tested up to: 6.5.4` — expected to work, watch for surprises.
- `otp281.trufi.app` is a public dev server — endpoint may change or be rate-limited.
- The WordPress install wizard requires manual browser input; values to type are listed in Phase 2.
