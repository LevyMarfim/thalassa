/**
 * Page-wide chart period sync.
 *
 * A chart that handles a user's range click emits REQUEST. The page's single
 * chart-period controller relays it as APPLY; every chart with a range selector
 * listens for APPLY and re-slices without emitting again. Keeping the names here
 * avoids a typo silently breaking one of the charts.
 */
export const REQUEST = 'chart-period:request';
export const APPLY = 'chart-period:apply';

export function requestPeriod(months) {
    window.dispatchEvent(new CustomEvent(REQUEST, { detail: { months } }));
}

export function applyPeriod(months) {
    window.dispatchEvent(new CustomEvent(APPLY, { detail: { months } }));
}
