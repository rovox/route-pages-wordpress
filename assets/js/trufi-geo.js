/**
 * "Mi ubicación" button behaviour, shared by the index and route detail
 * maps (previously duplicated near-verbatim in both templates' inline
 * scripts). Tries the browser Geolocation API first, falls back to an
 * approximate IP-based lookup (ipwho.is) on failure/absence - see
 * AGENTS.md "Templates & rendering" for why.
 */
window.Trufi = window.Trufi || {};

Trufi.geo = {
    /**
     * @param {L.Map} map
     * @param {{button: HTMLElement|null, onHint: (msg: string) => void}} options
     */
    attach: function (map, options) {
        const geoBtn = options.button;
        const hint = options.onHint || function () {};
        const geoLayer = L.layerGroup().addTo(map);

        function pulseIcon() {
            return L.divIcon({
                className: 'trufi-geo-pulse-icon',
                iconSize: [14, 14],
                iconAnchor: [7, 7],
            });
        }

        function showUserLocation(latlng, accuracy) {
            geoLayer.clearLayers();
            L.marker(latlng, { icon: pulseIcon(), zIndexOffset: 1000 }).addTo(geoLayer);
            L.circle(latlng, { radius: accuracy || 60, color: '#1971c2', weight: 1, fillColor: '#1971c2', fillOpacity: 0.15 }).addTo(geoLayer);
            if (map.getZoom() < 15) map.setZoom(15);
            map.panTo(latlng);
            hint('Tu ubicación marcada en el mapa');
        }

        function showUserLocationIp(latlng) {
            geoLayer.clearLayers();
            L.marker(latlng, { icon: pulseIcon(), zIndexOffset: 1000 }).addTo(geoLayer);
            L.circle(latlng, { radius: 3000, color: '#6741d9', weight: 1, dashArray: '6 4', fillColor: '#6741d9', fillOpacity: 0.1 }).addTo(geoLayer);
            if (map.getZoom() > 13) map.setZoom(13);
            map.panTo(latlng);
            hint('Ubicación aproximada por IP (según tu conexión)');
        }

        function locateByIp() {
            fetch('https://ipwho.is/')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.success || !d.latitude || !d.longitude) throw new Error('lookup failed');
                    if (geoBtn) geoBtn.classList.remove('is-locating');
                    showUserLocationIp([d.latitude, d.longitude]);
                })
                .catch(function () {
                    if (geoBtn) geoBtn.classList.remove('is-locating');
                    hint('No se pudo obtener tu ubicación.');
                });
        }

        function locateUser() {
            if (geoBtn) geoBtn.classList.add('is-locating');
            if (!('geolocation' in navigator)) {
                locateByIp();
                return;
            }
            map.locate({ setView: false, watch: false, enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
        }

        if (geoBtn) {
            geoBtn.addEventListener('click', locateUser);
        }
        map.on('locationfound', function (e) {
            if (geoBtn) geoBtn.classList.remove('is-locating');
            showUserLocation(e.latlng, e.accuracy);
        });
        map.on('locationerror', function () {
            if (geoBtn) geoBtn.classList.remove('is-locating');
            hint('GPS no disponible, ubicando por IP...');
            locateByIp();
        });

        return { locateUser: locateUser };
    },
};
