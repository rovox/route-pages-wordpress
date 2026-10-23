/**
 * Pure filtering logic for the routes index sidebar - kept separate from
 * trufi-index.js (which owns rendering/DOM) so the matching rules can be
 * read/tested on their own.
 */
window.Trufi = window.Trufi || {};

Trufi.search = {
    /** Lowercase + strip accents, so "taquina" matches "Taquiña". */
    normalize: function (text) {
        return (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    },

    /**
     * Search text, transport mode and sindicato are combined with AND.
     *
     * @param {Array} lines Array of {shortName, mode, sindicato: {name, slug}|null, patterns: [{code, longName, from, to}]}
     * @param {{query?: string, mode?: string, sindicato?: string}} filters `sindicato` is a slug
     * @returns {Array} filtered lines
     */
    filterLines: function (lines, filters) {
        const normalize = Trufi.search.normalize;
        const query = normalize(filters.query);
        const mode = filters.mode || '';
        const sindicato = filters.sindicato || '';

        return lines.filter(function (line) {
            if (mode && line.mode !== mode) return false;
            if (sindicato && (!line.sindicato || line.sindicato.slug !== sindicato)) return false;
            if (!query) return true;
            if (normalize(line.shortName).includes(query)) return true;
            if (line.sindicato && normalize(line.sindicato.name).includes(query)) return true;
            return line.patterns.some(function (p) {
                return [p.longName, p.from, p.to].some(function (text) { return normalize(text).includes(query); });
            });
        });
    },
};
