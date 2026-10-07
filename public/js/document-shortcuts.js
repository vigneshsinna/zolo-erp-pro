/*
 * Central document shortcut registry (one keydown listener for the authenticated ERP).
 * A shortcut only opens the existing document page in its new-document state. When the current page already is that
 * document, it asks the page's lifecycle API (window.zoloDocumentWorkspace) to start a new document instead of
 * navigating, so this file never serializes or clears form state itself.
 */
(() => {
    'use strict';
    const node = document.getElementById('document-shortcut-data');
    if (!node || window.zoloDocumentShortcuts) return;
    let entries = [];
    try { entries = JSON.parse(node.textContent) || []; } catch (error) { return; }

    const parse = key => {
        const parts = key.split('+');
        const main = parts.pop();
        return {main, ctrl: parts.includes('Ctrl'), alt: parts.includes('Alt'), shift: parts.includes('Shift')};
    };
    const registry = entries.map(entry => ({...entry, combo: parse(entry.key)}));
    const find = id => registry.find(entry => entry.id === id) || null;

    // Another dialog owning the keyboard (a Bootstrap modal, a native <dialog>, or anything marked data-owns-keys) wins.
    const dialogOwnsKeys = () => !!document.querySelector('dialog[open], .modal.show, [data-owns-keys]');

    function open(entry) {
        const workspace = window.zoloDocumentWorkspace;
        if (workspace && workspace.handles && workspace.handles(entry)) {
            workspace.newDocument({shortcut: entry.id, url: entry.url});
            return;
        }
        window.location.href = entry.url;
    }

    document.addEventListener('keydown', event => {
        if (event.defaultPrevented || event.repeat || event.isComposing || event.metaKey) return;
        const entry = registry.find(({combo}) => combo.main === event.key
            && combo.ctrl === event.ctrlKey && combo.alt === event.altKey && combo.shift === event.shiftKey);
        if (entry) {
            if (dialogOwnsKeys()) return;
            event.preventDefault();
            open(entry);
            return;
        }
        // "?" opens the help dialog, but never while typing.
        if (event.key === '?' && !event.ctrlKey && !event.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(event.target.tagName) && !event.target.isContentEditable && !dialogOwnsKeys()) {
            event.preventDefault();
            showHelp();
        }
    });

    // Visible links/buttons are the guaranteed path; F-keys are accelerators for the same action.
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-document-shortcut]');
        if (!trigger || event.ctrlKey || event.metaKey || event.shiftKey || event.button) return;
        const entry = find(trigger.getAttribute('data-document-shortcut'));
        const workspace = window.zoloDocumentWorkspace;
        if (entry && workspace && workspace.handles && workspace.handles(entry)) {
            event.preventDefault();
            workspace.newDocument({shortcut: entry.id, url: entry.url});
        }
    });

    function showHelp() {
        const existing = document.getElementById('document-shortcut-help');
        if (existing) { existing.remove(); return; }
        const local = (window.zoloDocumentWorkspace && window.zoloDocumentWorkspace.localShortcuts) || [];
        const dialog = document.createElement('dialog');
        dialog.id = 'document-shortcut-help';
        dialog.setAttribute('aria-label', 'Keyboard shortcuts');
        dialog.innerHTML = '<h3>Keyboard shortcuts</h3>';
        const table = document.createElement('table');
        const add = (key, label) => {
            const row = table.insertRow();
            const keyCell = row.insertCell(), labelCell = row.insertCell();
            const kbd = document.createElement('kbd'); kbd.textContent = key; keyCell.appendChild(kbd);
            labelCell.textContent = label;
        };
        registry.forEach(entry => add(entry.key, entry.label));
        local.forEach(item => add(item.key, item.label));
        add('?', 'Show / hide this list');
        dialog.appendChild(table);
        const close = document.createElement('button');
        close.type = 'button'; close.className = 'btn btn-sm btn-primary'; close.textContent = 'Close';
        close.addEventListener('click', () => dialog.remove());
        dialog.appendChild(close);
        dialog.addEventListener('cancel', () => dialog.remove());
        document.body.appendChild(dialog);
        if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
        close.focus();
    }

    document.addEventListener('click', event => {
        if (event.target.closest('[data-shortcut-help]')) { event.preventDefault(); showHelp(); }
    });

    // ?new=1 on a register page whose "add" control opens the document's own start step (for example the Returns bill-reference prompt).
    function startFromRegister() {
        const query = new URLSearchParams(window.location.search);
        const trigger = document.querySelector('[data-new-document]');
        if (query.get('new') !== '1' || !trigger) return;
        query.delete('new');
        window.history.replaceState(null, '', window.location.pathname + (query.toString() ? '?' + query : '') + window.location.hash);
        trigger.click();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', startFromRegister); else startFromRegister();

    window.zoloDocumentShortcuts = {registry, find, open, showHelp};
})();
