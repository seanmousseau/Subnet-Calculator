// ── Theme toggle ─────────────────────────────────────────────────────────────
// Icon visibility is driven by CSS html[data-theme="light"] selectors — no JS needed.
function updateThemeToggleLabel() {
    const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
    document.getElementById('theme-toggle')
        .setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
}
document.getElementById('theme-toggle').addEventListener('click', () => {
    const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    updateThemeToggleLabel();
});
updateThemeToggleLabel();

// ── Tab switcher ─────────────────────────────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        document.getElementById('panel-' + btn.dataset.tab).classList.add('active');
        autoFocusActive();
    });

    btn.addEventListener('keydown', e => {
        const tabs = [...document.querySelectorAll('.tab-btn')];
        const idx = tabs.indexOf(e.currentTarget);
        let next = null;
        if (e.key === 'ArrowRight') next = tabs[(idx + 1) % tabs.length];
        if (e.key === 'ArrowLeft')  next = tabs[(idx - 1 + tabs.length) % tabs.length];
        if (next) { e.preventDefault(); next.focus(); next.click(); }
    });
});

// ── Copy to clipboard (with execCommand fallback for cross-origin iframes) ───
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(() => t.classList.remove('show'), 1500);
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); showToast('Copied!'); } catch { showToast('Copy failed'); }
    document.body.removeChild(ta);
}

function copyText(text, successMsg) {
    successMsg = successMsg || 'Copied!';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => showToast(successMsg)).catch(() => fallbackCopy(text));
    } else {
        fallbackCopy(text);
    }
}

document.querySelectorAll('.results').forEach(results => {
    results.addEventListener('click', e => {
        const row = e.target.closest('.result-row');
        if (!row) return;
        const val = row.querySelector('.result-value');
        if (!val) return;
        copyText(val.textContent.trim());
    });
});

// ── Share URL: show full URL and copy it ─────────────────────────────────────
const _base = window.location.origin + window.location.pathname;
document.querySelectorAll('.share-url').forEach(el => {
    // Override server-provided absolute URL with window.location for reverse-proxy accuracy
    const btn = el.closest('.share-bar')?.querySelector('.share-copy');
    if (btn) el.textContent = _base + btn.dataset.copy;
});
document.querySelectorAll('.share-copy').forEach(btn => {
    btn.addEventListener('click', () => {
        copyText(_base + btn.dataset.copy, 'Link copied!');
    });
});

// ── Subnet splitter: click to copy ──────────────────────────────────────────
document.querySelectorAll('.split-item').forEach(item => {
    item.addEventListener('click', e => {
        if (e.target.closest('.subnet-copy')) return; // handled by button handler
        copyText(item.dataset.copy);
    });
});

document.addEventListener('click', e => {
    const btn = e.target.closest('.subnet-copy');
    if (btn) { e.stopPropagation(); copyText(btn.dataset.copy, 'Copied!'); }
});

// ── Keyboard activation for copy targets (result rows + split items) ─────────
document.querySelectorAll('.result-row[tabindex], .split-item').forEach(function (el) {
    el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            el.click();
        }
    });
});

// ── Input auto-detection (paste "192.168.1.0/24" into IP field) ──────────────
function autoDetect(ipId, maskId) {
    const ipEl   = document.getElementById(ipId);
    const maskEl = document.getElementById(maskId);
    if (!ipEl || !maskEl) return;
    const val   = ipEl.value.trim();
    const slash = val.indexOf('/');
    if (slash !== -1) {
        ipEl.value   = val.slice(0, slash).trim();
        maskEl.value = val.slice(slash).trim();
    }
}

document.getElementById('ip')?.addEventListener('blur',   () => autoDetect('ip',   'mask'));
document.getElementById('ipv6')?.addEventListener('blur', () => autoDetect('ipv6', 'prefix'));

// ── Auto-focus first empty input on active panel ─────────────────────────────
function autoFocusActive() {
    const panel = document.querySelector('.panel.active');
    if (!panel) return;
    const first = panel.querySelector('input[type="text"]');
    if (first && !first.value) first.focus();
}

autoFocusActive();

// ── VLSM: dynamic requirement rows ──────────────────────────────────────────
(function () {
    const reqs = document.getElementById('vlsm-reqs');
    if (!reqs) return;

    function makeRow() {
        const row = document.createElement('div');
        row.className = 'vlsm-req-row';
        row.innerHTML =
            '<input type="text" name="vlsm_name[]" class="vlsm-name-input" placeholder="e.g. LAN A" autocomplete="off">' +
            '<input type="number" name="vlsm_hosts[]" class="vlsm-hosts-input" min="1" placeholder="e.g. 50">' +
            '<button type="button" class="vlsm-remove-row" aria-label="Remove row">\u00d7</button>';
        return row;
    }

    document.querySelector('.vlsm-add-row')?.addEventListener('click', () => {
        reqs.appendChild(makeRow());
    });

    reqs.addEventListener('click', e => {
        const btn = e.target.closest('.vlsm-remove-row');
        if (!btn) return;
        const rows = reqs.querySelectorAll('.vlsm-req-row');
        if (rows.length > 1) btn.closest('.vlsm-req-row').remove();
    });

    reqs.addEventListener('keydown', function (e) {
        if (e.key !== 'Delete' && e.key !== 'Backspace') return;
        const btn = e.target.closest('.vlsm-remove-row');
        if (!btn) return;
        e.preventDefault();
        const rows = [...reqs.querySelectorAll('.vlsm-req-row')];
        if (rows.length <= 1) return;
        const idx = rows.indexOf(btn.closest('.vlsm-req-row'));
        btn.closest('.vlsm-req-row').remove();
        const remaining = reqs.querySelectorAll('.vlsm-name-input');
        (remaining[idx] || remaining[idx - 1])?.focus();
    });
})();

// ── VLSM: submit validation + loading state ──────────────────────────────────
(function () {
    const form = document.querySelector('.vlsm-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        form.querySelectorAll('.vlsm-inline-error').forEach(function (el) { el.remove(); });
        var hasError = false;
        form.querySelectorAll('.vlsm-req-row').forEach(function (row) {
            var hostsInput = row.querySelector('.vlsm-hosts-input');
            if (!hostsInput) return;
            var val = parseInt(hostsInput.value, 10);
            if (!hostsInput.value || isNaN(val) || val < 1) {
                hasError = true;
                var msg = document.createElement('span');
                msg.className = 'vlsm-inline-error';
                msg.textContent = 'Must be \u2265 1';
                hostsInput.after(msg);
            }
        });
        if (hasError) { e.preventDefault(); return; }
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Calculating\u2026'; }
    });
})();

// ── VLSM result cells: click to copy subnet ───────────────────────────────────
document.querySelectorAll('.vlsm-subnet-cell').forEach(cell => {
    cell.addEventListener('click', () => copyText(cell.dataset.copy));
    cell.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cell.click(); }
    });
});

// ── Copy All ──────────────────────────────────────────────────────────────────
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.copy-all-btn');
    if (!btn) return;
    var target = btn.dataset.target;
    var texts = [];
    if (target === 'split') {
        var list = btn.closest('.split-list');
        if (list) {
            list.querySelectorAll('.split-item[data-copy]').forEach(function (item) {
                texts.push(item.dataset.copy);
            });
        }
    } else if (target === 'vlsm') {
        document.querySelectorAll('.vlsm-subnet-cell[data-copy]').forEach(function (cell) {
            texts.push(cell.dataset.copy);
        });
    } else if (target === 'supernet' || target === 'ula') {
        var list2 = btn.closest('.split-list');
        if (list2) {
            list2.querySelectorAll('.split-item[data-copy]').forEach(function (item) {
                texts.push(item.dataset.copy);
            });
        }
    }
    if (texts.length > 0) copyText(texts.join('\n'), 'All copied!');
});

// ── VLSM: CSV export ──────────────────────────────────────────────────────────
document.getElementById('vlsm-export-csv')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm-table');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    var networkVal = (document.getElementById('vlsm_network')?.value || 'network').replace(/[^0-9.]/g, '');
    var cidrVal    = (document.getElementById('vlsm_cidr')?.value    || '0').replace(/[^0-9]/g, '');
    var filename = 'vlsm-' + networkVal + '-' + cidrVal + '.csv';
    var headers = ['Name', 'Hosts Needed', 'Allocated Subnet', 'First Usable', 'Last Usable', 'Usable IPs', 'Waste'];
    var lines = [headers.join(',')];
    rows.forEach(function (tr) {
        var cells = tr.querySelectorAll('td');
        var name      = (cells[0]?.textContent || '').trim().replace(/,/g, ' ');
        var hostsNeed = (cells[1]?.textContent || '').trim().replace(/,/g, '');
        var subnet    = (tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim();
        var first     = (tr.dataset.first || '').trim();
        var last      = (tr.dataset.last  || '').trim();
        var usable    = (cells[3]?.textContent || '').trim().replace(/,/g, '');
        var waste     = (cells[4]?.textContent || '').trim().replace(/,/g, '');
        lines.push([name, hostsNeed, subnet, first, last, usable, waste].join(','));
    });
    var csv = lines.join('\r\n');
    var blob = new Blob([csv], {type: 'text/csv'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = filename; a.style.display = 'none';
    document.body.appendChild(a); a.click();
    setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
});

// ── VLSM: JSON export ─────────────────────────────────────────────────────────
document.getElementById('vlsm-export-json')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm-table');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    var networkVal = (document.getElementById('vlsm_network')?.value || 'network').replace(/[^0-9.]/g, '');
    var cidrVal    = (document.getElementById('vlsm_cidr')?.value    || '0').replace(/[^0-9]/g, '');
    var filename = 'vlsm-' + networkVal + '-' + cidrVal + '.json';
    var data = [];
    rows.forEach(function (tr) {
        var cells = tr.querySelectorAll('td');
        data.push({
            name:            (cells[0]?.textContent || '').trim(),
            hosts_needed:    parseInt((cells[1]?.textContent || '0').replace(/,/g, ''), 10) || 0,
            allocated_subnet:(tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim(),
            first_usable:    (tr.dataset.first || '').trim(),
            last_usable:     (tr.dataset.last  || '').trim(),
            usable_ips:      parseInt((cells[3]?.textContent || '0').replace(/,/g, ''), 10) || 0,
            waste:           parseInt((cells[4]?.textContent || '0').replace(/,/g, ''), 10) || 0,
        });
    });
    var json = JSON.stringify(data, null, 2);
    var blob = new Blob([json], {type: 'application/json'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = filename; a.style.display = 'none';
    document.body.appendChild(a); a.click();
    setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
});

// ── VLSM: XLSX export ─────────────────────────────────────────────────────────
document.getElementById('vlsm-export-xlsx')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm-table');
    if (!table) return;
    var networkVal = (document.getElementById('vlsm_network')?.value || 'network').replace(/[^0-9.]/g, '');
    var cidrVal    = (document.getElementById('vlsm_cidr')?.value    || '0').replace(/[^0-9]/g, '');
    var filename = 'vlsm-' + networkVal + '-' + cidrVal + '.xlsx';
    /* global XLSX */
    if (typeof XLSX === 'undefined') { alert('XLSX library not loaded.'); return; }
    var wb = XLSX.utils.table_to_book(table, {sheet: 'VLSM'});
    XLSX.writeFile(wb, filename);
});

// ── ASCII network diagram ──────────────────────────────────────────────────────
/**
 * Build an ASCII tree from a parent CIDR and an array of {cidr, name?} rows.
 * @param {string} parent
 * @param {Array<{cidr: string, name?: string}>} rows
 * @returns {string}
 */
function buildAsciiDiagram(parent, rows) {
    var lines = [parent];
    rows.forEach(function (row, i) {
        var isLast  = i === rows.length - 1;
        var prefix  = isLast ? '\u2514\u2500 ' : '\u251C\u2500 ';
        var label   = row.cidr + (row.name ? '  ' + row.name : '');
        lines.push(prefix + label);
    });
    return lines.join('\n');
}

// ASCII export for IPv4/IPv6 splitter results
document.addEventListener('click', function (e) {
    var btn = /** @type {HTMLElement|null} */ (e.target);
    if (!btn || !btn.classList.contains('ascii-export-btn')) return;
    var list   = btn.closest('.split-list');
    if (!list) return;
    var parent = (/** @type {HTMLElement} */ (list)).dataset.parent || '';
    var items  = list.querySelectorAll('.split-item');
    var rows   = Array.prototype.map.call(items, function (el) {
        return {cidr: (/** @type {HTMLElement} */ (el)).dataset.copy || ''};
    });
    var diagram = buildAsciiDiagram(parent, rows);
    copyText(diagram, 'ASCII copied!');
});

// ASCII export for VLSM results
document.getElementById('vlsm-export-ascii')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm-table');
    if (!table) return;
    var networkVal = (document.getElementById('vlsm_network')?.value || '').trim();
    var cidrVal    = (document.getElementById('vlsm_cidr')?.value    || '').trim();
    var parent = networkVal && cidrVal ? networkVal + '/' + cidrVal : networkVal;
    var rows   = Array.prototype.map.call(table.querySelectorAll('tbody tr'), function (tr) {
        var cells = tr.querySelectorAll('td');
        return {
            cidr: (tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim(),
            name: (cells[0]?.textContent || '').trim(),
        };
    });
    var diagram = buildAsciiDiagram(parent, rows);
    copyText(diagram, 'ASCII copied!');
});

// ── Tooltip right-edge overflow detection (#205) ─────────────────────────────
(function () {
    function detectBubbleEdges() {
        var vw = window.innerWidth;
        document.querySelectorAll('.help-bubble').forEach(function (bubble) {
            var rect = bubble.getBoundingClientRect();
            if (rect.width === 0) { return; } // inside hidden panel — skip
            bubble.classList.toggle('bubble-right-edge', (vw - rect.right) < 150);
        });
    }
    detectBubbleEdges();
    window.addEventListener('resize', detectBubbleEdges);
    // Re-run after tab switches (panel becomes visible, layout recalculates)
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            requestAnimationFrame(detectBubbleEdges);
        });
    });
})();

// ── iframe: auto-detect and report height to parent via postMessage ───────────
if (window.self !== window.top) {
    document.documentElement.classList.add('in-iframe');
    var _parentOrigin = (function () {
        // ancestorOrigins is always accurate (Chrome/Edge); unaffected by navigation
        if (window.location.ancestorOrigins && window.location.ancestorOrigins.length) {
            return window.location.ancestorOrigins[0];
        }
        // Firefox: persist the parent origin in sessionStorage so it
        // survives same-origin form-submit navigations within the iframe
        try {
            var stored = sessionStorage.getItem('_sc_parent_origin');
            if (stored) return stored;
        } catch { /* sessionStorage unavailable */ }
        try {
            var o = new URL(document.referrer).origin;
            if (o !== window.location.origin) {
                try { sessionStorage.setItem('_sc_parent_origin', o); } catch { /* sessionStorage unavailable */ }
                return o;
            }
        } catch { /* invalid referrer URL */ }
        return null;
    })();
    (function () {
        function postHeight() {
            var card = document.querySelector('.card');
            // Use only the card's own height — body/document scrollHeight reflects
            // the iframe's current (parent-set) height and never shrinks on Reset.
            var h = card ? Math.ceil(card.getBoundingClientRect().height) : 0;
            window.parent.postMessage({ type: 'sc-resize', height: h }, _parentOrigin || '*');
        }
        postHeight();
        requestAnimationFrame(function () { postHeight(); });
        window.addEventListener('load', postHeight);
        if (window.ResizeObserver) {
            var card = document.querySelector('.card');
            if (card) new ResizeObserver(postHeight).observe(card);
            document.querySelectorAll('.cf-turnstile').forEach(function (el) {
                new ResizeObserver(postHeight).observe(el);
            });
        } else {
            // Fallback for browsers without ResizeObserver: poll 300 ms × 20 = 6 s
            var polls = 0;
            var timer = setInterval(function () { postHeight(); if (++polls >= 20) clearInterval(timer); }, 300);
        }
    })();
    // Listen for background colour commands from the parent page
    window.addEventListener('message', function (e) {
        if (_parentOrigin && e.origin !== _parentOrigin) return;
        if (!e.data || e.data.type !== 'sc-set-bg') return;
        var color = e.data.color;
        if (color && color !== 'null' && /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(color)) {
            document.documentElement.style.setProperty('--color-bg', color);
            document.body.style.backgroundColor = color;
        } else {
            document.documentElement.style.removeProperty('--color-bg');
            document.body.style.backgroundColor = '';
        }
    });
}

// ── Tool Drawer ────────────────────────────────────────────────────────────
const toolDrawer = {
    _activeTrigger: null,

    init() {
        document.documentElement.classList.add('js-enabled');

        // Hide all tool panels on init (no-JS users see them stacked without this)
        document.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });

        // Toolbar button clicks
        document.querySelectorAll('.tool-trigger').forEach(btn => {
            btn.addEventListener('click', () => {
                const panel  = btn.closest('.panel');
                const drawer = panel?.querySelector('.tool-drawer');
                if (!panel || !drawer) return;
                const toolId = btn.dataset.tool;
                if (drawer.classList.contains('open') && btn.getAttribute('aria-expanded') === 'true') {
                    this.close(drawer, btn);
                } else if (drawer.classList.contains('open')) {
                    this.swap(drawer, panel, toolId, btn);
                } else {
                    this.open(drawer, panel, toolId, btn);
                }
            });
        });

        // × close button
        document.querySelectorAll('.tool-drawer-close').forEach(btn => {
            btn.addEventListener('click', () => {
                const drawer     = btn.closest('.tool-drawer');
                const activeBtn  = drawer.closest('.panel').querySelector('.tool-trigger[aria-expanded="true"]');
                this.close(drawer, activeBtn);
            });
        });

        // Escape key closes open drawer in the active panel
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            const openDrawer = document.querySelector('.panel.active .tool-drawer.open');
            if (!openDrawer) return;
            const activeBtn = openDrawer.closest('.panel').querySelector('.tool-trigger[aria-expanded="true"]');
            this.close(openDrawer, activeBtn);
        });

        // Tab switch: close any open drawer in the panel being left
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tool-drawer.open').forEach(drawer => {
                    const panel = drawer.closest('.panel');
                    drawer.classList.remove('open');
                    panel.querySelectorAll('.tool-trigger').forEach(t => {
                        t.setAttribute('aria-expanded', 'false');
                        t.classList.remove('active');
                    });
                    drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
                    this._activeTrigger = null;
                });
            });
        });

        // Auto-open from PHP data-open-tool on page load
        const activePanel = document.querySelector('.panel.active');
        if (activePanel) {
            const toolbar = activePanel.querySelector('.tool-toolbar[data-open-tool]');
            if (toolbar) {
                const toolId  = toolbar.dataset.openTool;
                const trigger = toolbar.querySelector(`.tool-trigger[data-tool="${toolId}"]`);
                const drawer  = activePanel.querySelector('.tool-drawer');
                if (trigger && drawer) this.open(drawer, activePanel, toolId, trigger);
            }
        }
    },

    open(drawer, panel, toolId, trigger) {
        drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
        const target = drawer.querySelector(`.tool-panel[data-tool="${toolId}"]`);
        if (!target) return;
        target.hidden = false;

        const titleEl = drawer.querySelector('.tool-drawer-title');
        if (titleEl) titleEl.textContent = trigger.textContent.trim();

        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('active');

        drawer.classList.add('open');
        this._activeTrigger = trigger;

        const first = target.querySelector('input, button, textarea, select, [tabindex]:not([tabindex="-1"])');
        if (first) first.focus({ preventScroll: true });
    },

    close(drawer, trigger) {
        drawer.classList.remove('open');
        const panel = drawer.closest('.panel');
        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
        if (this._activeTrigger) {
            this._activeTrigger.focus();
            this._activeTrigger = null;
        } else if (trigger) {
            trigger.focus();
        }
    },

    swap(drawer, panel, toolId, trigger) {
        drawer.querySelectorAll('.tool-panel').forEach(p => { p.hidden = true; });
        const target = drawer.querySelector(`.tool-panel[data-tool="${toolId}"]`);
        if (target) target.hidden = false;

        const titleEl = drawer.querySelector('.tool-drawer-title');
        if (titleEl) titleEl.textContent = trigger.textContent.trim();

        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('active');

        this._activeTrigger = trigger;
        const first = target ? target.querySelector('input, button, textarea, select, [tabindex]:not([tabindex="-1"])') : null;
        if (first) first.focus({ preventScroll: true });
    }
};

toolDrawer.init();

// ── Service Worker registration ───────────────────────────────────────────
if (window.self === window.top && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}
