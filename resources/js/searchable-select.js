/**
 * Type-to-search for every dropdown in the system.
 *
 * Any ordinary <select> opens a small popup with a search box instead of the browser's list: type to narrow the
 * options, use the arrow keys and Enter, or click. The real <select> stays the source of truth (its value, its
 * options, its change event), so forms and v-model keep working exactly as before, and a page needs no change.
 *
 * Left alone: <select multiple>, a list box (size > 1), a disabled select, and any select marked data-native.
 */

const POPUP_LIMIT = 150;          // options drawn at once; typing narrows a longer list

let popup = null;                 // the one open popup: { select, root, input, list, items, active }

const isEnhanceable = (el) =>
    el instanceof HTMLSelectElement && !el.multiple && !(el.size > 1) && !el.disabled && !el.hasAttribute('data-native');

const norm = (s) => String(s ?? '').toLowerCase().normalize('NFKC');

/** the choices of a select as plain objects, in order, with their group heading */
function readOptions(select) {
    const out = [];
    let group = null;

    const walk = (node) => {
        for (const child of node.children) {
            if (child.tagName === 'OPTGROUP') {
                group = child.label;
                walk(child);
                group = null;
            } else if (child.tagName === 'OPTION') {
                out.push({ el: child, text: (child.textContent || '').trim() || ' ', group, disabled: child.disabled || child.parentElement?.disabled });
            }
        }
    };

    walk(select);

    return out;
}

function close(refocus = true) {
    if (!popup) return;

    const { select, root } = popup;
    root.remove();
    popup = null;
    select.setAttribute('aria-expanded', 'false');

    document.removeEventListener('mousedown', onOutside, true);
    window.removeEventListener('resize', onWindowChange);
    window.removeEventListener('scroll', onScroll, true);

    if (refocus) select.focus({ preventScroll: true });
}

const onOutside = (e) => { if (popup && !popup.root.contains(e.target) && e.target !== popup.select) close(false); };
const onWindowChange = () => close(false);
const onScroll = (e) => { if (popup && !popup.root.contains(e.target)) close(false); };

function choose(opt) {
    if (!popup || opt.disabled) return;

    const { select } = popup;
    const changed = !opt.el.selected;

    opt.el.selected = true;
    close(true);

    if (changed) {
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function render() {
    if (!popup) return;

    const q = norm(popup.input.value).trim();
    const words = q.split(/\s+/).filter(Boolean);
    const all = popup.items;

    popup.shown = all.filter((o) => {
        if (!words.length) return true;
        const hay = norm(o.text) + ' ' + norm(o.group);
        return words.every((w) => hay.includes(w));
    });

    const list = popup.list;
    list.textContent = '';

    if (!popup.shown.length) {
        const empty = document.createElement('div');
        empty.className = 'ss-empty';
        empty.textContent = 'Nothing matches';
        list.appendChild(empty);
        popup.active = -1;
        popup.input.removeAttribute('aria-activedescendant');
        return;
    }

    // start on the chosen option when nothing is typed, else on the first match
    const current = popup.shown.findIndex((o) => o.el.selected);
    popup.active = !words.length && current >= 0 ? current : 0;

    let lastGroup = null;
    const slice = popup.shown.slice(0, POPUP_LIMIT);

    slice.forEach((o, i) => {
        if (o.group && o.group !== lastGroup) {
            const h = document.createElement('div');
            h.className = 'ss-group';
            h.textContent = o.group;
            list.appendChild(h);
        }
        lastGroup = o.group;

        const row = document.createElement('div');
        row.id = `ss-opt-${i}`;
        row.setAttribute('role', 'option');
        row.className = 'ss-option' + (o.el.selected ? ' is-selected' : '') + (o.disabled ? ' is-disabled' : '');
        row.setAttribute('aria-selected', o.el.selected ? 'true' : 'false');
        row.textContent = o.text;
        row.dataset.index = String(i);
        row.addEventListener('mousemove', () => { if (popup && popup.active !== i) { popup.active = i; paintActive(false); } });
        row.addEventListener('mousedown', (e) => { e.preventDefault(); choose(o); });
        list.appendChild(row);
    });

    if (popup.shown.length > POPUP_LIMIT) {
        const more = document.createElement('div');
        more.className = 'ss-empty';
        more.textContent = `${popup.shown.length - POPUP_LIMIT} more: keep typing to narrow the list`;
        list.appendChild(more);
    }

    paintActive(true);
}

function paintActive(scroll) {
    if (!popup) return;

    const rows = popup.list.querySelectorAll('.ss-option');
    rows.forEach((r, i) => r.classList.toggle('is-active', i === popup.active));

    const row = rows[popup.active];

    if (row) {
        popup.input.setAttribute('aria-activedescendant', row.id);
        if (scroll) row.scrollIntoView({ block: 'nearest' });
    }
}

function move(step) {
    if (!popup || !popup.shown.length) return;

    const max = Math.min(popup.shown.length, POPUP_LIMIT) - 1;
    let i = popup.active;

    // skip disabled choices
    for (let n = 0; n <= max; n++) {
        i = Math.min(max, Math.max(0, i + step));
        if (!popup.shown[i].disabled || i === 0 || i === max) break;
    }

    popup.active = i;
    paintActive(true);
}

function position() {
    const { select, root } = popup;
    const r = select.getBoundingClientRect();
    const width = Math.max(r.width, 220);
    const room = window.innerHeight - r.bottom;
    const above = room < 260 && r.top > room;

    root.style.minWidth = `${width}px`;
    root.style.width = `${width}px`;
    root.style.left = `${Math.max(8, Math.min(r.left, window.innerWidth - width - 8))}px`;

    if (above) {
        root.style.bottom = `${window.innerHeight - r.top + 4}px`;
        root.style.top = 'auto';
        popup.list.style.maxHeight = `${Math.min(280, r.top - 70)}px`;
    } else {
        root.style.top = `${r.bottom + 4}px`;
        root.style.bottom = 'auto';
        popup.list.style.maxHeight = `${Math.min(280, room - 70)}px`;
    }
}

function open(select) {
    if (popup?.select === select) return close();

    close(false);

    const root = document.createElement('div');
    root.className = 'ss-popup';

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'ss-input';
    input.placeholder = 'Type to search…';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', 'ss-list');
    input.setAttribute('aria-label', (select.getAttribute('aria-label') || select.labels?.[0]?.textContent || 'Search options').trim());

    const list = document.createElement('div');
    list.id = 'ss-list';
    list.className = 'ss-list';
    list.setAttribute('role', 'listbox');

    root.append(input, list);
    document.body.appendChild(root);

    popup = { select, root, input, list, items: readOptions(select), shown: [], active: 0 };
    select.setAttribute('aria-expanded', 'true');

    position();
    render();
    input.focus({ preventScroll: true });

    input.addEventListener('input', render);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Home') { e.preventDefault(); popup.active = 0; paintActive(true); }
        else if (e.key === 'End') { e.preventDefault(); popup.active = Math.min(popup.shown.length, POPUP_LIMIT) - 1; paintActive(true); }
        else if (e.key === 'Enter') { e.preventDefault(); const o = popup.shown[popup.active]; if (o) choose(o); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
        else if (e.key === 'Tab') { close(false); }
    });

    document.addEventListener('mousedown', onOutside, true);
    window.addEventListener('resize', onWindowChange);
    window.addEventListener('scroll', onScroll, true);
}

export function installSearchableSelects() {
    if (typeof document === 'undefined' || window.__searchableSelects) return;
    window.__searchableSelects = true;

    // a click opens the search popup instead of the browser's own list
    document.addEventListener('mousedown', (e) => {
        const select = e.target instanceof Element ? e.target.closest('select') : null;

        if (!select || e.button !== 0 || !isEnhanceable(select)) return;

        e.preventDefault();
        select.focus({ preventScroll: true });
        open(select);
    }, true);

    // from the keyboard: Enter, Space or the arrow keys on a focused dropdown
    document.addEventListener('keydown', (e) => {
        const select = e.target;

        if (!isEnhanceable(select) || popup) return;

        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp' || (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey)) {
            e.preventDefault();
            open(select);

            // a typed character starts the search
            if (e.key.length === 1 && e.key !== ' ' && popup) {
                popup.input.value = e.key;
                render();
            }
        }
    }, true);
}
