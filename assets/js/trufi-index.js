/**
 * Bootstraps the routes index page: builds the sidebar from
 * `trufiIndexConfig.lines` (localized by enqueue-scripts.php), wires the
 * search/filter UI (trufi-search.js) and the map (trufi-route-draw.js +
 * trufi-geo.js), and fetches a pattern's full geometry/stops on demand
 * (trufi-api.js) when it's selected.
 */
(function () {
    const config = window.trufiIndexConfig;
    if (!config) return;

    const lines = config.lines || [];
    const modeLabels = { BUS: 'Bus', TRAM: 'Tranvía', GONDOLA: 'Teleférico', RAIL: 'Tren' };

    const listEl = document.getElementById('trufi-index-list');
    const searchEl = document.getElementById('trufi-index-search');
    const modeEl = document.getElementById('trufi-index-mode-filter');
    const clearEl = document.getElementById('trufi-index-clear');
    const hintEl = document.getElementById('trufi-index-map-hint');
    const geoBtn = document.getElementById('trufi-geo-btn');

    const map = L.map('trufi-index-map', { zoomControl: false }).setView([config.mapCenter.lat, config.mapCenter.lng], config.mapZoom);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> and <a href="https://trufi-association.org">Trufi Association</a> contributors',
    }).addTo(map);

    const overlays = L.layerGroup().addTo(map);
    let activeRow = null;
    let hintTimer = null;

    function hint(msg, autoHide) {
        hintEl.textContent = msg;
        hintEl.style.display = 'block';
        clearTimeout(hintTimer);
        if (autoHide) {
            hintTimer = setTimeout(function () { hintEl.style.display = 'none'; }, 1200);
        }
    }

    Trufi.geo.attach(map, {
        button: geoBtn,
        onHint: function (msg) {
            hint(msg);
            clearTimeout(hintTimer);
            hintTimer = setTimeout(function () { hintEl.style.display = 'none'; }, 2500);
        },
    });

    // Populate the mode filter from whatever modes are actually present.
    const modes = [...new Set(lines.map(function (l) { return l.mode; }))];
    modes.forEach(function (mode) {
        const opt = document.createElement('option');
        opt.value = mode;
        opt.textContent = modeLabels[mode] || mode;
        modeEl.appendChild(opt);
    });

    function selectPattern(pattern, row) {
        if (activeRow) activeRow.classList.remove('is-active');
        row.classList.add('is-active');
        activeRow = row;
        hint('Cargando ruta...');

        Trufi.api.fetchPattern(config.restUrl, pattern.code)
            .then(function (data) {
                Trufi.routeDraw.drawPattern(map, overlays, data, config.lineColor, { fitBounds: true });
                const parts = Trufi.routeDraw.splitLongName(data.route ? data.route.longName : pattern.longName);
                hint(parts.to || parts.headline, true);
            })
            .catch(function () {
                hint('No se pudo cargar la ruta.');
            });
    }

    function render() {
        const filtered = Trufi.search.filterLines(lines, { query: searchEl.value, mode: modeEl.value });
        listEl.innerHTML = '';

        if (filtered.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'trufi-index-empty';
            empty.textContent = 'No se encontraron líneas.';
            listEl.appendChild(empty);
            return;
        }

        filtered.forEach(function (line) {
            const lineEl = document.createElement('div');
            lineEl.className = 'trufi-line';

            const header = document.createElement('div');
            header.className = 'trufi-line-header';

            const badge = document.createElement('span');
            badge.className = 'trufi-line-badge';
            badge.innerHTML = '<span class="trufi-icon" data-icon="bus"></span>' + line.shortName;
            header.appendChild(badge);

            const title = document.createElement('span');
            title.className = 'trufi-line-title';
            title.textContent = (modeLabels[line.mode] || line.mode) + ' ' + line.shortName;
            header.appendChild(title);

            lineEl.appendChild(header);

            line.patterns.forEach(function (pattern) {
                const row = document.createElement('div');
                row.className = 'trufi-pattern';
                const parts = Trufi.routeDraw.splitLongName(pattern.longName);
                row.innerHTML =
                    '<span class="trufi-icon" data-icon="route"></span>' +
                    '<span class="trufi-pattern-label"></span>' +
                    '<button type="button" class="trufi-icon-btn trufi-pattern-share" title="Compartir esta ruta">' +
                    '<span class="trufi-icon" data-icon="share"></span></button>';
                row.querySelector('.trufi-pattern-label').textContent = parts.to ? (parts.from + ' → ' + parts.to) : pattern.longName;
                row.addEventListener('click', function () { selectPattern(pattern, row); });

                const shareBtn = row.querySelector('.trufi-pattern-share');
                shareBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    Trufi.share.copyOrShare(pattern.url, pattern.longName, shareBtn);
                });

                lineEl.appendChild(row);
            });

            listEl.appendChild(lineEl);
        });

        Trufi.icons.mount(listEl);
    }

    searchEl.addEventListener('input', render);
    modeEl.addEventListener('change', render);
    clearEl.addEventListener('click', function () {
        searchEl.value = '';
        modeEl.value = '';
        render();
    });

    render();
})();
