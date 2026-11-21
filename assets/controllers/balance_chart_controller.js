import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';
import { APPLY, requestPeriod } from './chart_period_events.js';

const MONEY = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const COMPACT = new Intl.NumberFormat('pt-BR', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

const MUTED = '#64748b';

/**
 * Vertical guide drawn at the hovered month. Chart.js can draw the points and
 * the tooltip, but not a crosshair, so this tiny plugin adds one at the active
 * element's x. It reads the tooltip's active elements, which means it follows
 * the same `interaction.mode = 'index'` as the tooltip.
 */
const crosshair = {
    id: 'crosshair',
    afterDatasetsDraw(chart) {
        const active = chart.tooltip?.getActiveElements?.() ?? [];
        if (active.length === 0) {
            return;
        }

        const { ctx, chartArea } = chart;
        const x = active[0].element.x;

        ctx.save();
        ctx.beginPath();
        ctx.setLineDash([5, 4]);
        ctx.moveTo(x, chartArea.top);
        ctx.lineTo(x, chartArea.bottom);
        ctx.lineWidth = 1;
        ctx.strokeStyle = 'rgba(148, 163, 184, 0.65)';
        ctx.stroke();
        ctx.restore();
    },
};

/**
 * Month-end balances, one line per account, one canvas per currency.
 *
 * The server sends plain labels + datasets through a data attribute; every
 * interaction happens here:
 *
 *  - hovering anywhere on a month pins a crosshair and shows every account's
 *    value for that month in one tooltip (footer totals them);
 *  - clicking a legend item hides/shows that account;
 *  - the 3M/6M/1Y/2Y/3Y/5Y/All buttons re-slice the same history client-side,
 *    with a transition instead of a reload (only ranges the ledger covers are
 *    rendered). On pages with a period controller the selection is shared with
 *    the other range charts;
 *  - the chart is responsive down to the card width.
 */
export default class extends Controller {
    static values = { data: Object };
    static targets = ['canvas', 'rangeButton', 'summary'];

    connect() {
        this.onPeriodApplied = (event) => this.applyExternalPeriod(event);
        window.addEventListener(APPLY, this.onPeriodApplied);

        this.fullLabels = this.dataValue.labels;
        this.fullDatasets = this.dataValue.datasets;
        this.fullData = this.fullDatasets.map((dataset) => dataset.data);

        // One year is the initial view, matching the server-rendered summary.
        // A ledger shorter than that opens on All instead.
        this.rangeParam = this.fullLabels.length >= 12 ? 12 : 0;
        this.range = this.rangeParam === 0 ? this.fullLabels.length : this.rangeParam;

        const labels = this.fullLabels.slice(-this.range);
        const data = this.fullData.map((values) => values.slice(-this.range));

        this.chart = new Chart(this.canvasTarget, this.buildConfig(labels, data));
        this.syncRangeButtons();
        this.updateSummary();
    }

    disconnect() {
        window.removeEventListener(APPLY, this.onPeriodApplied);
        this.chart?.destroy();
        this.chart = null;
    }

    showRange(event) {
        this.setRange(Number(event.params.range));
    }

    applyExternalPeriod(event) {
        const months = Number(event.detail?.months);
        if (!Number.isInteger(months) || months < 0 || months === this.rangeParam) {
            return;
        }

        // Already broadcast by the page's period controller; don't echo it back.
        this.setRange(months, false);
    }

    setRange(requested, broadcast = true) {
        if (!Number.isInteger(requested) || requested < 0) {
            return;
        }

        // 0 is the All button; any larger value is a month count.
        const range = requested === 0
            ? this.fullLabels.length
            : Math.min(requested, this.fullLabels.length);

        // A window longer than the whole ledger is really All. The balance
        // chart only renders buttons its history covers, so without this the
        // external period could leave no button pressed and overstate the span.
        const clamped = requested > this.fullLabels.length;
        this.rangeParam = clamped ? 0 : requested;
        this.range = range;
        this.chart.data.labels = this.fullLabels.slice(-range);
        this.chart.data.datasets.forEach((dataset, index) => {
            dataset.data = this.fullData[index].slice(-range);
            dataset.pointRadius = this.pointRadiusFor(range);
        });
        // Drop a tooltip pinned to a month that may no longer exist.
        this.chart.setActiveElements([]);
        this.chart.tooltip?.setActiveElements?.([], { x: 0, y: 0 });
        this.chart.update();

        this.syncRangeButtons();
        this.updateSummary();

        if (broadcast) {
            requestPeriod(requested);
        }
    }

    buildConfig(labels, data) {
        const format = (value) => `${this.dataValue.symbol} ${MONEY.format(value)}`;

        return {
            type: 'line',
            data: {
                labels,
                datasets: this.fullDatasets.map((dataset, index) => ({
                    label: dataset.label,
                    data: data[index],
                    borderColor: dataset.color,
                    // Only a lone series gets a fill: overlapping translucent
                    // areas make a multi-account chart harder to read.
                    backgroundColor: this.fillFor(dataset.color),
                    fill: this.fullDatasets.length === 1 ? 'origin' : false,
                    borderWidth: 2,
                    tension: 0.35,
                    // Points appear on hover rather than cluttering the line;
                    // the hit area is still 18px wide, so they are easy to
                    // catch with a mouse or a finger. One or two points get a
                    // visible marker: without a segment there is no line to
                    // see (a new ledger can have a single month).
                    pointRadius: this.pointRadiusFor(labels.length),
                    pointHoverRadius: 5,
                    pointHitRadius: 18,
                    pointBackgroundColor: dataset.color,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointHoverBorderWidth: 2,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 450, easing: 'easeOutQuart' },
                onHover: (event, elements) => {
                    const target = event.native?.target;
                    if (target) {
                        target.style.cursor = elements.length > 0 ? 'pointer' : 'default';
                    }
                },
                layout: { padding: { top: 4 } },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            color: MUTED,
                            font: { size: 11 },
                        },
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(15, 23, 42, 0.96)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#f8fafc',
                        footerColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 8,
                        boxPadding: 4,
                        usePointStyle: true,
                        callbacks: {
                            label: (item) => ` ${item.dataset.label}: ${format(item.parsed.y)}`,
                            footer: (items) => {
                                if (items.length < 2) {
                                    return '';
                                }

                                const total = items.reduce((sum, item) => sum + item.parsed.y, 0);
                                return `Total: ${format(total)}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            color: MUTED,
                            maxRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 12,
                            font: { size: 11 },
                        },
                    },
                    y: {
                        // A debit must sit visibly below zero, so the axis
                        // always starts there instead of zooming into the
                        // current balance range.
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(148, 163, 184, 0.18)' },
                        ticks: {
                            color: MUTED,
                            font: { size: 11 },
                            callback: (value) => COMPACT.format(value),
                        },
                    },
                },
            },
            plugins: [crosshair],
        };
    }

    fillFor(color) {
        // The palette is always #rrggbb, so appending an alpha channel is safe.
        return this.fullDatasets.length === 1 ? `${color}1f` : 'transparent';
    }

    pointRadiusFor(months) {
        // A lone point has no line segment, so it must draw itself.
        return months <= 2 ? 4 : 0;
    }

    syncRangeButtons() {
        this.rangeButtonTargets.forEach((button) => {
            const pressed = Number(button.dataset.balanceChartRangeParam) === this.rangeParam;
            button.setAttribute('aria-pressed', String(pressed));
        });
    }

    updateSummary() {
        const accounts = this.fullDatasets.length;
        const period = this.rangeParam === 0
            ? `All time (${this.months(this.range)})`
            : `Last ${this.months(this.rangeParam)}`;

        this.summaryTarget.textContent =
            `${period} · ` +
            `${accounts} ${accounts === 1 ? 'account' : 'accounts'} · ` +
            'click a legend item to hide a series';
    }

    months(count) {
        return `${count} ${count === 1 ? 'month' : 'months'}`;
    }
}
