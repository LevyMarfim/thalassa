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
const COLOR = '#3b82f6';

/**
 * One currency's monthly money totals, with a client-side operation-type
 * picker. The server sends every type's full-history series; switching buttons
 * only swaps the visible dataset, so there is no round-trip.
 */
export default class extends Controller {
    static values = {
        data: Object,
    };
    static targets = ['canvas', 'typeButton', 'summary'];

    connect() {
        this.series = this.dataValue.types ?? [];
        this.selected = this.series[0]?.type ?? null;
        this.chart = new Chart(this.canvasTarget, this.config());
        this.syncButtons();
        this.updateSummary();
    }

    disconnect() {
        this.chart?.destroy();
        this.chart = null;
    }

    selectType(event) {
        const type = event.params.type;
        if (!this.series.some((item) => item.type === type)) {
            return;
        }

        this.selected = type;
        this.chart.data.datasets[0].data = this.values();
        this.chart.data.datasets[0].label = type;
        // Drop a tooltip pinned to a point that is no longer visible.
        this.chart.setActiveElements([]);
        this.chart.tooltip?.setActiveElements?.([], { x: 0, y: 0 });
        this.chart.update();

        this.syncButtons();
        this.updateSummary();
    }

    current() {
        return this.series.find((item) => item.type === this.selected) ?? this.series[0];
    }

    values() {
        return this.current()?.values ?? [];
    }

    syncButtons() {
        this.typeButtonTargets.forEach((button) => {
            const pressed = button.dataset.monthlyTypeChartTypeParam === this.selected;
            button.setAttribute('aria-pressed', String(pressed));
        });
    }

    updateSummary() {
        const current = this.current();
        if (!current) {
            return;
        }

        const symbol = this.dataValue.symbol ?? '';
        this.summaryTarget.textContent = `${current.type} · ${symbol} ${MONEY.format(current.total)}`;
    }

    config() {
        const data = this.dataValue;
        const format = (value) => `${data.symbol ?? ''} ${MONEY.format(value)}`.trim();

        return {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: this.selected,
                    data: this.values(),
                    borderColor: COLOR,
                    backgroundColor: 'rgba(59, 130, 246, 0.14)',
                    pointBackgroundColor: COLOR,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
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
                            label: (item) => ` ${item.dataset.label}: ${format(item.parsed.y)}`,
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
}
