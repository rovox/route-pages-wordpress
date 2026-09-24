/**
 * Distance math shared by both pages. The route detail page's headline
 * stat is rendered server-side (see trufi_route_distance_km() in
 * functions.php) so it works without JS; this module exists for any
 * client-side distance display, e.g. a route preview from the index page.
 */
window.Trufi = window.Trufi || {};

Trufi.distance = {
    haversineKm: function (a, b) {
        const R = 6371;
        const dLat = (b[0] - a[0]) * Math.PI / 180;
        const dLon = (b[1] - a[1]) * Math.PI / 180;
        const lat1 = a[0] * Math.PI / 180;
        const lat2 = b[0] * Math.PI / 180;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    },

    totalKm: function (coords) {
        let total = 0;
        for (let i = 0; i < coords.length - 1; i++) {
            total += Trufi.distance.haversineKm(coords[i], coords[i + 1]);
        }
        return total;
    },

    format: function (km) {
        return km.toFixed(1).replace('.', ',') + ' km';
    },
};
