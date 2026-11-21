import { Controller } from '@hotwired/stimulus';
import { REQUEST, applyPeriod } from './chart_period_events.js';

/**
 * Attached to any page with more than one range-bearing chart (currently the
 * home dashboard and the Transactions page). It turns one chart's range click
 * into a page-wide period change. Pages without this controller let their
 * charts keep independent ranges.
 */
export default class extends Controller {
    connect() {
        this.onRequest = (event) => this.relay(event);
        window.addEventListener(REQUEST, this.onRequest);
    }

    disconnect() {
        window.removeEventListener(REQUEST, this.onRequest);
    }

    relay(event) {
        const months = Number(event.detail?.months);
        if (!Number.isInteger(months) || months < 0) {
            return;
        }

        applyPeriod(months);
    }
}
