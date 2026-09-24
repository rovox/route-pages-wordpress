/**
 * Draws a route (pattern) on a Leaflet map: the polyline, small arrow
 * markers showing direction of travel along it, start/end markers, and one
 * marker per stop (labelled with its street name via a tooltip) - shared by
 * the index page's on-demand preview and the route detail page's main map.
 */
window.Trufi = window.Trufi || {};

Trufi.routeDraw = {
    bearingDeg: function (a, b) {
        const dLon = (b[1] - a[1]) * Math.PI / 180;
        const lat1 = a[0] * Math.PI / 180;
        const lat2 = b[0] * Math.PI / 180;
        const y = Math.sin(dLon) * Math.cos(lat2);
        const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLon);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    },

    /** Splits a "{headline}: {from} → {to}" longName into its parts. */
    splitLongName: function (longName) {
        const base = (longName || '').split(':').slice(1).join(':').trim() || (longName || '');
        const parts = base.split('→').map(function (s) { return s.trim(); });
        return { headline: (longName || '').split(':')[0].trim(), from: parts[0] || '', to: parts[1] || '' };
    },

    /** Solid red pin marking the route's destination (end of the geometry). */
    pinMarker: function (at, color) {
        return L.marker(at, {
            icon: L.divIcon({
                className: 'trufi-pin-wrap',
                html: Trufi.icons.pinMarkerSvg(color),
                iconSize: [26, 34],
                iconAnchor: [13, 34],
            }),
            keyboard: false,
        });
    },

    arrowMarker: function (lineColor, at, angle) {
        return L.marker(at, {
            icon: L.divIcon({
                className: 'trufi-arrow-wrap',
                html: '<div class="trufi-arrow" style="transform:rotate(' + angle.toFixed(1) + 'deg);color:' + lineColor + ';">➤</div>',
                iconSize: [0, 0],
            }),
            interactive: false,
            keyboard: false,
        });
    },

    drawDirectionArrows: function (layerGroup, coords, lineColor) {
        const count = Math.max(3, Math.min(8, Math.floor(coords.length / 60)));
        const spacing = coords.length / (count + 1);
        let acc = 0;
        for (let i = 0; i < coords.length - 1; i++) {
            const a = coords[i];
            const b = coords[i + 1];
            const seg = Math.hypot(b[0] - a[0], b[1] - a[1]);
            if (seg <= 0) continue;
            const angle = Trufi.routeDraw.bearingDeg(a, b);
            let p = acc;
            while (p + spacing <= acc + seg) {
                const f = (p - acc) / seg;
                Trufi.routeDraw.arrowMarker(lineColor, [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f], angle).addTo(layerGroup);
                p += spacing;
            }
            acc += seg;
        }
    },

    /**
     * Origin: a small solid green dot. Destination: a red map-pin icon
     * (matches the "start = green point, end = red pin" convention used
     * throughout the UI - see the stops list dots in trufi-shared.css).
     */
    drawEndpoints: function (layerGroup, coords, labels) {
        if (!coords || coords.length === 0) return;
        const start = L.circleMarker(coords[0], { radius: 7, color: '#fff', weight: 2, fillColor: '#2e7d32', fillOpacity: 1 }).addTo(layerGroup);
        const end = Trufi.routeDraw.pinMarker(coords[coords.length - 1], '#c62828').addTo(layerGroup);
        if (labels && labels.from) start.bindTooltip(labels.from, { sticky: true, direction: 'right', offset: [8, 0] });
        if (labels && labels.to) end.bindTooltip(labels.to, { sticky: true, direction: 'top', offset: [0, -30] });
    },

    /**
     * One small marker per stop, labelled with its street name on hover/tap.
     * @returns {Object<number, L.CircleMarker>} markers keyed by stop index
     */
    drawStops: function (layerGroup, stops, onStopClick) {
        const markersByIndex = {};
        (stops || []).forEach(function (stop, index) {
            if (index === 0 || index === stops.length - 1) return; // endpoints are drawn bigger by drawEndpoints
            const marker = L.circleMarker([stop.lat, stop.lon], {
                radius: 4,
                color: '#fff',
                weight: 1.5,
                fillColor: '#495057',
                fillOpacity: 0.9,
            }).addTo(layerGroup);
            marker.bindTooltip(stop.name || 'Innominada', { direction: 'top', offset: [0, -4] });
            if (onStopClick) {
                marker.on('click', function () { onStopClick(index, stop); });
            }
            markersByIndex[index] = marker;
        });
        return markersByIndex;
    },

    /**
     * Orchestrator: clears layerGroup and draws the polyline + arrows +
     * endpoints + stop markers for one pattern.
     *
     * @param {L.Map} map
     * @param {L.LayerGroup} layerGroup
     * @param {{geometry: Array<{lat:number, lon:number}>, stops: Array, route: {longName: string}}} pattern
     * @param {string} lineColor
     * @param {{weight?: number, fitBounds?: boolean, onStopClick?: Function}} [options]
     * @returns {{coords: Array<[number, number]>, stopMarkers: Object<number, L.CircleMarker>}}
     */
    drawPattern: function (map, layerGroup, pattern, lineColor, options) {
        options = options || {};
        layerGroup.clearLayers();

        const coords = (pattern.geometry || []).map(function (pt) { return [pt.lat, pt.lon]; });
        if (coords.length < 2) return { coords: coords, stopMarkers: {} };

        const polyline = L.polyline(coords, { color: lineColor, weight: options.weight || 5, opacity: 0.9 }).addTo(layerGroup);

        Trufi.routeDraw.drawDirectionArrows(layerGroup, coords, lineColor);

        const labels = Trufi.routeDraw.splitLongName(pattern.route ? pattern.route.longName : '');
        Trufi.routeDraw.drawEndpoints(layerGroup, coords, labels);
        const stopMarkers = Trufi.routeDraw.drawStops(layerGroup, pattern.stops || [], options.onStopClick);

        if (options.fitBounds !== false) {
            map.fitBounds(polyline.getBounds());
        }

        return { coords: coords, stopMarkers: stopMarkers };
    },
};
