/**
 * Pure filtering logic for the routes index sidebar - kept separate from
 * trufi-index.js (which owns rendering/DOM) so the matching rules can be
 * read/tested on their own (see tests/search.test.js).
 */
(function (root) {
    const Trufi = root.Trufi = root.Trufi || {};

    Trufi.search = {
        /** Lowercase + strip accents, so "taquina" matches "Taquiña". */
        normalize: function (text) {
            return (text || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
        },

        /**
         * Splits a leading vehicle word off a normalized query. `titles` is
         * the list of title prefixes it stands for (null = no restriction,
         * e.g. "bus", "colectivo"), `rest` the remaining line-name text.
         */
        parseVehicle: function (query) {
            const aliases = [
                [/^taxi\s+trufis?\b\s*/, ['trufi']],
                [/^trufis?\b\s*/, ['trufi']],
                [/^micro\s*buses?\b\s*/, ['microbus']],
                [/^micros?\b\s*/, ['microbus', 'micro']],
                [/^mini\s*buses?\b\s*/, ['minibus']],
                [/^minis?\b\s*/, ['minibus']],
                [/^(tren|tranvia)\b\s*/, ['tren', 'tranvia', 'linea', 'mi tren']],
                [/^colectivos?\b\s*/, null],
                [/^bus(es)?\b\s*/, null],
            ];
            for (let i = 0; i < aliases.length; i++) {
                const m = query.match(aliases[i][0]);
                if (m) return { titles: aliases[i][1], rest: query.slice(m[0].length).trim() };
            }
            return { titles: null, rest: query };
        },

        /**
         * The search box finds LINES by name, e.g. "M" or "103" - it does not
         * match sindicato names (typing "M" must not return every "Sindicato
         * mixto ..."), and never needs a vehicle word like "bus".
         *
         * 1. Line-name matches (shortName or display title): an exact match
         *    wins outright, otherwise names that start with the query, and
         *    only then names that merely contain it as a whole word.
         * 2. Only when no line is named like the query does it fall back to
         *    origin/destination places, so "Quillacollo" still finds lines.
         *
         * Transport mode and sindicato (a slug, from the dropdowns) are
         * separate filters, combined with AND.
         *
         * @param {Array} lines Array of {shortName, title, mode, sindicato: {name, slug}|null, patterns: [{code, longName, from, to}]}
         * @param {{query?: string, mode?: string, sindicato?: string}} filters
         * @returns {Array} filtered lines
         */
        filterLines: function (lines, filters) {
            const normalize = Trufi.search.normalize;
            const mode = filters.mode || '';
            const sindicato = filters.sindicato || '';

            // Local vehicle words ("micro k", "trufi 106", "taxi trufi 133"):
            // the vehicle word narrows the lines to titles of that kind and
            // the rest of the text is matched as the line name.
            const parsed = Trufi.search.parseVehicle(normalize(filters.query));
            const query = parsed.rest;

            const scoped = lines.filter(function (line) {
                if (mode && line.mode !== mode) return false;
                if (sindicato && (!line.sindicato || line.sindicato.slug !== sindicato)) return false;
                if (parsed.titles) {
                    const title = normalize(line.title);
                    if (!parsed.titles.some(function (prefix) { return title.startsWith(prefix); })) return false;
                }
                return true;
            });
            if (!query) return scoped;

            // Lower rank = better match. 0 exact, 1 prefix, 2 whole word.
            function nameRank(line) {
                const names = [line.shortName, line.title].map(normalize).filter(Boolean);
                let best = null;
                names.forEach(function (name) {
                    let rank = null;
                    if (name === query) {
                        rank = 0;
                    } else if (name.startsWith(query)) {
                        rank = 1;
                    } else if (name.split(/[^a-z0-9ñ]+/).some(function (word) { return word === query || word.startsWith(query); })) {
                        rank = 2;
                    }
                    if (rank !== null && (best === null || rank < best)) best = rank;
                });
                return best;
            }

            const ranked = scoped
                .map(function (line, index) { return { line: line, rank: nameRank(line), index: index }; })
                .filter(function (item) { return item.rank !== null; });

            if (ranked.length) {
                const top = Math.min.apply(null, ranked.map(function (item) { return item.rank; }));
                // An exact line name ("M") hides the weaker partial matches ("Metropolitano").
                const keep = top === 0 ? ranked.filter(function (item) { return item.rank === 0; }) : ranked;
                return keep
                    .sort(function (a, b) { return a.rank - b.rank || a.index - b.index; })
                    .map(function (item) { return item.line; });
            }

            return scoped.filter(function (line) {
                return line.patterns.some(function (p) {
                    return [p.from, p.to].some(function (text) { return normalize(text).includes(query); });
                });
            });
        },
    };

    if (typeof module !== 'undefined' && module.exports) module.exports = Trufi.search;
})(typeof window !== 'undefined' ? window : globalThis);
