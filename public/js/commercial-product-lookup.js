(function (root) {
    'use strict';
    const normalize = value => String(value || '').normalize('NFKC').replace(/[\s\u00a0]+/gu, ' ').trim().toLocaleLowerCase();
    let inFlightController = null;
    const queryCache = new Map();

    root.CommercialProductLookup = {
        normalize,
        async search(term, products, kind, mode) {
            const party = document.querySelector(kind === 'sale' ? '[name="customer_id"]' : '[name="supplier_id"]');
            const selectedParty = party?.value || '';
            const trimmedTerm = String(term || '').trim();
            const query = normalize(trimmedTerm);

            // Abort any previous pending search request
            if (inFlightController) {
                inFlightController.abort();
            }
            inFlightController = new AbortController();
            const signal = inFlightController.signal;

            const modeFilter = typeof mode === 'function' ? mode : () => true;

            // If commercial lookup endpoint is enabled, use server results directly
            if (root.CommercialProductLookup.enabled !== false) {
                const cacheKey = kind + ':' + selectedParty + ':' + query;
                if (queryCache.has(cacheKey)) {
                    const cached = queryCache.get(cacheKey);
                    return cached.filter(modeFilter);
                }

                try {
                    const params = new URLSearchParams({q: trimmedTerm});
                    if (selectedParty) params.set('party_id', selectedParty);

                    const response = await fetch('/commercial/' + kind + '/search/products?' + params, {
                        headers: {Accept: 'application/json'},
                        signal: signal
                    });

                    if (!response.ok) throw new Error('Product lookup failed.');
                    const resJson = await response.json();
                    const data = resJson?.data || [];

                    // Ignore if party changed in the meantime
                    if ((party?.value || '') !== selectedParty) return [];

                    // Map server data directly, ensuring standard fields
                    const serverMatches = data.map(product => ({
                        ...product,
                        match_rank: product.match_rank !== undefined ? product.match_rank
                            : (normalize(product.code) === query ? 0 : (normalize(product.name) === query ? 3 : 4))
                    })).sort((a, b) => a.match_rank - b.match_rank || String(a.code).localeCompare(String(b.code)));

                    // Cache up to 100 queries
                    if (queryCache.size > 100) {
                        const firstKey = queryCache.keys().next().value;
                        queryCache.delete(firstKey);
                    }
                    queryCache.set(cacheKey, serverMatches);

                    return serverMatches.filter(modeFilter).slice(0, 50);
                } catch (err) {
                    if (err.name === 'AbortError') {
                        return []; // Stale request aborted; ignore
                    }
                    // If network fails and local products array is available, fall back to local
                    if (!Array.isArray(products) || !products.length) {
                        throw err;
                    }
                }
            }

            // Local fallback (when offline or commercial endpoint explicitly disabled)
            if (!Array.isArray(products)) return [];
            return products.filter(product => modeFilter(product) && (normalize(product.code).includes(query) || normalize(product.name).includes(query)))
                .map(product => ({...product, match_rank: normalize(product.code) === query ? 0 : (normalize(product.name) === query ? 3 : 4)}))
                .sort((a, b) => a.match_rank - b.match_rank || String(a.code).localeCompare(String(b.code))).slice(0, 50);
        },
        exact(matches, term) {
            if (!Array.isArray(matches) || !matches.length) return null;
            const query = normalize(term);
            const rank = matches[0]?.match_rank;
            const exact = matches.filter(product => product.match_rank === rank && (rank <= 2 || normalize(product.name) === query));
            return exact.length === 1 ? exact[0] : null;
        },
        clearCache() {
            queryCache.clear();
        }
    };
}(window));
