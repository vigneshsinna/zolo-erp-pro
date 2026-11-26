$(document).ready(function () {

    'use strict';
    var brandPrimary = '#4f46e5';
    var brandPrimaryRgba = 'rgba(79, 70, 229, 0.2)';

    var isDark = $('body').hasClass('dark-mode');
    var gridColor = isDark ? 'rgba(255, 255, 255, 0.06)' : 'rgba(226, 232, 240, 0.6)';
    var tickColor = isDark ? '#64748b' : '#94a3b8';

    var dashboard = document.querySelector('[data-dashboard]');
    if (dashboard) {
        var locale = dashboard.dataset.currency === '₹' ? 'en-IN' : document.documentElement.lang || 'en';
        var currency = dashboard.dataset.currency;
        var compact = new Intl.NumberFormat(locale, {notation: 'compact', maximumFractionDigits: 1});
        var exact = new Intl.NumberFormat(locale, {maximumFractionDigits: Number(dashboard.dataset.decimals)});
        var cash = $('#cashFlow').data();
        var yearly = $('#saleChart').data();
        var monthly = $('#transactionChart').data();
        var charts = [];
        var dark = document.body.classList.contains('dark-mode');

        function drawDashboardCharts() {
            charts.forEach(function (chart) { chart.destroy(); });
            charts = [];
            var theme = getComputedStyle(dashboard);
            var token = function (name) { return theme.getPropertyValue(name).trim(); };
            var plum = token('--z-primary-text');
            var amber = token('--z-warning');
            var green = token('--z-success');
            var grid = token('--z-border');
            var surface = token('--z-surface');
            var font = theme.fontFamily;
            var text = token('--z-text-2');
            var ticks = {fontColor: text, fontFamily: font, fontSize: 11};
            var layout = {responsive: true, maintainAspectRatio: false, animation: {duration: 0}};
            var axes = {
                xAxes: [{gridLines: {display: false}, ticks: ticks}],
                yAxes: [{
                    gridLines: {color: grid, zeroLineColor: grid, drawBorder: false},
                    ticks: Object.assign({}, ticks, {
                        beginAtZero: true,
                        maxTicksLimit: 5,
                        callback: function (value) { return currency + compact.format(value); }
                    })
                }]
            };
            var legend = {labels: Object.assign({}, ticks, {boxWidth: 10, usePointStyle: true})};
            var tooltips = {
                titleFontFamily: font,
                bodyFontFamily: font,
                callbacks: {
                    label: function (item, data) {
                        var series = data.datasets[item.datasetIndex];
                        var label = series.label || data.labels[item.index];
                        return label + ' ' + currency + exact.format(series.data[item.index]);
                    }
                }
            };
            function draw(id, type, labels, datasets, options) {
                var el = document.getElementById(id);
                if (!el) return;
                charts.push(new Chart(el, {
                    type: type,
                    data: {labels: labels, datasets: datasets},
                    options: Object.assign({}, layout, {tooltips: tooltips}, options)
                }));
            }
            function line(label, data, color, fill) {
                return {
                    label: label, data: data, borderColor: color, backgroundColor: fill, borderWidth: 2.5,
                    pointBackgroundColor: surface, pointBorderColor: color, pointBorderWidth: 2,
                    pointRadius: 3.5, pointHoverRadius: 6, pointHitRadius: 10, lineTension: 0.35, fill: true
                };
            }

            if (cash) {
                var cashEl = document.getElementById('cashFlow');
                var fillReceived = token('--z-primary-soft-strong');
                var fillSent = token('--z-warning-soft');
                if (cashEl && cashEl.getContext) {
                    var ctx = cashEl.getContext('2d');
                    var g1 = ctx.createLinearGradient(0, 0, 0, 260);
                    g1.addColorStop(0, 'rgba(125, 40, 126, 0.28)');
                    g1.addColorStop(1, 'rgba(125, 40, 126, 0.0)');
                    fillReceived = g1;

                    var g2 = ctx.createLinearGradient(0, 0, 0, 260);
                    g2.addColorStop(0, 'rgba(217, 119, 6, 0.25)');
                    g2.addColorStop(1, 'rgba(217, 119, 6, 0.0)');
                    fillSent = g2;
                }
                draw('cashFlow', 'line', cash.month, [
                    line(cash.label1, cash.recieved, plum, fillReceived),
                    line(cash.label2, cash.sent, amber, fillSent)
                ], {legend: legend, scales: axes});
            }
            if (yearly) {
                draw('saleChart', 'bar', yearly.month, [
                    {label: yearly.label1, data: yearly.purchase_chart_value, backgroundColor: amber, borderRadius: 4},
                    {label: yearly.label2, data: yearly.sale_chart_value, backgroundColor: plum, borderRadius: 4}
                ], {legend: legend, scales: axes});
            }
            if (monthly) {
                draw('transactionChart', 'doughnut', [monthly.label1, monthly.label2, monthly.label3], [{
                    data: [monthly.purchase, monthly.revenue, monthly.expense],
                    backgroundColor: [plum, green, amber],
                    borderWidth: 2,
                    borderColor: surface
                }], {cutoutPercentage: 72, legend: {display: false}});
            }
        }

        drawDashboardCharts();
        new MutationObserver(function () {
            var nextDark = document.body.classList.contains('dark-mode');
            if (nextDark === dark) return;
            dark = nextDark;
            drawDashboardCharts();
        }).observe(document.body, {attributes: true, attributeFilter: ['class']});
    }

    // ------------------------------------------------------- //
    // Sale Report Line Chart
    // ------------------------------------------------------ //
    var SALEREPORTCHART = $('#sale-report-chart');
    if (SALEREPORTCHART.length > 0) {
        var soldqty = SALEREPORTCHART.data('soldqty');
        var datepoints = SALEREPORTCHART.data('datepoints');
        var label1 = SALEREPORTCHART.data('label1') || 'Sold Qty';

        var ctxReport = SALEREPORTCHART[0].getContext('2d');
        var gradReport = ctxReport.createLinearGradient(0, 0, 0, 260);
        gradReport.addColorStop(0, 'rgba(79, 70, 229, 0.25)');
        gradReport.addColorStop(1, 'rgba(79, 70, 229, 0.00)');

        var sale_report_chart = new Chart(SALEREPORTCHART, {
            type: 'line',
            data: {
                labels: datepoints,
                datasets: [
                    {
                        label: label1,
                        fill: true,
                        lineTension: 0.4,
                        backgroundColor: gradReport,
                        borderColor: '#4f46e5',
                        borderWidth: 2.8,
                        pointBorderColor: '#4f46e5',
                        pointBackgroundColor: "#ffffff",
                        pointBorderWidth: 2.5,
                        pointHoverRadius: 6,
                        pointHoverBackgroundColor: '#4f46e5',
                        pointHoverBorderColor: "#ffffff",
                        pointHoverBorderWidth: 2,
                        pointRadius: 4,
                        pointHitRadius: 10,
                        data: soldqty,
                        spanGaps: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 10,
                        usePointStyle: true,
                        fontFamily: 'Plus Jakarta Sans',
                        fontSize: 12,
                        fontColor: tickColor
                    }
                },
                scales: {
                    xAxes: [{
                        gridLines: { display: false, drawBorder: false },
                        ticks: { fontFamily: 'Plus Jakarta Sans', fontSize: 11, fontColor: tickColor }
                    }],
                    yAxes: [{
                        gridLines: { color: gridColor, drawBorder: false },
                        ticks: { fontFamily: 'JetBrains Mono', fontSize: 11, fontColor: tickColor, beginAtZero: true }
                    }]
                }
            }
        });
    };

    // ------------------------------------------------------- //
    // Best Seller Bar Chart
    // ------------------------------------------------------ //
    var BESTSELLER = $('#bestSeller');
    if (BESTSELLER.length > 0) {
        var sold_qty = BESTSELLER.data('sold_qty');
        var product_info = BESTSELLER.data('product');

        var bestSeller = new Chart(BESTSELLER, {
            type: 'bar',
            data: {
                labels: [ product_info[0], product_info[1], product_info[2] ],
                datasets: [
                    {
                        label: "Sale Qty",
                        backgroundColor: ['#6366f1', '#10b981', '#f59e0b'],
                        hoverBackgroundColor: ['#4f46e5', '#059669', '#d97706'],
                        borderWidth: 0,
                        data: [ sold_qty[0], sold_qty[1], sold_qty[2] ]
                    }
                ]
            },
            options: {
                responsive: true,
                legend: { display: false },
                scales: {
                    xAxes: [{ gridLines: { display: false }, ticks: { fontColor: tickColor } }],
                    yAxes: [{ gridLines: { color: gridColor }, ticks: { fontColor: tickColor, beginAtZero: true } }]
                }
            }
        });
    };

    // ------------------------------------------------------- //
    // Pie Chart
    // ------------------------------------------------------ //
    var PIECHART = $('#pieChart');
    if (PIECHART.length > 0) {
        var price = PIECHART.data('price');
        var cost = PIECHART.data('cost');
        var label1 = PIECHART.data('label1') || 'Price';
        var label2 = PIECHART.data('label2') || 'Cost';
        var label3 = PIECHART.data('label3') || 'Profit';

        var myPieChart = new Chart(PIECHART, {
            type: 'doughnut',
            data: {
                labels: [ label1, label2, label3 ],
                datasets: [{
                    data: [ price, cost, Math.max(0, price - cost) ],
                    backgroundColor: ['#4f46e5', '#ef4444', '#10b981'],
                    hoverBackgroundColor: ['#4338ca', '#dc2626', '#059669'],
                    borderWidth: 0
                }]
            },
            options: {
                cutoutPercentage: 72,
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 10, usePointStyle: true, fontColor: tickColor }
                }
            }
        });
    }
});
