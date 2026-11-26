(function (window) {
    'use strict';
    const label = key => key.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase());
    function render(value, depth) {
        if (value === null || value === undefined) return document.createTextNode('—');
        if (typeof value !== 'object') return document.createTextNode(String(value));
        if (depth >= 6) return document.createTextNode(JSON.stringify(value));
        const list = document.createElement('dl');
        list.className = 'small mb-2 pl-2 border-left';
        Object.entries(value).forEach(([key, item]) => {
            if (depth === 0 && ['proposal_id', 'task_id', 'action_key', 'version'].includes(key)) return;
            const term = document.createElement('dt'); term.textContent = Array.isArray(value) ? 'Line ' + (Number(key) + 1) : label(key);
            const description = document.createElement('dd'); description.className = 'text-break mb-2';
            description.append(render(item, depth + 1)); list.append(term, description);
        });
        return list;
    }
    window.ZoloAiActionDetails = value => render(value, 0);
})(window);
