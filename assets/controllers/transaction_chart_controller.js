import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';

const MONEY = new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const COMPACT = new Intl.NumberFormat('pt-BR', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

const MUTED = '#64748b';
const POSITIVE_RGB = '16, 185, 129';
const NEGATIVE_RGB = '239, 68, 68';
const NEUTRAL_RGB = '148, 163, 184';

/** Month counts offered by the range buttons, with 0 meaning All. */
const RANGES = [0, 3, 6, 12, 24, 36, 60];
const SERIES_KINDS = ['monthly', 'cashflow'];

/**
 * Compact charts for the Transactions page and dashboard:
 *
 *  - monthly credits/debits per currency (grouped bars), plus the same months
 *    as net cash-flow bars (green above zero, red below). The server sends the
 *    whole filtered history; the
 *    3M/6M/1Y/2Y/3Y/5Y/All buttons slice it here, so old movements stay reachable;
 *  - absolute money moved by operation (credits/debits) and by operation type,
 *    one doughnut per currency; both share the payload shape below.
 *
 * There is no crosshair or server round-trip on range changes: Chart.js keeps
 * scaling/tooltips and this controller only swaps the visible arrays.
 */
export default class extends Controller {
    static values = {
        kind: String,
        data: Object,
    };
    static targets = ['canvas', 'rangeButton', 'summary'];

    connect() {
        if (!SERIES_KINDS.includes(this.kindValue)) {
            this.chart = new Chart(this.canvasTarget, this.typeConfig());
            return;
        }

        const data = this.dataValue;
        this.fullLabels = data.labels;
        if (this.isCashFlow()) {
            // Cached payloads may predate the net series; deriving it here keeps
            // the chart correct without a second server round-trip.
            this.fullNet = data.net ?? data.credits.map(
                (credit, index) => Math.round((credit - data.debits[index]) * 100) / 100,
            );
        } else {
            this.fullCredits = data.credits;
            this.fullDebits = data.debits;
        }

        // The builder picks the shortest preset covering the currency's last
        // movement (or All for very old data), so an old ledger opens on data
        // instead of empty bars.
        const preferred = Number(data.defaultRange ?? -1);
        this.rangeParam = RANGES.includes(preferred)
            ? preferred
            : (this.fullLabels.length >= 12 ? 12 : 0);
        this.range = 0 === this.rangeParam ? this.fullLabels.length : this.rangeParam;
        this.sliceHistory();

        this.chart = new Chart(
            this.canvasTarget,
            this.isCashFlow() ? this.cashFlowConfig() : this.monthlyConfig(),
        );
        this.syncRangeButtons();
        this.updateSummary();
    }

    disconnect() {
        this.chart?.destroy();
        this.chart = null;
    }

    showRange(event) {
        if (!SERIES_KINDS.includes(this.kindValue)) {
            return;
        }

        const requested = Number(event.params.range);
        if (!Number.isInteger(requested) || requested < 0) {
            return;
        }

        // 0 is the All button; any larger value is a month count.
        this.rangeParam = requested;
        this.range = 0 === requested
            ? this.fullLabels.length
            : Math.min(requested, this.fullLabels.length);
        this.sliceHistory();

        this.chart.data.labels = this.labels;
        if (this.isCashFlow()) {
            const dataset = this.chart.data.datasets[0];
            dataset.data = this.net;
            // The visible slice changed, so the per-bar colours must change too.
            dataset.backgroundColor = this.cashFlowColors(0.78);
            dataset.borderColor = this.cashFlowColors();
        } else {
            this.chart.data.datasets[0].data = this.credits;
            this.chart.data.datasets[1].data = this.debits;
        }
        // Drop a tooltip pinned to a month that may no longer exist.
        this.chart.setActiveElements([]);
        this.chart.tooltip?.setActiveElements?.([], { x: 0, y: 0 });
        this.chart.update();

        this.syncRangeButtons();
        this.updateSummary();
    }

    isCashFlow() {
        return 'cashflow' === this.kindValue;
    }

    cashFlowColors(alpha = 1) {
        return this.net.map((value) => {
            const rgb = value > 0
                ? POSITIVE_RGB
                : value < 0 ? NEGATIVE_RGB : NEUTRAL_RGB;
            return 1 === alpha ? `rgb(${rgb})` : `rgba(${rgb}, ${alpha})`;
        });
    }

    sliceHistory() {
        this.labels = this.fullLabels.slice(-this.range);
        if (this.isCashFlow()) {
            this.net = this.fullNet.slice(-this.range);
            return;
        }
        this.credits = this.fullCredits.slice(-this.range);
        this.debits = this.fullDebits.slice(-this.range);
    }

    syncRangeButtons() {
        this.rangeButtonTargets.forEach((button) => {
            const pressed = Number(button.dataset.transactionChartRangeParam) === this.rangeParam;
            button.setAttribute('aria-pressed', String(pressed));
        });
    }

    updateSummary() {
        this.summaryTarget.textContent = 0 === this.rangeParam
            ? `All time (${this.months(this.range)})`
            : `Last ${this.rangeParam} months`;
    }

    months(count) {
        return `${count} ${count === 1 ? 'month' : 'months'}`;
    }

    monthlyConfig() {
        const data = this.dataValue;
        const format = (value) => `${data.symbol} ${MONEY.format(value)}`;

        return {
            type: 'bar',
            data: {
                labels: this.labels,
                datasets: [
                    {
                        label: 'Créditos',
                        data: this.credits,
                        backgroundColor: 'rgba(16, 185, 129, 0.78)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 3,
                        maxBarThickness: 12,
                    },
                    {
                        label: 'Débitos',
                        data: this.debits,
                        backgroundColor: 'rgba(239, 68, 68, 0.72)',
                        borderColor: '#ef4444',
                        borderWidth: 1,
                        borderRadius: 3,
                        maxBarThickness: 12,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 350, easing: 'easeOutQuart' },
                layout: { padding: { top: 4 } },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 10,
                            color: MUTED,
                            font: { size: 10 },
                        },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.96)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#f8fafc',
                        footerColor: '#cbd5e1',
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 3,
                        usePointStyle: true,
                        callbacks: {
                            label: (item) => ` ${item.dataset.label}: ${format(item.parsed.y)}`,
                            footer: (items) => {
                                const index = items[0].dataIndex;
                                return `Net: ${format(this.credits[index] - this.debits[index])}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: MUTED, maxRotation: 0, autoSkip: true, maxTicksLimit: 6, font: { size: 10 } },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(148, 163, 184, 0.18)' },
                        ticks: { color: MUTED, font: { size: 10 }, callback: (value) => COMPACT.format(value) },
                    },
                },
            },
        };
    }

    cashFlowConfig() {
        const data = this.dataValue;
        const format = (value) => `${data.symbol} ${MONEY.format(value)}`;

        return {
            type: 'bar',
            data: {
                labels: this.labels,
                datasets: [{
                    label: 'Net cash flow',
                    data: this.net,
                    backgroundColor: this.cashFlowColors(0.78),
                    borderColor: this.cashFlowColors(),
                    borderWidth: 1,
                    borderRadius: 3,
                    maxBarThickness: 16,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                animation: { duration: 350, easing: 'easeOutQuart' },
                layout: { padding: { top: 4 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.96)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#f8fafc',
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 3,
                        usePointStyle: true,
                        callbacks: {
                            label: (item) => ` Net: ${format(item.parsed.y)}`,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: MUTED, maxRotation: 0, autoSkip: true, maxTicksLimit: 6, font: { size: 10 } },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(148, 163, 184, 0.18)' },
                        ticks: { color: MUTED, font: { size: 10 }, callback: (value) => COMPACT.format(value) },
                    },
                },
            },
        };
    }

    typeConfig() {
        const data = this.dataValue;
        const format = (value) => `${data.symbol ?? ''} ${MONEY.format(value)}`.trim();

        return {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: [{
                    data: data.values ?? data.counts,
                    backgroundColor: data.colors,
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 5,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                animation: { duration: 350, easing: 'easeOutQuart' },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 8,
                            color: MUTED,
                            font: { size: 10 },
                        },
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.96)',
                        titleColor: '#e2e8f0',
                        bodyColor: '#f8fafc',
                        padding: 10,
                        cornerRadius: 8,
                        boxPadding: 3,
                        callbacks: {
                            label: (item) => ` ${item.label}: ${format(item.parsed)}`,
                        },
                    },
                },
            },
        };
    }
}
