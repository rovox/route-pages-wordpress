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

    // Back link: return to the index with the filter the visitor came from.
    (function restoreBackLink() {
        const back = document.querySelector('.trufi-route-back');
        if (!back) return;
        try {
            let saved = JSON.parse(window.sessionStorage.getItem('trufiIndexView') || 'null');
            if (!saved || !saved.search) {
                // New tab / storage cleared: use the referring index URL, if any.
                const ref = document.referrer ? new URL(document.referrer) : null;
                const base = new URL(back.href, window.location.href);
                if (ref && ref.origin === base.origin && ref.pathname === base.pathname) {
                    saved = { search: ref.search, label: '' };
                }
            }
            if (!saved || !saved.search) return;
            back.href = back.href.split('?')[0].split('#')[0] + saved.search;
            if (saved.label) {
                const text = document.createTextNode(' Volver a ' + saved.label);
                const icon = back.querySelector('[data-icon]');
                back.textContent = '';
                if (icon) back.appendChild(icon);
                back.appendChild(text);
            } else if (saved.search) {
                back.setAttribute('title', 'Volver a la lista con tus filtros');
            }
        } catch (e) { /* keep the plain link */ }
    })();

    const hintEl = document.getElementById('trufi-route-map-hint');
    const geoBtn = document.getElementById('trufi-geo-btn');
    const stopsListEl = document.getElementById('trufi-route-stops');
    const shareBtn = document.getElementById('trufi-route-share');
    const scrollWrapEl = document.getElementById('trufi-route-stops-scroll');
    const scrollHintEl = document.getElementById('trufi-route-scroll-hint');

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
        labels: config.labels,
        onStopClick: function (index) { setActiveStop(index); },
    });

    if (stopsListEl) {
        stopsListEl.querySelectorAll('.trufi-stop').forEach(function (stopEl) {
            stopEl.addEventListener('click', function () {
                const lat = parseFloat(stopEl.getAttribute('data-lat'));
                const lon = parseFloat(stopEl.getAttribute('data-lon'));
                if (isNaN(lat) || isNaN(lon)) return;
                map.setView([lat, lon], Math.max(map.getZoom(), 16));
                const index = parseInt(stopEl.getAttribute('data-stop-index'), 10);
                const marker = drawn.stopMarkers[index];
                if (marker) marker.openTooltip();
                setActiveStop(index);
            });
        });
    }

    // Scroll affordance for the stops list: a bottom fade + "desplaza" hint
    // while more stops are hidden below, cleared once the end is reached.
    // (Also announced by the list being a focusable, labelled scroll region.)
    function updateScrollHint() {
        if (!scrollWrapEl || !stopsListEl) return;
        const more = stopsListEl.scrollHeight - stopsListEl.scrollTop - stopsListEl.clientHeight > 8;
        scrollWrapEl.classList.toggle('has-more', more);
        if (scrollHintEl) scrollHintEl.hidden = !more;
    }
    if (stopsListEl) {
        stopsListEl.addEventListener('scroll', updateScrollHint, { passive: true });
        window.addEventListener('resize', updateScrollHint);
        updateScrollHint();
    }

    // The hint is a polite live region emptied (hidden via `:empty`) after a
    // moment, so no blank dark pill is left over the map.
    if (hintEl) {
        Trufi.geo.attach(map, {
            button: geoBtn,
            onHint: function (msg) {
                hintEl.textContent = msg;
                clearTimeout(hintEl._t);
                hintEl._t = setTimeout(function () { hintEl.textContent = ''; }, 2500);
            },
        });
    }

    if (shareBtn) {
        shareBtn.addEventListener('click', function () {
            Trufi.share.copyOrShare(window.location.href, document.title);
        });
    }
})();
