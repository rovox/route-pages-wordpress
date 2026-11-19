/**
 * Bootstraps the routes index page: wires the search/filter UI
 * (trufi-search.js), the overview map + geolocation (trufi-geo.js) and the
 * "Compartir" buttons (trufi-share.js). Each route row is a plain link to
 * its detail page.
 *
 * The full list (grouped by operator, "sindicato") is server-rendered by
 * trufi_render_routes_list_html() (template-redirect.php) so crawlers see
 * every route link without JS; it's only rebuilt here from
 * `trufiIndexConfig.lines` when the visitor filters. renderGroup() and
 * renderLine() below must produce the same markup as that PHP function.
 *
 * The filters are mirrored in the URL (`?sindicato=<slug>&modo=<mode>&q=<text>`)
 * via history.replaceState, so a shared sindicato link opens pre-filtered
 * and the browser's Back button from a route page returns to the same
 * filtered list.
 */
(function () {
    const config = window.trufiIndexConfig;
    if (!config) return;

    const lines = config.lines || [];
    const modeLabels = { BUS: 'Bus', TRAM: 'Tranvía', GONDOLA: 'Teleférico', RAIL: 'Tren' };

    const listEl = document.getElementById('trufi-index-list');
    const searchEl = document.getElementById('trufi-index-search');
    const modeEl = document.getElementById('trufi-index-mode-filter');
    const sindicatoEl = document.getElementById('trufi-index-sindicato-filter');
    const clearEl = document.getElementById('trufi-index-clear');
    const hintEl = document.getElementById('trufi-index-map-hint');
    const statusEl = document.getElementById('trufi-index-status');
    const geoBtn = document.getElementById('trufi-geo-btn');

    const map = L.map('trufi-index-map', { zoomControl: false }).setView([config.mapCenter.lat, config.mapCenter.lng], config.mapZoom);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> and <a href="https://trufi-association.org">Trufi Association</a> contributors',
    }).addTo(map);

    let hintTimer = null;

    // The hint is a polite live region: it is emptied (and so hidden by
    // `:empty` in CSS) when it expires, instead of leaving a blank dark pill.
    function hint(msg, hideAfterMs) {
        hintEl.textContent = msg;
        clearTimeout(hintTimer);
        if (hideAfterMs) {
            hintTimer = setTimeout(function () { hintEl.textContent = ''; }, hideAfterMs);
        }
    }

    hint(hintEl.textContent, 4000);

    Trufi.geo.attach(map, {
        button: geoBtn,
        onHint: function (msg) { hint(msg, 2500); },
    });

    // Populate the mode filter from whatever modes are actually present.
    const modes = [...new Set(lines.map(function (l) { return l.mode; }))];
    modes.forEach(function (mode) {
        const opt = document.createElement('option');
        opt.value = mode;
        opt.textContent = modeLabels[mode] || mode;
        modeEl.appendChild(opt);
    });

    // Sindicatos present in the data, with each one's total route count
    // (shown on its group header regardless of the active filters).
    const sindicatos = {};
    lines.forEach(function (line) {
        if (!line.sindicato) return;
        const s = sindicatos[line.sindicato.slug] || (sindicatos[line.sindicato.slug] = { name: line.sindicato.name, slug: line.sindicato.slug, total: 0 });
        s.total += line.patterns.length;
    });
    const hasSindicatos = Object.keys(sindicatos).length > 0;
    const otherLinesTotal = lines.reduce(function (sum, line) { return sum + (line.sindicato ? 0 : line.patterns.length); }, 0);

    // Same order as the server-rendered list (accent/case-insensitive).
    function byName(a, b) {
        const x = Trufi.search.normalize(a.name);
        const y = Trufi.search.normalize(b.name);
        return x < y ? -1 : (x > y ? 1 : 0);
    }

    Object.values(sindicatos)
        .sort(byName)
        .forEach(function (s) {
            const opt = document.createElement('option');
            opt.value = s.slug;
            opt.textContent = s.name;
            sindicatoEl.appendChild(opt);
        });

    const params = new URLSearchParams(window.location.search);
    if (sindicatos[params.get('sindicato')]) sindicatoEl.value = params.get('sindicato');
    if (modes.indexOf(params.get('modo')) !== -1) modeEl.value = params.get('modo');
    if (params.get('q')) searchEl.value = params.get('q');

    function sindicatoUrl(slug) {
        const url = new URL(config.indexUrl || window.location.href, window.location.href);
        url.search = '';
        if (slug) url.searchParams.set('sindicato', slug);
        return url.toString();
    }

    function syncUrl() {
        const url = new URL(window.location.href);
        [['sindicato', sindicatoEl.value], ['modo', modeEl.value], ['q', searchEl.value.trim()]].forEach(function (pair) {
            if (pair[1]) {
                url.searchParams.set(pair[0], pair[1]);
            } else {
                url.searchParams.delete(pair[0]);
            }
        });
        window.history.replaceState(null, '', url.toString());
        try {
            // So a route page's back link can return to this filtered view.
            const opt = sindicatoEl.options[sindicatoEl.selectedIndex];
            window.sessionStorage.setItem('trufiIndexView', JSON.stringify({
                search: url.search,
                label: sindicatoEl.value && opt ? opt.textContent : '',
            }));
        } catch (e) { /* storage unavailable: back link just goes to the index */ }
    }

    function update() {
        syncUrl();
        render();
    }

    function routesCountLabel(n) {
        return n + (n === 1 ? ' ruta trazada' : ' rutas trazadas');
    }

    function patternLabel(pattern) {
        if (pattern.label) return pattern.label;
        if (pattern.from && pattern.to) return pattern.from + ' hacia ' + pattern.to;
        if (pattern.to) return 'Hacia ' + pattern.to;
        return pattern.longName;
    }

    // Clicks are handled once, by delegation on the list (see below), so the
    // same data-share-* attributes work for server- and JS-rendered rows.
    function shareButton(url, title, label) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'trufi-share-btn';
        btn.textContent = 'Compartir';
        btn.setAttribute('data-share-url', url);
        btn.setAttribute('data-share-title', title);
        btn.setAttribute('aria-label', label);
        return btn;
    }

    // Heading level of a line title: h4 under a sindicato's h3, h3 in the flat list.
    function renderLine(line, tag) {
        const lineEl = document.createElement('div');
        lineEl.className = 'trufi-line';

        const header = document.createElement('div');
        header.className = 'trufi-line-header';

        const badge = document.createElement('span');
        badge.className = 'trufi-line-badge';
        badge.setAttribute('aria-hidden', 'true');
        badge.innerHTML = '<span class="trufi-icon" data-icon="bus"></span>';
        badge.appendChild(document.createTextNode(line.shortName));
        header.appendChild(badge);

        const title = document.createElement(tag);
        title.className = 'trufi-line-title';
        title.textContent = line.title;
        header.appendChild(title);

        lineEl.appendChild(header);

        const list = document.createElement('ul');
        list.className = 'trufi-pattern-list';
        line.patterns.forEach(function (pattern) {
            const label = patternLabel(pattern);

            const row = document.createElement('li');
            row.className = 'trufi-pattern';

            const link = document.createElement('a');
            link.className = 'trufi-pattern-link';
            link.href = pattern.url;
            link.innerHTML = '<span class="trufi-icon" data-icon="route" aria-hidden="true"></span><span class="trufi-pattern-label"></span>';
            link.querySelector('.trufi-pattern-label').textContent = label;
            row.appendChild(link);

            row.appendChild(shareButton(pattern.url, line.title + ' · ' + label, 'Compartir la ruta ' + line.title + ', ' + label));
            list.appendChild(row);
        });
        lineEl.appendChild(list);

        return lineEl;
    }

    function renderGroup(name, total, slug, groupLines) {
        const groupEl = document.createElement('section');
        groupEl.className = 'trufi-group';
        groupEl.setAttribute('aria-label', name);

        const header = document.createElement('div');
        header.className = 'trufi-group-header';

        const info = document.createElement('div');
        info.className = 'trufi-group-info';
        const nameEl = document.createElement('h3');
        nameEl.className = 'trufi-group-name';
        nameEl.textContent = name;
        const countEl = document.createElement('span');
        countEl.className = 'trufi-group-count';
        countEl.textContent = routesCountLabel(total);
        info.appendChild(nameEl);
        info.appendChild(countEl);
        header.appendChild(info);

        if (slug) {
            header.appendChild(shareButton(sindicatoUrl(slug), name, 'Compartir las rutas de ' + name));
        }

        groupEl.appendChild(header);
        groupLines.forEach(function (line) { groupEl.appendChild(renderLine(line, 'h4')); });
        return groupEl;
    }

    function render() {
        const filtered = Trufi.search.filterLines(lines, {
            query: searchEl.value,
            mode: modeEl.value,
            sindicato: sindicatoEl.value,
        });
        listEl.innerHTML = '';

        if (filtered.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'trufi-index-empty';
            empty.textContent = 'No se encontraron líneas.';
            listEl.appendChild(empty);
            announce(0);
            return;
        }

        if (!hasSindicatos) {
            // No operator data (sindicatos source disabled/unreachable): plain line list.
            filtered.forEach(function (line) { listEl.appendChild(renderLine(line, 'h3')); });
        } else {
            const groups = {};
            const otherLines = [];
            filtered.forEach(function (line) {
                if (!line.sindicato) {
                    otherLines.push(line);
                    return;
                }
                (groups[line.sindicato.slug] = groups[line.sindicato.slug] || []).push(line);
            });

            Object.keys(groups)
                .sort(function (a, b) { return byName(sindicatos[a], sindicatos[b]); })
                .forEach(function (slug) {
                    const s = sindicatos[slug];
                    listEl.appendChild(renderGroup(s.name, s.total, s.slug, groups[slug]));
                });

            if (otherLines.length) {
                listEl.appendChild(renderGroup('Otras líneas', otherLinesTotal, null, otherLines));
            }
        }

        Trufi.icons.mount(listEl);
        announce(filtered.length);
    }

    // Result count for screen readers (polite live region) and sighted users.
    // Only after the visitor filters: the initial page load stays quiet.
    function announce(count) {
        if (!statusEl) return;
        const active = searchEl.value.trim() || modeEl.value || sindicatoEl.value;
        statusEl.textContent = active
            ? (count === 0 ? 'No se encontraron líneas.' : count + (count === 1 ? ' línea encontrada.' : ' líneas encontradas.'))
            : '';
    }

    listEl.addEventListener('click', function (e) {
        const btn = e.target.closest('.trufi-share-btn');
        if (!btn) return;
        e.preventDefault();
        Trufi.share.copyOrShare(btn.getAttribute('data-share-url'), btn.getAttribute('data-share-title'));
    });

    searchEl.addEventListener('input', update);
    modeEl.addEventListener('change', update);
    sindicatoEl.addEventListener('change', update);
    clearEl.addEventListener('click', function () {
        searchEl.value = '';
        modeEl.value = '';
        sindicatoEl.value = '';
        update();
    });

    // The server already rendered the unfiltered list; only rebuild it when
    // the page was opened with filters (shared sindicato link, Back button).
    if (searchEl.value || modeEl.value || sindicatoEl.value) {
        render();
    }
    // Remember the view even if the visitor never changes a filter (opened
    // from a shared ?sindicato= link), so a route page can link back to it.
    syncUrl();
})();
