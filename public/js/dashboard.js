/* Dashboard filters and read-only tables. */
jQuery(function ($) {
    const dashboard = document.querySelector('[data-dashboard]');
    if (!dashboard) return;

    const {currency, decimals, dateFormat, saleUrl, purchaseUrl} = dashboard.dataset;
    const locale = currency === '₹' ? 'en-IN' : document.documentElement.lang || 'en';
    const exact = new Intl.NumberFormat(locale, {minimumFractionDigits: Number(decimals), maximumFractionDigits: Number(decimals)});
    const compact = new Intl.NumberFormat(locale, {notation: 'compact', maximumFractionDigits: 2});
    const money = value => Number.isFinite(Number(value)) && value !== null ? currency + exact.format(value) : '—';

    function setMetric(element, value) {
        element.title = money(value);
        element.textContent = Math.abs(value) >= 10000000 ? currency + compact.format(value) : element.title;
    }

    dashboard.querySelectorAll('[data-metric]').forEach(element => setMetric(element, element.dataset.amount));
    dashboard.querySelectorAll('.dash-filters select').forEach(select => {
        select.addEventListener('change', () => select.form.requestSubmit());
    });

    function addCell(row, text, className) {
        const cell = row.insertCell();
        cell.textContent = text ?? '—';
        if (className) cell.className = className;
        return cell;
    }

    function loadTable(table, url, renderRow, {loading, empty, error}) {
        const body = table.tBodies[0];
        function showMessage(message) {
            const row = document.createElement('tr');
            const cell = addCell(row, message, 'dash-table-message');
            cell.colSpan = table.tHead.querySelectorAll('th:not([hidden])').length;
            cell.setAttribute('role', 'status');
            body.replaceChildren(row);
        }

        showMessage(loading);
        return $.getJSON(url).done(items => {
            if (!Array.isArray(items) || !items.length) {
                showMessage(Array.isArray(items) ? empty : error);
                return;
            }
            const rows = document.createDocumentFragment();
            items.forEach((item, index) => {
                const row = document.createElement('tr');
                renderRow(row, item, index);
                rows.append(row);
            });
            body.replaceChildren(rows);
        }).fail((_, status) => {
            if (status !== 'abort') showMessage(error);
        });
    }

    function dateLabel(value) {
        if (!value) return '—';
        const [Y, m, d] = value.slice(0, 10).split('-');
        return dateFormat.replace(/[Ymd]/g, part => ({Y, m, d})[part]);
    }

    const activityType = dashboard.querySelector('#dash-activity-kind');
    const activity = dashboard.querySelector('#dash-activity-table');
    const saleStatuses = {1: ['Completed', 'success'], 2: ['Pending', 'warning']};
    const purchaseStatuses = {1: ['Received', 'success'], 2: ['Partial', 'warning'], 3: ['Pending', 'warning']};
    const statusFor = {
        sale: item => saleStatuses[item.sale_status] ?? ['Draft', 'info'],
        purchase: item => purchaseStatuses[item.status] ?? ['Ordered', 'info'],
        quotation: item => Number(item.quotation_status) === 1 ? ['Pending', 'warning'] : ['Sent', 'info'],
        payment: item => [item.paying_method ?? '—', 'info'],
    };
    let activityRequest;

    function loadActivity() {
        activityRequest?.abort();
        const kind = activityType.value;
        const payment = kind === 'payment';
        const party = activity.querySelector('[data-activity-party]');
        party.hidden = payment;
        party.textContent = kind === 'purchase' ? 'Supplier' : 'Customer';
        activity.querySelector('[data-activity-status]').textContent = payment ? 'Payment method' : 'Status';

        activityRequest = loadTable(activity, activityType.selectedOptions[0].dataset.url, (row, item) => {
            const reference = addCell(row, payment ? item.payment_reference : item.reference_no, 'dash-reference');
            const register = {sale: saleUrl, purchase: purchaseUrl}[kind];
            if (register && item.id) {
                const link = document.createElement('a');
                const url = new URL(register, window.location.href);
                url.searchParams.set('view_id', item.id);
                link.href = url.href;
                link.textContent = reference.textContent;
                reference.replaceChildren(link);
            }
            addCell(row, dateLabel(item.business_date || item.created_at));
            if (!payment) addCell(row, item.name);
            const [status, tone] = statusFor[kind](item);
            const pill = document.createElement('span');
            pill.className = 'dash-status dash-tone-' + tone;
            pill.textContent = status;
            row.insertCell().append(pill);
            const amount = (payment ? item.amount : item.grand_total) / (Number(item.exchange_rate) || 1);
            const amountCell = addCell(row, '', 'dash-number font-weight-bold');
            const flowBadge = document.createElement('span');
            const isInflow = kind === 'sale' || (kind === 'payment' && (Number(item.sale_id) > 0 || !item.purchase_id));
            flowBadge.className = 'dash-flow-badge ' + (isInflow ? 'dash-flow-inflow' : 'dash-flow-outflow') + ' mr-2';
            flowBadge.innerHTML = isInflow ? '<i class="dripicons-arrow-thin-down" aria-hidden="true"></i> In' : '<i class="dripicons-arrow-thin-up" aria-hidden="true"></i> Out';
            const amtText = document.createElement('span');
            amtText.textContent = money(amount);
            amountCell.append(flowBadge, amtText);
        }, {
            loading: 'Loading transactions…',
            empty: 'No recent transactions of this type.',
            error: 'Could not load recent transactions.',
        });
    }

    activityType.addEventListener('change', loadActivity);
    loadActivity();

    dashboard.querySelectorAll('[data-ranking-url]').forEach(table => {
        const byValue = table.hasAttribute('data-ranking-value');
        loadTable(table, table.dataset.rankingUrl, (row, item, index) => {
            if (index === 0) {
                table.__maxVal = Number(byValue ? item.total_price : item.sold_qty) || 1;
            }
            const product = addCell(row, '');
            const badge = document.createElement('span');
            badge.className = 'dash-rank-badge' + (index < 3 ? ' dash-rank-badge-' + (index + 1) : '');
            badge.textContent = index + 1;
            const textWrap = document.createElement('div');
            textWrap.className = 'd-inline-flex flex-column flex-grow-1';
            const name = document.createElement('span');
            name.textContent = item.product_name;
            const code = document.createElement('small');
            code.textContent = item.product_code;

            const barWrap = document.createElement('div');
            barWrap.className = 'dash-leaderboard-bar-wrap';
            const barFill = document.createElement('div');
            barFill.className = 'dash-leaderboard-bar-fill';
            const curVal = Number(byValue ? item.total_price : item.sold_qty) || 0;
            const pct = Math.min(100, Math.max(6, Math.round((curVal / (table.__maxVal || 1)) * 100)));
            barFill.style.width = pct + '%';
            barWrap.append(barFill);

            textWrap.append(name, code, barWrap);
            const content = document.createElement('div');
            content.className = 'd-flex align-items-center w-100';
            content.append(badge, textWrap);
            product.append(content);
            addCell(row, byValue ? money(item.total_price) : item.sold_qty, 'dash-number font-weight-bold');
        }, {
            loading: 'Loading best sellers…',
            empty: 'No sales in this period.',
            error: 'Could not load best sellers.',
        });
    });

    // --------------------------------------------------------------------------
    // Dynamic Expandable Quick Navigation Modules Ribbon & Hover-Pan Physics
    // --------------------------------------------------------------------------
    const expandBtn = document.getElementById('dashHeroExpandBtn');
    const tray = document.getElementById('dashHeroModulesTray');
    const viewport = document.getElementById('dashModulesViewport');
    const prevBtn = document.getElementById('dashModulesPrev');
    const nextBtn = document.getElementById('dashModulesNext');

    if (expandBtn && tray && viewport) {
        const STORAGE_KEY = 'zolo_dash_modules_tray_expanded';

        function setExpanded(expanded, save = true) {
            if (expanded) {
                tray.classList.add('is-expanded');
                expandBtn.classList.add('is-active');
                expandBtn.setAttribute('aria-expanded', 'true');
                tray.setAttribute('aria-hidden', 'false');
                const label = expandBtn.querySelector('.dash-hero-expand-label');
                if (label) label.textContent = 'Collapse Modules';
            } else {
                tray.classList.remove('is-expanded');
                expandBtn.classList.remove('is-active');
                expandBtn.setAttribute('aria-expanded', 'false');
                tray.setAttribute('aria-hidden', 'true');
                const label = expandBtn.querySelector('.dash-hero-expand-label');
                if (label) label.textContent = 'Explore Modules';
            }
            if (save) {
                try {
                    localStorage.setItem(STORAGE_KEY, expanded ? '1' : '0');
                } catch (e) {}
            }
        }

        expandBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const isExp = tray.classList.contains('is-expanded');
            setExpanded(!isExp);
        });

        // Restore user preference
        try {
            if (localStorage.getItem(STORAGE_KEY) === '1') {
                setExpanded(true, false);
            }
        } catch (e) {}

        // Interactive Fluid Hover-Pan Animation
        let currentScroll = 0;
        let targetScroll = 0;
        let isHovering = false;
        let isDragging = false;
        let startX = 0;
        let scrollStart = 0;
        let rafId = null;

        function lerp(start, end, factor) {
            return start + (end - start) * factor;
        }

        function step() {
            if (!viewport) return;
            const maxScroll = viewport.scrollWidth - viewport.clientWidth;
            if (maxScroll <= 0) {
                rafId = null;
                return;
            }

            targetScroll = Math.max(0, Math.min(maxScroll, targetScroll));
            currentScroll = lerp(currentScroll, targetScroll, 0.08);

            if (Math.abs(targetScroll - currentScroll) < 0.5) {
                currentScroll = targetScroll;
                viewport.scrollLeft = currentScroll;
                if (!isHovering && !isDragging) {
                    rafId = null;
                    return;
                }
            } else {
                viewport.scrollLeft = currentScroll;
            }

            rafId = requestAnimationFrame(step);
        }

        function requestStep() {
            if (!rafId) {
                rafId = requestAnimationFrame(step);
            }
        }

        // Mousemove calculates relative horizontal position across viewport width
        // Moving mouse right scrolls track left (revealing items on right)
        // Moving mouse left scrolls track right (returning smoothly to start)
        viewport.addEventListener('mousemove', function(e) {
            if (isDragging) return;
            const rect = viewport.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const ratio = Math.max(0, Math.min(1, x / rect.width));
            const maxScroll = viewport.scrollWidth - viewport.clientWidth;
            if (maxScroll > 0) {
                targetScroll = ratio * maxScroll;
                isHovering = true;
                requestStep();
            }
        });

        viewport.addEventListener('mouseenter', function() {
            isHovering = true;
            currentScroll = viewport.scrollLeft;
            requestStep();
        });

        viewport.addEventListener('mouseleave', function() {
            isHovering = false;
        });

        // Horizontal and vertical mousewheel smooth passthrough
        viewport.addEventListener('wheel', function(e) {
            const delta = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
            if (delta !== 0) {
                e.preventDefault();
                const maxScroll = viewport.scrollWidth - viewport.clientWidth;
                targetScroll = Math.max(0, Math.min(maxScroll, targetScroll + delta * 1.5));
                requestStep();
            }
        }, { passive: false });

        // Touch & mouse drag panning support
        viewport.addEventListener('mousedown', function(e) {
            if (e.button !== 0) return;
            isDragging = true;
            startX = e.pageX - viewport.offsetLeft;
            scrollStart = viewport.scrollLeft;
            viewport.style.cursor = 'grabbing';
        });

        window.addEventListener('mouseup', function() {
            if (isDragging) {
                isDragging = false;
                viewport.style.cursor = 'grab';
            }
        });

        window.addEventListener('mousemove', function(e) {
            if (!isDragging) return;
            e.preventDefault();
            const x = e.pageX - viewport.offsetLeft;
            const walk = (x - startX) * 1.5;
            const maxScroll = viewport.scrollWidth - viewport.clientWidth;
            targetScroll = Math.max(0, Math.min(maxScroll, scrollStart - walk));
            currentScroll = targetScroll;
            viewport.scrollLeft = currentScroll;
        });

        // Arrow step buttons
        if (prevBtn) {
            prevBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const maxScroll = viewport.scrollWidth - viewport.clientWidth;
                targetScroll = Math.max(0, targetScroll - 300);
                requestStep();
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const maxScroll = viewport.scrollWidth - viewport.clientWidth;
                targetScroll = Math.min(maxScroll, targetScroll + 300);
                requestStep();
            });
        }
    }
});
