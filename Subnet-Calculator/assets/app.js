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
        document.querySelectorAll('.vlsm-table:not(.vlsm6-table) .vlsm-subnet-cell[data-copy]').forEach(function (cell) {
            texts.push(cell.dataset.copy);
        });
    } else if (target === 'vlsm6') {
        document.querySelectorAll('.vlsm6-table .vlsm-subnet-cell[data-copy]').forEach(function (cell) {
            texts.push(cell.dataset.copy);
        });
    } else if (target === 'supernet' || target === 'ula') {
        var list2 = btn.closest('.split-list');
        if (list2) {
            list2.querySelectorAll('.split-item[data-copy]').forEach(function (item) {
                texts.push(item.dataset.copy);
            });
        }
    } else if (target === 'lookup') {
        var results = btn.closest('.lookup-results');
        if (results) {
            results.querySelectorAll('.lookup-table tbody tr').forEach(function (tr) {
                var cells = tr.querySelectorAll('td');
                var ip      = (cells[0]?.textContent || '').trim();
                var deepest = (cells[1]?.textContent || '').trim();
                var all     = (cells[2]?.textContent || '').trim();
                texts.push(ip + '\t' + deepest + '\t' + all);
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
    _trapKeydown: null,

    _buildTrap(drawer) {
        return e => {
            if (e.key !== 'Tab') return;
            const els = Array.from(
                drawer.querySelectorAll('input, button, textarea, select, [tabindex]:not([tabindex="-1"])')
            ).filter(el => !el.disabled && el.offsetParent !== null);
            if (els.length < 2) return;
            const first = els[0], last = els[els.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault(); last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault(); first.focus();
            }
        };
    },

    init() {
        document.documentElement.classList.add('js-enabled');
        // CSS (.js-enabled .tool-panel { display: none }) hides all panels once js-enabled is set.

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
                    drawer.querySelectorAll('.tool-panel').forEach(p => p.classList.remove('active'));
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
        drawer.querySelectorAll('.tool-panel').forEach(p => p.classList.remove('active'));
        const target = drawer.querySelector(`.tool-panel[data-tool="${toolId}"]`);
        if (!target) return;
        target.classList.add('active');

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

        if (this._trapKeydown) drawer.removeEventListener('keydown', this._trapKeydown);
        this._trapKeydown = this._buildTrap(drawer);
        drawer.addEventListener('keydown', this._trapKeydown);

        const first = target.querySelector('input, button:not([aria-label="Help"]), textarea, select, [tabindex]:not([tabindex="-1"]):not([aria-label="Help"])');
        if (first) first.focus({ preventScroll: true });
    },

    close(drawer, trigger) {
        drawer.classList.remove('open');
        if (this._trapKeydown) {
            drawer.removeEventListener('keydown', this._trapKeydown);
            this._trapKeydown = null;
        }
        const panel = drawer.closest('.panel');
        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        drawer.querySelectorAll('.tool-panel').forEach(p => p.classList.remove('active'));
        if (this._activeTrigger) {
            this._activeTrigger.focus();
            this._activeTrigger = null;
        } else if (trigger) {
            trigger.focus();
        }
    },

    swap(drawer, panel, toolId, trigger) {
        drawer.querySelectorAll('.tool-panel').forEach(p => p.classList.remove('active'));
        const target = drawer.querySelector(`.tool-panel[data-tool="${toolId}"]`);
        if (target) target.classList.add('active');

        const titleEl = drawer.querySelector('.tool-drawer-title');
        if (titleEl) titleEl.textContent = trigger.textContent.trim();

        panel.querySelectorAll('.tool-trigger').forEach(t => {
            t.setAttribute('aria-expanded', 'false');
            t.classList.remove('active');
        });
        trigger.setAttribute('aria-expanded', 'true');
        trigger.classList.add('active');

        this._activeTrigger = trigger;

        if (this._trapKeydown) drawer.removeEventListener('keydown', this._trapKeydown);
        this._trapKeydown = this._buildTrap(drawer);
        drawer.addEventListener('keydown', this._trapKeydown);

        const first = target ? target.querySelector('input, button:not([aria-label="Help"]), textarea, select, [tabindex]:not([tabindex="-1"]):not([aria-label="Help"])') : null;
        if (first) first.focus({ preventScroll: true });
    }
};

toolDrawer.init();

// ── VLSM6: dynamic requirement rows ──────────────────────────────────────────
(function () {
    const reqs = document.getElementById('vlsm6-reqs');
    if (!reqs) return;

    function makeRow() {
        const row = document.createElement('div');
        row.className = 'vlsm-req-row';
        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.name = 'vlsm6_name[]';
        nameInput.className = 'vlsm6-name-input';
        nameInput.placeholder = 'e.g. Site A';
        nameInput.autocomplete = 'off';
        const hostsInput = document.createElement('input');
        hostsInput.type = 'text';
        hostsInput.name = 'vlsm6_hosts[]';
        hostsInput.className = 'vlsm6-hosts-input';
        hostsInput.placeholder = 'e.g. 256 or 2^64';
        hostsInput.autocomplete = 'off';
        hostsInput.spellcheck = false;
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'vlsm-remove-row';
        removeBtn.setAttribute('aria-label', 'Remove row');
        removeBtn.textContent = '×';
        row.appendChild(nameInput);
        row.appendChild(hostsInput);
        row.appendChild(removeBtn);
        return row;
    }

    document.querySelector('.vlsm6-add-row')?.addEventListener('click', () => {
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
        const remaining = reqs.querySelectorAll('.vlsm6-name-input');
        (remaining[idx] || remaining[idx - 1])?.focus();
    });
})();

// ── VLSM6: submit validation + loading state ─────────────────────────────────
(function () {
    const form = document.querySelector('.vlsm6-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
        form.querySelectorAll('.vlsm-inline-error').forEach(function (el) { el.remove(); });
        var hasError = false;
        form.querySelectorAll('.vlsm-req-row').forEach(function (row) {
            var hostsInput = row.querySelector('.vlsm6-hosts-input');
            if (!hostsInput) return;
            var v = (hostsInput.value || '').trim();
            var powMatch = v.match(/^2\^(\d{1,3})$/);
            var ok = /^\d+$/.test(v)
                ? parseInt(v, 10) >= 1
                : (powMatch !== null && parseInt(powMatch[1], 10) <= 128);
            if (!ok) {
                hasError = true;
                var msg = document.createElement('span');
                msg.className = 'vlsm-inline-error';
                msg.textContent = 'Use a positive integer or 2^N';
                hostsInput.after(msg);
            }
        });
        if (hasError) { e.preventDefault(); return; }
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Calculating…'; }
    });
})();

// ── VLSM6: CSV export ────────────────────────────────────────────────────────
document.getElementById('vlsm6-export-csv')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm6-table');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    var networkVal = (document.getElementById('vlsm6_network')?.value || 'network').replace(/[^0-9a-fA-F:]/g, '');
    var cidrVal    = (document.getElementById('vlsm6_cidr')?.value    || '0').replace(/[^0-9]/g, '');
    var filename = 'vlsm6-' + (networkVal || 'network') + '-' + cidrVal + '.csv';
    var headers = ['Name', 'Hosts Needed', 'Allocated Subnet', 'Usable'];
    var lines = [headers.join(',')];
    rows.forEach(function (tr) {
        var cells = tr.querySelectorAll('td');
        var name      = (cells[0]?.textContent || '').trim().replace(/,/g, ' ');
        var hostsNeed = (cells[1]?.textContent || '').trim().replace(/,/g, '');
        var subnet    = (tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim();
        var usable    = (cells[3]?.textContent || '').trim().replace(/,/g, '');
        lines.push([name, hostsNeed, subnet, usable].join(','));
    });
    var csv = lines.join('\r\n');
    var blob = new Blob([csv], {type: 'text/csv'});
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href = url; a.download = filename; a.style.display = 'none';
    document.body.appendChild(a); a.click();
    setTimeout(function () { document.body.removeChild(a); URL.revokeObjectURL(url); }, 100);
});

// ── VLSM6: JSON export ───────────────────────────────────────────────────────
document.getElementById('vlsm6-export-json')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm6-table');
    if (!table) return;
    var rows = table.querySelectorAll('tbody tr');
    var networkVal = (document.getElementById('vlsm6_network')?.value || 'network').replace(/[^0-9a-fA-F:]/g, '');
    var cidrVal    = (document.getElementById('vlsm6_cidr')?.value    || '0').replace(/[^0-9]/g, '');
    var filename = 'vlsm6-' + (networkVal || 'network') + '-' + cidrVal + '.json';
    var data = [];
    rows.forEach(function (tr) {
        var cells = tr.querySelectorAll('td');
        data.push({
            name:             (cells[0]?.textContent || '').trim(),
            hosts_needed:     (cells[1]?.textContent || '').trim(),
            allocated_subnet: (tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim(),
            usable:           (cells[3]?.textContent || '').trim()
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

// ── VLSM6: ASCII export ──────────────────────────────────────────────────────
document.getElementById('vlsm6-export-ascii')?.addEventListener('click', function () {
    var table = document.querySelector('.vlsm6-table');
    if (!table) return;
    var networkVal = (document.getElementById('vlsm6_network')?.value || '').trim();
    var cidrVal    = (document.getElementById('vlsm6_cidr')?.value    || '').trim();
    var parent = networkVal && cidrVal ? networkVal + '/' + cidrVal.replace(/^\//, '') : networkVal;
    var rows   = Array.prototype.map.call(table.querySelectorAll('tbody tr'), function (tr) {
        var cells = tr.querySelectorAll('td');
        return {
            cidr: (tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim(),
            name: (cells[0]?.textContent || '').trim()
        };
    });
    var diagram = buildAsciiDiagram(parent, rows);
    copyText(diagram, 'ASCII copied!');
});

// ── Copy as Markdown / Cisco exports ─────────────────────────────────────────
//
// buildMarkdown(kind, data) and buildCiscoConfig(kind, data) are pure
// (no DOM access). The click handler below extracts data from the existing
// result-row / table DOM nodes and feeds it in.
//
// Cisco output is intentionally generic IOS-style ("interface " stanza +
// "ip address …") — vendor-specific tweaks may be required for non-Cisco
// gear (Juniper, Arista, Mikrotik, etc.). This is documented in docs/exports.md
// and surfaced via help_bubble() tooltips next to each Copy as Cisco button.

function _cidrToMask4(cidr) {
    var c = Math.max(0, Math.min(32, parseInt(String(cidr).replace(/^\//, ''), 10) || 0));
    var m = c === 0 ? 0 : (0xFFFFFFFF << (32 - c)) >>> 0;
    return [(m >>> 24) & 0xFF, (m >>> 16) & 0xFF, (m >>> 8) & 0xFF, m & 0xFF].join('.');
}

function _slug(name, idx) {
    var s = String(name || '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    return s || ('Net' + (idx + 1));
}

function buildMarkdown(kind, data) {
    function table(headers, rows) {
        var head = '| ' + headers.join(' | ') + ' |';
        var sep  = '| ' + headers.map(function () { return '---'; }).join(' | ') + ' |';
        var body = rows.map(function (r) { return '| ' + r.join(' | ') + ' |'; }).join('\n');
        return head + '\n' + sep + '\n' + body + '\n';
    }
    if (kind === 'ipv4' || kind === 'ipv6') {
        var rows = data.rows.map(function (r) { return [r.label, r.value]; });
        return table(['Field', 'Value'], rows);
    }
    if (kind === 'vlsm') {
        var headers = ['Name', 'Hosts Needed', 'Allocated Subnet', 'Usable IPs', 'Waste'];
        return table(headers, data.rows);
    }
    if (kind === 'vlsm6') {
        var headers6 = ['Name', 'Hosts Needed', 'Allocated Subnet', 'Usable Addresses'];
        return table(headers6, data.rows);
    }
    if (kind === 'split4' || kind === 'split6') {
        return table(['#', 'Subnet'], data.subnets.map(function (s, i) { return [i + 1, s]; }));
    }
    return '';
}

function buildCiscoConfig(kind, data) {
    // Generic IOS-style output. NOT a complete config — only the pieces
    // unambiguously implied by the calculator output.
    var lines = ['! Generic Cisco IOS-style configuration',
                 '! Generated by Subnet Calculator — vendor-specific tweaks may apply',
                 ''];
    if (kind === 'ipv4') {
        var parts = String(data.cidr).split('/');
        var ip   = parts[0];
        var mask = _cidrToMask4(parts[1] || '0');
        lines.push('interface GigabitEthernet0/0');
        lines.push(' description ' + (data.description || 'Subnet Calculator export'));
        lines.push(' ip address ' + ip + ' ' + mask);
        lines.push(' no shutdown');
        lines.push('!');
        return lines.join('\n') + '\n';
    }
    if (kind === 'ipv6') {
        lines.push('interface GigabitEthernet0/0');
        lines.push(' description ' + (data.description || 'Subnet Calculator export'));
        lines.push(' ipv6 address ' + data.cidr);
        lines.push(' no shutdown');
        lines.push('!');
        return lines.join('\n') + '\n';
    }
    if (kind === 'vlsm') {
        data.allocations.forEach(function (a, i) {
            var p = String(a.subnet).split('/');
            var first = a.firstUsable || p[0];
            var mask  = _cidrToMask4(p[1] || '0');
            lines.push('interface GigabitEthernet0/' + i);
            lines.push(' description ' + _slug(a.name, i));
            lines.push(' ip address ' + first + ' ' + mask);
            lines.push(' no shutdown');
            lines.push('!');
        });
        return lines.join('\n') + '\n';
    }
    if (kind === 'vlsm6') {
        data.allocations.forEach(function (a, i) {
            lines.push('interface GigabitEthernet0/' + i);
            lines.push(' description ' + _slug(a.name, i));
            lines.push(' ipv6 address ' + a.subnet);
            lines.push(' no shutdown');
            lines.push('!');
        });
        return lines.join('\n') + '\n';
    }
    if (kind === 'split4') {
        data.subnets.forEach(function (s, i) {
            var p = String(s).split('/');
            var mask = _cidrToMask4(p[1] || '0');
            lines.push('interface GigabitEthernet0/' + i);
            lines.push(' description Split-' + (i + 1));
            lines.push(' ip address ' + p[0] + ' ' + mask);
            lines.push(' no shutdown');
            lines.push('!');
        });
        return lines.join('\n') + '\n';
    }
    if (kind === 'split6') {
        data.subnets.forEach(function (s, i) {
            lines.push('interface GigabitEthernet0/' + i);
            lines.push(' description Split-' + (i + 1));
            lines.push(' ipv6 address ' + s);
            lines.push(' no shutdown');
            lines.push('!');
        });
        return lines.join('\n') + '\n';
    }
    return '';
}

function _collectIPv4Data() {
    var panel = document.getElementById('panel-ipv4');
    if (!panel) return null;
    var rows = [];
    panel.querySelectorAll('.results .result-row').forEach(function (r) {
        var label = (r.querySelector('.result-label')?.textContent || '').trim();
        var value = (r.querySelector('.result-value')?.textContent || '').trim();
        if (label && value) rows.push({ label: label, value: value });
    });
    var cidr = '';
    rows.forEach(function (r) { if (/^Subnet/i.test(r.label)) cidr = r.value; });
    return { rows: rows, cidr: cidr, description: 'IPv4 ' + cidr };
}

function _collectIPv6Data() {
    var panel = document.getElementById('panel-ipv6');
    if (!panel) return null;
    var rows = [];
    panel.querySelectorAll('.results .result-row').forEach(function (r) {
        var label = (r.querySelector('.result-label')?.textContent || '').trim();
        var value = (r.querySelector('.result-value')?.textContent || '').trim();
        if (label && value) rows.push({ label: label, value: value });
    });
    var cidr = '';
    rows.forEach(function (r) { if (/^Network/i.test(r.label)) cidr = r.value; });
    return { rows: rows, cidr: cidr, description: 'IPv6 ' + cidr };
}

function _collectVlsmData(v6) {
    var sel = v6 ? '.vlsm6-table' : '.vlsm-table:not(.vlsm6-table)';
    var table = document.querySelector(sel);
    if (!table) return null;
    var rows = [];
    var allocations = [];
    table.querySelectorAll('tbody tr').forEach(function (tr) {
        var cells = tr.querySelectorAll('td');
        var name      = (cells[0]?.textContent || '').trim();
        var hostsNeed = (cells[1]?.textContent || '').trim();
        var subnet    = (tr.querySelector('.vlsm-subnet-cell code')?.textContent || '').trim();
        var usable    = (cells[3]?.textContent || '').trim();
        if (v6) {
            rows.push([name, hostsNeed, subnet, usable]);
            allocations.push({ name: name, subnet: subnet });
        } else {
            var waste = (cells[4]?.textContent || '').trim();
            rows.push([name, hostsNeed, subnet, usable, waste]);
            allocations.push({
                name: name,
                subnet: subnet,
                firstUsable: (tr.dataset.first || '').trim(),
            });
        }
    });
    return { rows: rows, allocations: allocations };
}

function _collectSplitData(panelId) {
    var panel = document.getElementById(panelId);
    if (!panel) return null;
    var subnets = [];
    panel.querySelectorAll('.split-list .split-item[data-copy]').forEach(function (item) {
        subnets.push(item.dataset.copy);
    });
    return { subnets: subnets };
}

document.addEventListener('click', function (e) {
    var btn = e.target.closest('.copy-md-btn, .copy-cisco-btn');
    if (!btn) return;
    var isCisco = btn.classList.contains('copy-cisco-btn');
    var target  = btn.dataset.target;
    var data    = null;
    var kind    = target;

    if (target === 'ipv4')        data = _collectIPv4Data();
    else if (target === 'ipv6')   data = _collectIPv6Data();
    else if (target === 'vlsm')   data = _collectVlsmData(false);
    else if (target === 'vlsm6')  data = _collectVlsmData(true);
    else if (target === 'split4') data = _collectSplitData('panel-ipv4');
    else if (target === 'split6') data = _collectSplitData('panel-ipv6');

    if (!data) { showToast('No data to copy'); return; }

    var output = isCisco ? buildCiscoConfig(kind, data) : buildMarkdown(kind, data);
    if (!output) { showToast('Nothing to copy'); return; }
    copyText(output, isCisco ? 'Cisco config copied!' : 'Markdown copied!');
});

// ── Service Worker registration ───────────────────────────────────────────
if (window.self === window.top && 'serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {});
}

// ── v3.0.0 (#300) keyboard shortcut overlay + (#301) history pane ─────────
(function () {
    const TAB_KEY_MAP = { '1': 'tab-ipv4', '2': 'tab-ipv6', '3': 'tab-vlsm', '4': 'tab-vlsm6' };
    const HISTORY_ENABLED_KEY = 'sc.history.enabled';
    const HISTORY_ENTRIES_KEY = 'sc.history.entries';
    const HISTORY_CAP = 50;

    const kbdOverlay = document.getElementById('kbd-overlay');
    const historyOverlay = document.getElementById('history-overlay');
    const kbdToggle = document.getElementById('kbd-help-toggle');
    const historyToggle = document.getElementById('history-toggle');
    const historyEnabledToggle = document.getElementById('history-enabled-toggle');
    const historyClearBtn = document.getElementById('history-clear');
    const historyList = document.getElementById('history-list');
    const historyEmptyMsg = document.getElementById('history-empty-msg');
    const historyDisabledMsg = document.getElementById('history-disabled-msg');

    if (!kbdOverlay || !historyOverlay) return;

    function isEditableTarget(el) {
        if (!el) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    function activeTabId() {
        const active = document.querySelector('.tab-btn.active');
        return active ? active.id : null;
    }

    function openOverlay(overlay) {
        // Close the other overlay first so only one is visible at a time.
        [kbdOverlay, historyOverlay].forEach(o => { if (o !== overlay) o.hidden = true; });
        overlay.hidden = false;
        const closeBtn = overlay.querySelector('.modal-close');
        if (closeBtn) closeBtn.focus();
    }

    function closeOverlays() {
        kbdOverlay.hidden = true;
        historyOverlay.hidden = true;
    }

    function isAnyOverlayOpen() {
        return !kbdOverlay.hidden || !historyOverlay.hidden;
    }

    [kbdOverlay, historyOverlay].forEach(overlay => {
        overlay.addEventListener('click', e => { if (e.target === overlay) closeOverlays(); });
        const btn = overlay.querySelector('.modal-close');
        if (btn) btn.addEventListener('click', closeOverlays);
    });

    if (kbdToggle) kbdToggle.addEventListener('click', () => openOverlay(kbdOverlay));
    if (historyToggle) historyToggle.addEventListener('click', () => { renderHistory(); openOverlay(historyOverlay); });

    // ── History storage ──────────────────────────────────────────────────
    function historyEnabled() {
        try { return localStorage.getItem(HISTORY_ENABLED_KEY) === '1'; } catch { return false; }
    }

    function setHistoryEnabled(on) {
        try { localStorage.setItem(HISTORY_ENABLED_KEY, on ? '1' : '0'); } catch (e) { void e; }
    }

    function loadHistory() {
        try {
            const raw = localStorage.getItem(HISTORY_ENTRIES_KEY);
            if (!raw) return [];
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch { return []; }
    }

    function saveHistory(entries) {
        try { localStorage.setItem(HISTORY_ENTRIES_KEY, JSON.stringify(entries)); } catch (e) { void e; }
    }

    function pushHistory(entry) {
        if (!historyEnabled()) return;
        const entries = loadHistory();
        // De-dupe consecutive identical URLs.
        if (entries.length > 0 && entries[entries.length - 1].url === entry.url) return;
        entries.push(entry);
        while (entries.length > HISTORY_CAP) entries.shift();
        saveHistory(entries);
    }

    function clearChildren(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function renderHistory() {
        const enabled = historyEnabled();
        if (historyEnabledToggle) historyEnabledToggle.checked = enabled;
        const entries = loadHistory();
        clearChildren(historyList);
        historyDisabledMsg.hidden = enabled;
        historyEmptyMsg.hidden = !enabled || entries.length > 0;
        historyClearBtn.hidden = entries.length === 0;
        for (let i = entries.length - 1; i >= 0; i--) {
            const entry = entries[i];
            const li = document.createElement('li');
            li.className = 'history-item';
            const badge = document.createElement('span');
            badge.className = 'history-tab-badge';
            badge.textContent = entry.tab || '?';
            const link = document.createElement('button');
            link.type = 'button';
            link.className = 'history-link';
            link.textContent = entry.label || entry.url;
            link.title = entry.url;
            link.addEventListener('click', () => { window.location.assign(entry.url); });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'history-remove';
            remove.setAttribute('aria-label', 'Remove from history');
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                const all = loadHistory();
                all.splice(i, 1);
                saveHistory(all);
                renderHistory();
            });
            li.append(badge, link, remove);
            historyList.appendChild(li);
        }
    }

    if (historyEnabledToggle) {
        historyEnabledToggle.addEventListener('change', () => {
            setHistoryEnabled(historyEnabledToggle.checked);
            renderHistory();
        });
    }

    if (historyClearBtn) {
        historyClearBtn.addEventListener('click', () => {
            saveHistory([]);
            renderHistory();
        });
    }

    function captureCurrentPage() {
        if (!historyEnabled()) return;
        const hasResult = document.querySelector('.results, .vlsm-results, .overlap-result, .split-list');
        if (!hasResult) return;
        const url = window.location.pathname + window.location.search;
        const tabId = activeTabId();
        const tab = tabId ? tabId.replace(/^tab-/, '') : '';
        const labelInput = document.querySelector('.panel.active input[type="text"], .panel.active textarea');
        const label = (labelInput && labelInput.value.trim()) || url;
        pushHistory({ url, tab, label, ts: Date.now() });
    }
    captureCurrentPage();

    // ── Keyboard handler ─────────────────────────────────────────────────
    document.addEventListener('keydown', e => {
        const inEditable = isEditableTarget(e.target);

        if (e.key === 'Escape' && isAnyOverlayOpen()) {
            closeOverlays();
            e.preventDefault();
            return;
        }

        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'r') {
            const tabId = activeTabId();
            const tabSlug = tabId ? tabId.replace(/^tab-/, '') : '';
            const target = window.location.pathname + (tabSlug ? '?tab=' + encodeURIComponent(tabSlug) : '');
            window.location.assign(target);
            e.preventDefault();
            return;
        }

        if (e.ctrlKey && e.shiftKey && !e.metaKey && !e.altKey && e.key.toLowerCase() === 'c') {
            const firstVal = document.querySelector('.panel.active .result-value');
            if (firstVal) {
                copyText(firstVal.textContent.trim());
                e.preventDefault();
            }
            return;
        }

        if (inEditable) return;

        if (e.key === '?' || (e.shiftKey && e.key === '/')) {
            openOverlay(kbdOverlay);
            e.preventDefault();
            return;
        }

        if (e.key === '/' && !e.shiftKey) {
            const firstInput = document.querySelector('.panel.active input[type="text"], .panel.active input:not([type]), .panel.active textarea');
            if (firstInput) {
                firstInput.focus();
                e.preventDefault();
            }
            return;
        }

        if (Object.prototype.hasOwnProperty.call(TAB_KEY_MAP, e.key)) {
            const btn = document.getElementById(TAB_KEY_MAP[e.key]);
            if (btn) {
                btn.click();
                btn.focus();
                e.preventDefault();
            }
            return;
        }

        if (e.key === 'h' || e.key === 'H') {
            renderHistory();
            openOverlay(historyOverlay);
            e.preventDefault();
        }
    });
})();

// ─── Subnet Tree Editor (#302, v3.0.0) ──────────────────────────────────────
//
// Client-only reducer-style editor.  All state lives in JS; localStorage
// autosave covers reload-recovery; explicit "Save Session" persists via
// POST /api/v1/sessions (type: 'tree') for cross-device share.  See
// docs/superpowers/plans/2026-05-03-v3.0.0-tree-editor-design.md for the
// design lock.  No new runtime deps — plain JS + BigInt for v6 math.

(function () {
    'use strict';

    const root = document.querySelector('.tool-panel[data-tool="tree-editor"]');
    if (!root) { return; }

    const initForm = root.querySelector('#tree-editor-init');
    const initInput = root.querySelector('#tree_editor_cidr');
    const editorEl = root.querySelector('.tree-editor');
    const canvas = root.querySelector('[data-role="canvas"]');
    const statusEl = root.querySelector('[data-role="status"]');
    const splitModal = root.querySelector('[data-role="split-modal"]');
    const renameModal = root.querySelector('[data-role="rename-modal"]');
    const sheet = root.querySelector('[data-role="action-sheet"]');
    const undoBtn = root.querySelector('[data-action="undo"]');
    const redoBtn = root.querySelector('[data-action="redo"]');
    const shareBox = root.querySelector('[data-role="share"]');

    const MAX_UNDO = 50;
    const SHARE_NODE_CAP = 50;
    const AUTOSAVE_DELAY = 300;

    let state = null;       // { root: TreeNode, family: 'ipv4'|'ipv6' }
    let undoStack = [];     // serialized prior states
    let redoStack = [];
    let autosaveTimer = null;
    let pickerTarget = null;   // CIDR string the picker is acting on
    let renameTarget = null;
    let sheetTarget = null;
    let dragSource = null;

    // ── CIDR math (BigInt for unified v4 + v6) ──────────────────────────────

    function cidrFamily(cidr) {
        return cidr.indexOf(':') !== -1 ? 'ipv6' : 'ipv4';
    }

    function cidrParts(cidr) {
        const [ip, pxStr] = cidr.split('/');
        return { ip: ip, prefix: parseInt(pxStr, 10) };
    }

    function ipv4ToBig(ip) {
        const o = ip.split('.').map(function (s) { return parseInt(s, 10); });
        return (BigInt(o[0]) << 24n) | (BigInt(o[1]) << 16n) | (BigInt(o[2]) << 8n) | BigInt(o[3]);
    }

    function bigToIpv4(n) {
        return [
            Number((n >> 24n) & 0xffn),
            Number((n >> 16n) & 0xffn),
            Number((n >> 8n) & 0xffn),
            Number(n & 0xffn)
        ].join('.');
    }

    function ipv6ToBig(ip) {
        // Expand :: and parse 8 hextets.
        const halves = ip.split('::');
        const left = halves[0] ? halves[0].split(':') : [];
        const right = halves.length > 1 && halves[1] ? halves[1].split(':') : [];
        const missing = 8 - left.length - right.length;
        const parts = left.concat(Array(missing).fill('0'), right);
        let n = 0n;
        for (let i = 0; i < 8; i++) {
            n = (n << 16n) | BigInt(parseInt(parts[i] || '0', 16));
        }
        return n;
    }

    function bigToIpv6(n) {
        const parts = [];
        for (let i = 7; i >= 0; i--) {
            parts.push(Number((n >> BigInt(i * 16)) & 0xffffn).toString(16));
        }
        // Compress longest run of zeros.
        let bestStart = -1, bestLen = 0, curStart = -1, curLen = 0;
        for (let i = 0; i < parts.length; i++) {
            if (parts[i] === '0') {
                if (curStart === -1) { curStart = i; }
                curLen++;
                if (curLen > bestLen) { bestStart = curStart; bestLen = curLen; }
            } else { curStart = -1; curLen = 0; }
        }
        if (bestLen >= 2) {
            return parts.slice(0, bestStart).join(':') + '::' + parts.slice(bestStart + bestLen).join(':');
        }
        return parts.join(':');
    }

    function ipToBig(ip, family) { return family === 'ipv6' ? ipv6ToBig(ip) : ipv4ToBig(ip); }
    function bigToIp(n, family) { return family === 'ipv6' ? bigToIpv6(n) : bigToIpv4(n); }
    function familyMaxBits(family) { return family === 'ipv6' ? 128 : 32; }

    function canonicalCidr(cidr, family) {
        const { ip, prefix } = cidrParts(cidr);
        const bits = familyMaxBits(family);
        const n = ipToBig(ip, family);
        const shift = BigInt(bits - prefix);
        const mask = prefix === 0 ? 0n : (((1n << BigInt(prefix)) - 1n) << shift);
        return bigToIp(n & mask, family) + '/' + prefix;
    }

    function splitInto(cidr, count, family) {
        // count must be a power of 2, 2..16.
        const { ip, prefix } = cidrParts(cidr);
        const bits = Math.log2(count);
        if (!Number.isInteger(bits) || bits < 1) { return []; }
        const newPx = prefix + bits;
        if (newPx > familyMaxBits(family)) { return []; }
        const start = ipToBig(ip, family);
        const stride = 1n << BigInt(familyMaxBits(family) - newPx);
        const out = [];
        for (let i = 0; i < count; i++) {
            out.push(bigToIp(start + BigInt(i) * stride, family) + '/' + newPx);
        }
        return out;
    }

    // ── Tree state helpers ──────────────────────────────────────────────────

    function deepClone(o) { return JSON.parse(JSON.stringify(o)); }

    function findNode(node, cidr) {
        if (node.cidr === cidr) { return node; }
        if (node.children) {
            for (let i = 0; i < node.children.length; i++) {
                const r = findNode(node.children[i], cidr);
                if (r) { return r; }
            }
        }
        return null;
    }

    function findParent(node, cidr, parent) {
        if (node.cidr === cidr) { return parent; }
        if (node.children) {
            for (let i = 0; i < node.children.length; i++) {
                const r = findParent(node.children[i], cidr, node);
                if (r !== undefined) { return r; }
            }
        }
        return undefined;
    }

    function countNodes(node) {
        let n = 1;
        if (node.children) {
            node.children.forEach(function (c) { n += countNodes(c); });
        }
        return n;
    }

    // ── Reducer ─────────────────────────────────────────────────────────────

    function pushHistory() {
        undoStack.push(JSON.stringify(state.root));
        if (undoStack.length > MAX_UNDO) { undoStack.shift(); }
        redoStack = [];
        updateUndoButtons();
    }

    function updateUndoButtons() {
        if (undoBtn) { undoBtn.disabled = undoStack.length === 0; }
        if (redoBtn) { redoBtn.disabled = redoStack.length === 0; }
    }

    function dispatch(action) {
        if (!state) { return; }
        const before = JSON.stringify(state.root);
        switch (action.type) {
            case 'SPLIT': {
                const node = findNode(state.root, action.cidr);
                if (!node || (node.children && node.children.length)) { return; }
                const kids = splitInto(node.cidr, action.count, state.family);
                if (!kids.length) { return; }
                pushHistory();
                node.children = kids.map(function (c) { return { cidr: c }; });
                break;
            }
            case 'MERGE': {
                const parent = findParent(state.root, action.cidr, null);
                if (!parent) { return; }
                pushHistory();
                delete parent.children;
                break;
            }
            case 'RENAME': {
                const node = findNode(state.root, action.cidr);
                if (!node) { return; }
                pushHistory();
                if (action.name) { node.name = action.name; } else { delete node.name; }
                if (action.notes) { node.notes = action.notes; } else { delete node.notes; }
                break;
            }
            case 'UNDO': {
                if (!undoStack.length) { return; }
                redoStack.push(JSON.stringify(state.root));
                state.root = JSON.parse(undoStack.pop());
                break;
            }
            case 'REDO': {
                if (!redoStack.length) { return; }
                undoStack.push(JSON.stringify(state.root));
                state.root = JSON.parse(redoStack.pop());
                break;
            }
            case 'LOAD': {
                state.root = action.root;
                undoStack = [];
                redoStack = [];
                break;
            }
            case 'RESET': {
                pushHistory();
                state.root = { cidr: state.root.cidr };
                break;
            }
        }
        if (JSON.stringify(state.root) !== before) {
            updateUndoButtons();
            render();
            scheduleAutosave();
        }
    }

    // ── Autosave (localStorage) ─────────────────────────────────────────────

    function autosaveKey() { return 'sc.tree.draft.' + state.root.cidr; }

    function scheduleAutosave() {
        if (autosaveTimer) { clearTimeout(autosaveTimer); }
        autosaveTimer = setTimeout(function () {
            try {
                localStorage.setItem(autosaveKey(), JSON.stringify(state.root));
                setStatus('Autosaved.');
            } catch (e) { /* quota exceeded — surface silently */ }
        }, AUTOSAVE_DELAY);
    }

    function loadAutosave(rootCidr) {
        try {
            const raw = localStorage.getItem('sc.tree.draft.' + rootCidr);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    function setStatus(msg) {
        if (statusEl) { statusEl.textContent = msg || ''; }
    }

    function render() {
        if (!state) { return; }
        canvas.innerHTML = '';
        canvas.appendChild(renderNode(state.root, 0, true));
    }

    function renderNode(node, depth, isRoot) {
        const wrap = document.createElement('div');
        wrap.className = 'tree-editor-node' + (isRoot ? ' tree-editor-node-root' : '');
        wrap.setAttribute('data-cidr', node.cidr);
        wrap.style.setProperty('--depth', depth);

        const card = document.createElement('div');
        card.className = 'tree-editor-card';
        card.setAttribute('tabindex', '0');
        card.setAttribute('role', 'button');
        card.setAttribute('draggable', 'true');

        const cidrSpan = document.createElement('code');
        cidrSpan.className = 'tree-editor-cidr';
        cidrSpan.textContent = node.cidr;
        card.appendChild(cidrSpan);

        if (node.name) {
            const nameSpan = document.createElement('span');
            nameSpan.className = 'tree-editor-name';
            nameSpan.textContent = node.name;
            card.appendChild(nameSpan);
        }
        if (node.notes) {
            const notesSpan = document.createElement('span');
            notesSpan.className = 'tree-editor-notes';
            notesSpan.textContent = node.notes;
            card.appendChild(notesSpan);
        }

        // Pencil icon (rename)
        const pencil = document.createElement('button');
        pencil.type = 'button';
        pencil.className = 'tree-editor-pencil';
        pencil.setAttribute('aria-label', 'Rename ' + node.cidr);
        pencil.setAttribute('data-rename', node.cidr);
        pencil.textContent = '✎';
        card.appendChild(pencil);

        wrap.appendChild(card);

        if (node.children && node.children.length) {
            const kidsWrap = document.createElement('div');
            kidsWrap.className = 'tree-editor-children';
            node.children.forEach(function (c) {
                kidsWrap.appendChild(renderNode(c, depth + 1, false));
            });
            wrap.appendChild(kidsWrap);
        }
        return wrap;
    }

    // ── Modal helpers ──────────────────────────────────────────────────────

    function openModal(el) { if (el) { el.hidden = false; } }
    function closeModal(el) { if (el) { el.hidden = true; } }

    function openSplitPicker(cidr) {
        pickerTarget = cidr;
        const cidrSpan = splitModal.querySelector('[data-role="split-cidr"]');
        if (cidrSpan) { cidrSpan.textContent = cidr; }
        openModal(splitModal);
    }

    function openRenameModal(cidr) {
        renameTarget = cidr;
        const node = findNode(state.root, cidr);
        if (!node) { return; }
        renameModal.querySelector('[data-role="rename-cidr"]').textContent = cidr;
        renameModal.querySelector('[data-role="rename-name"]').value = node.name || '';
        renameModal.querySelector('[data-role="rename-notes"]').value = node.notes || '';
        openModal(renameModal);
    }

    function openSheet(cidr) {
        sheetTarget = cidr;
        sheet.querySelector('[data-role="sheet-cidr"]').textContent = cidr;
        openModal(sheet);
    }

    // ── Exports ────────────────────────────────────────────────────────────

    function flatten(node, out) {
        out.push(node);
        if (node.children) { node.children.forEach(function (c) { flatten(c, out); }); }
        return out;
    }

    function leafCidrs() {
        return flatten(state.root, []).filter(function (n) { return !n.children || !n.children.length; }).map(function (n) { return n.cidr; });
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function () {});
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* fallback failed */ }
            document.body.removeChild(ta);
        }
    }

    function exportCidrList() { return leafCidrs().join('\n'); }

    function exportMarkdown() {
        const lines = ['# Subnet Plan: ' + state.root.cidr, ''];
        function walk(node, depth) {
            const indent = '  '.repeat(depth);
            const label = node.name ? ' — ' + node.name : '';
            lines.push(indent + '- `' + node.cidr + '`' + label);
            if (node.notes) { lines.push(indent + '  > ' + node.notes); }
            if (node.children) { node.children.forEach(function (c) { walk(c, depth + 1); }); }
        }
        walk(state.root, 0);
        return lines.join('\n');
    }

    function exportCisco() {
        return leafCidrs().map(function (c) {
            const [ip, px] = c.split('/');
            return 'interface XX\n ip address ' + ip + ' /' + px;
        }).join('\n!\n');
    }

    function exportCsv() {
        const rows = [['cidr', 'name', 'notes', 'is_leaf']];
        flatten(state.root, []).forEach(function (n) {
            rows.push([
                n.cidr,
                n.name || '',
                (n.notes || '').replace(/"/g, '""'),
                (!n.children || !n.children.length) ? '1' : '0'
            ]);
        });
        return rows.map(function (r) {
            return r.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(',');
        }).join('\n');
    }

    function exportJson() {
        return JSON.stringify({ type: 'tree', root: state.root }, null, 2);
    }

    function downloadFile(name, mime, body) {
        const blob = new Blob([body], { type: mime });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    function base64UrlEncode(s) {
        return btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function base64UrlDecode(s) {
        const pad = s + '==='.slice((s.length + 3) % 4);
        return decodeURIComponent(escape(atob(pad.replace(/-/g, '+').replace(/_/g, '/'))));
    }

    function shareUrl() {
        const total = countNodes(state.root);
        if (total > SHARE_NODE_CAP) {
            return null;
        }
        const enc = base64UrlEncode(JSON.stringify(state.root));
        const u = new URL(window.location.href);
        u.searchParams.set('tab', 'ipv4');
        u.searchParams.set('tree', enc);
        return u.toString();
    }

    function showShare(url) {
        const out = root.querySelector('[data-role="share-url"]');
        if (out) { out.textContent = url; }
        if (shareBox) { shareBox.hidden = false; }
    }

    function saveSession() {
        try { tree_validate_client(state.root, state.family); }
        catch (e) { setStatus('Cannot save: ' + e.message); return; }
        fetch('api/v1/sessions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ payload: { type: 'tree', root: state.root } })
        }).then(function (r) { return r.json(); })
            .then(function (json) {
                if (json && json.ok && json.data && json.data.id) {
                    const u = new URL(window.location.href);
                    u.searchParams.set('tab', 'ipv4');
                    u.searchParams.set('session_id', json.data.id);
                    showShare(u.toString());
                    setStatus('Saved as session ' + json.data.id);
                } else {
                    setStatus('Save failed: ' + ((json && json.error) || 'unknown error'));
                }
            }).catch(function (e) { setStatus('Save failed: ' + e.message); });
    }

    // Mirror of server tree_validate() — minimal client-side checks before
    // POSTing.  Server is the authority; this is for UX.
    function tree_validate_client(node, family) {
        function visit(n, parent, depth) {
            if (depth > 16) { throw new Error('depth exceeds 16'); }
            if (!n.cidr) { throw new Error('node missing cidr'); }
            if (n.name && n.name.length > 128) { throw new Error('name exceeds 128 characters'); }
            if (n.notes && n.notes.length > 1024) { throw new Error('notes exceed 1024 characters'); }
            if (n.children) {
                if (n.children.length < 2) { throw new Error('children must be >=2'); }
                if (n.children.length > 64) { throw new Error('children exceed 64'); }
                n.children.forEach(function (c) { visit(c, n, depth + 1); });
            }
        }
        visit(node, null, 0);
    }

    // ── Wiring ──────────────────────────────────────────────────────────────

    function startEditor(rootCidr) {
        const family = cidrFamily(rootCidr);
        const canon = canonicalCidr(rootCidr, family);
        if (canon !== rootCidr) {
            setStatus('Normalised to ' + canon);
            rootCidr = canon;
        }
        state = { root: { cidr: rootCidr }, family: family };

        const draft = loadAutosave(rootCidr);
        if (draft && draft.cidr === rootCidr) {
            state.root = draft;
            setStatus('Restored from autosave.');
        }

        // ?tree=… overrides autosave on first load.
        const params = new URLSearchParams(window.location.search);
        const treeParam = params.get('tree');
        if (treeParam) {
            try {
                const decoded = JSON.parse(base64UrlDecode(treeParam));
                if (decoded && decoded.cidr === rootCidr) {
                    state.root = decoded;
                    setStatus('Loaded tree from URL.');
                }
            } catch (e) { /* ignore bad share */ }
        }

        initForm.hidden = true;
        editorEl.hidden = false;
        undoStack = [];
        redoStack = [];
        updateUndoButtons();
        render();
    }

    if (initForm) {
        initForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const v = initInput.value.trim();
            if (!v) { return; }
            if (v.indexOf('/') === -1) {
                setStatus('CIDR must include a prefix, e.g. 10.0.0.0/24');
                return;
            }
            startEditor(v);
        });
    }

    // Auto-start if ?tree=… and a session already running on the page,
    // or if a session_id with type=tree was loaded by the server.
    document.addEventListener('DOMContentLoaded', function () {
        const params = new URLSearchParams(window.location.search);
        if (params.get('tree') && initInput && initInput.value) {
            startEditor(initInput.value);
        }
    });

    // Click on a node card → split picker (desktop) or sheet (touch).
    canvas.addEventListener('click', function (e) {
        const pencil = e.target.closest('[data-rename]');
        if (pencil) {
            openRenameModal(pencil.getAttribute('data-rename'));
            return;
        }
        const card = e.target.closest('.tree-editor-card');
        if (!card) { return; }
        const cidr = card.parentElement.getAttribute('data-cidr');
        if (window.matchMedia('(hover: none)').matches) {
            openSheet(cidr);
        } else {
            openSplitPicker(cidr);
        }
    });

    // Drag-merge (desktop only).
    canvas.addEventListener('dragstart', function (e) {
        if (window.matchMedia('(hover: none)').matches) { e.preventDefault(); return; }
        const card = e.target.closest('.tree-editor-card');
        if (!card) { return; }
        dragSource = card.parentElement.getAttribute('data-cidr');
        e.dataTransfer.effectAllowed = 'move';
    });
    canvas.addEventListener('dragover', function (e) {
        if (dragSource) { e.preventDefault(); }
    });
    canvas.addEventListener('drop', function (e) {
        e.preventDefault();
        if (!dragSource) { return; }
        const card = e.target.closest('.tree-editor-card');
        if (!card) { dragSource = null; return; }
        const target = card.parentElement.getAttribute('data-cidr');
        if (target === dragSource) { dragSource = null; return; }
        // Both must share a parent → merge that parent.
        const sourceParent = findParent(state.root, dragSource, null);
        const targetParent = findParent(state.root, target, null);
        if (sourceParent && sourceParent === targetParent) {
            dispatch({ type: 'MERGE', cidr: dragSource });
        } else {
            setStatus('Drag-merge only works between siblings.');
        }
        dragSource = null;
    });

    // Modal interactions.
    splitModal.addEventListener('click', function (e) {
        const into = e.target.closest('[data-split-into]');
        if (into) {
            dispatch({ type: 'SPLIT', cidr: pickerTarget, count: parseInt(into.getAttribute('data-split-into'), 10) });
            closeModal(splitModal);
            return;
        }
        if (e.target.matches('[data-role="split-cancel"]')) { closeModal(splitModal); }
    });

    renameModal.addEventListener('click', function (e) {
        if (e.target.matches('[data-role="rename-save"]')) {
            const name = renameModal.querySelector('[data-role="rename-name"]').value.trim();
            const notes = renameModal.querySelector('[data-role="rename-notes"]').value.trim();
            dispatch({ type: 'RENAME', cidr: renameTarget, name: name, notes: notes });
            closeModal(renameModal);
        } else if (e.target.matches('[data-role="rename-cancel"]')) {
            closeModal(renameModal);
        }
    });

    sheet.addEventListener('click', function (e) {
        const action = e.target.getAttribute && e.target.getAttribute('data-sheet-action');
        if (action === 'split') { closeModal(sheet); openSplitPicker(sheetTarget); }
        else if (action === 'merge') { closeModal(sheet); dispatch({ type: 'MERGE', cidr: sheetTarget }); }
        else if (action === 'rename') { closeModal(sheet); openRenameModal(sheetTarget); }
        else if (e.target.matches('[data-role="sheet-cancel"]')) { closeModal(sheet); }
    });

    // Toolbar buttons.
    root.querySelectorAll('.tree-editor-toolbar [data-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const a = btn.getAttribute('data-action');
            if (a === 'undo') { dispatch({ type: 'UNDO' }); }
            else if (a === 'redo') { dispatch({ type: 'REDO' }); }
            else if (a === 'reset') {
                if (confirm('Reset the tree? This drops all edits but keeps autosave history.')) {
                    dispatch({ type: 'RESET' });
                }
            }
            else if (a === 'save-session') { saveSession(); }
            else if (a === 'copy-cidr') { copyText(exportCidrList()); setStatus('Copied CIDR list.'); }
            else if (a === 'copy-md') { copyText(exportMarkdown()); setStatus('Copied Markdown.'); }
            else if (a === 'copy-cisco') { copyText(exportCisco()); setStatus('Copied Cisco config.'); }
            else if (a === 'download-csv') { downloadFile('subnet-tree.csv', 'text/csv', exportCsv()); }
            else if (a === 'download-json') { downloadFile('subnet-tree.json', 'application/json', exportJson()); }
            else if (a === 'share-url') {
                const u = shareUrl();
                if (!u) {
                    setStatus('Tree too large to share — save as session and share that URL instead.');
                } else {
                    showShare(u);
                    copyText(u);
                    setStatus('Share URL copied.');
                }
            }
        });
    });

    const shareCopyBtn = root.querySelector('[data-action="copy-share"]');
    if (shareCopyBtn) {
        shareCopyBtn.addEventListener('click', function () {
            const u = root.querySelector('[data-role="share-url"]');
            if (u) { copyText(u.textContent || ''); setStatus('Share URL copied.'); }
        });
    }

    // Keyboard: Ctrl/Cmd+Z = undo, +Shift = redo (only when editor is open
    // and focus is inside it, to avoid clobbering page-level shortcuts).
    document.addEventListener('keydown', function (e) {
        if (!state || editorEl.hidden) { return; }
        if (!root.contains(document.activeElement) && document.activeElement !== document.body) { return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
            if (e.shiftKey) { dispatch({ type: 'REDO' }); }
            else { dispatch({ type: 'UNDO' }); }
            e.preventDefault();
        }
    });
})();
