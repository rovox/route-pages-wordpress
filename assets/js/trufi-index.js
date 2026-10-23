/**
 * Bootstraps the routes index page: builds the sidebar from
 * `trufiIndexConfig.lines` (localized by enqueue-scripts.php) grouped by
 * operator ("sindicato"), wires the search/filter UI (trufi-search.js), the
 * overview map + geolocation (trufi-geo.js) and the "Compartir" buttons
 * (trufi-share.js). Each route row is a plain link to its detail page.
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
    const geoBtn = document.getElementById('trufi-geo-btn');

    const map = L.map('trufi-index-map', { zoomControl: false }).setView([config.mapCenter.lat, config.mapCenter.lng], config.mapZoom);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> and <a href="https://trufi-association.org">Trufi Association</a> contributors',
    }).addTo(map);

    let hintTimer = null;

    function hint(msg, hideAfterMs) {
        hintEl.textContent = msg;
        hintEl.style.display = 'block';
        clearTimeout(hintTimer);
        if (hideAfterMs) {
            hintTimer = setTimeout(function () { hintEl.style.display = 'none'; }, hideAfterMs);
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

    Object.values(sindicatos)
        .sort(function (a, b) { return a.name.localeCompare(b.name, 'es'); })
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
    }

    function update() {
        syncUrl();
        render();
    }

    function routesCountLabel(n) {
        return n + (n === 1 ? ' ruta trazada' : ' rutas trazadas');
    }

    function patternLabel(pattern) {
        if (pattern.from && pattern.to) return pattern.from + ' → ' + pattern.to;
        if (pattern.to) return 'Hacia ' + pattern.to;
        return pattern.longName;
    }

    function shareButton(url, title, label) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'trufi-share-btn';
        btn.textContent = 'Compartir';
        btn.setAttribute('aria-label', label);
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            Trufi.share.copyOrShare(url, title);
        });
        return btn;
    }

    function renderLine(line) {
        const lineEl = document.createElement('div');
        lineEl.className = 'trufi-line';

        const header = document.createElement('div');
        header.className = 'trufi-line-header';

        const badge = document.createElement('span');
        badge.className = 'trufi-line-badge';
        badge.innerHTML = '<span class="trufi-icon" data-icon="bus"></span>';
        badge.appendChild(document.createTextNode(line.shortName));
        header.appendChild(badge);

        const title = document.createElement('span');
        title.className = 'trufi-line-title';
        title.textContent = (modeLabels[line.mode] || line.mode) + ' ' + line.shortName;
        header.appendChild(title);

        lineEl.appendChild(header);

        line.patterns.forEach(function (pattern) {
            const label = patternLabel(pattern);

            const row = document.createElement('div');
            row.className = 'trufi-pattern';

            const link = document.createElement('a');
            link.className = 'trufi-pattern-link';
            link.href = pattern.url;
            link.innerHTML = '<span class="trufi-icon" data-icon="route" aria-hidden="true"></span><span class="trufi-pattern-label"></span>';
            link.querySelector('.trufi-pattern-label').textContent = label;
            row.appendChild(link);

            row.appendChild(shareButton(pattern.url, pattern.longName + ' · ' + label, 'Compartir la ruta ' + pattern.longName + ', ' + label));
            lineEl.appendChild(row);
        });

        return lineEl;
    }

    function renderGroup(name, total, slug, groupLines) {
        const groupEl = document.createElement('section');
        groupEl.className = 'trufi-group';

        const header = document.createElement('div');
        header.className = 'trufi-group-header';

        const info = document.createElement('div');
        info.className = 'trufi-group-info';
        const nameEl = document.createElement('span');
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
        groupLines.forEach(function (line) { groupEl.appendChild(renderLine(line)); });
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
            const empty = document.createElement('div');
            empty.className = 'trufi-index-empty';
            empty.textContent = 'No se encontraron líneas.';
            listEl.appendChild(empty);
            return;
        }

        if (!hasSindicatos) {
            // No operator data (sindicatos source disabled/unreachable): plain line list.
            filtered.forEach(function (line) { listEl.appendChild(renderLine(line)); });
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
                .sort(function (a, b) { return sindicatos[a].name.localeCompare(sindicatos[b].name, 'es'); })
                .forEach(function (slug) {
                    const s = sindicatos[slug];
                    listEl.appendChild(renderGroup(s.name, s.total, s.slug, groups[slug]));
                });

            if (otherLines.length) {
                listEl.appendChild(renderGroup('Otras líneas', otherLinesTotal, null, otherLines));
            }
        }

        Trufi.icons.mount(listEl);
    }

    searchEl.addEventListener('input', update);
    modeEl.addEventListener('change', update);
    sindicatoEl.addEventListener('change', update);
    clearEl.addEventListener('click', function () {
        searchEl.value = '';
        modeEl.value = '';
        sindicatoEl.value = '';
        update();
    });

    render();
})();
