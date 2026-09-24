/**
 * Bootstraps the route detail page: draws the route + its stops on the map
 * from `trufiRouteConfig.pattern` (localized by enqueue-scripts.php,
 * already includes geometry and stops - no fetch needed here), wires the
 * "Paradas" list <-> map marker interaction, geolocation, and the share
 * button.
 */
(function () {
    const config = window.trufiRouteConfig;
    if (!config || !config.pattern) return;

    const pattern = config.pattern;

    const hintEl = document.getElementById('trufi-route-map-hint');
    const geoBtn = document.getElementById('trufi-geo-btn');
    const stopsListEl = document.getElementById('trufi-route-stops');
    const shareBtn = document.getElementById('trufi-route-share');

    const map = L.map('trufi-route-map', { zoomControl: false }).setView([0, 0], 13);
    L.control.zoom({ position: 'bottomright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a> and <a href="https://trufi-association.org">Trufi Association</a> contributors',
    }).addTo(map);

    const overlays = L.layerGroup().addTo(map);
    let activeStopEl = null;

    function setActiveStop(index) {
        if (activeStopEl) activeStopEl.classList.remove('is-active');
        const el = stopsListEl.querySelector('[data-stop-index="' + index + '"]');
        if (el) {
            el.classList.add('is-active');
            el.scrollIntoView({ block: 'nearest' });
        }
        activeStopEl = el;
    }

    const drawn = Trufi.routeDraw.drawPattern(map, overlays, pattern, config.lineColor, {
        weight: config.lineWeight || 5,
        fitBounds: true,
        onStopClick: function (index) { setActiveStop(index); },
    });

    if (stopsListEl) {
        stopsListEl.querySelectorAll('.trufi-stop').forEach(function (li) {
            li.addEventListener('click', function () {
                const lat = parseFloat(li.getAttribute('data-lat'));
                const lon = parseFloat(li.getAttribute('data-lon'));
                if (isNaN(lat) || isNaN(lon)) return;
                map.setView([lat, lon], Math.max(map.getZoom(), 16));
                const index = parseInt(li.getAttribute('data-stop-index'), 10);
                const marker = drawn.stopMarkers[index];
                if (marker) marker.openTooltip();
                setActiveStop(index);
            });
        });
    }

    if (hintEl) {
        Trufi.geo.attach(map, {
            button: geoBtn,
            onHint: function (msg) {
                hintEl.textContent = msg;
                hintEl.style.display = 'block';
                clearTimeout(hintEl._t);
                hintEl._t = setTimeout(function () { hintEl.style.display = 'none'; }, 2500);
            },
        });
    }

    if (shareBtn) {
        shareBtn.addEventListener('click', function () {
            Trufi.share.copyOrShare(window.location.href, document.title, shareBtn);
        });
    }
})();
