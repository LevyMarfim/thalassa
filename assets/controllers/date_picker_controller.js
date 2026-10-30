import { Controller } from '@hotwired/stimulus';
import flatpickr from 'flatpickr';

// The display months, lowercase per the user-facing format (06/jan/2022).
// The same list feeds flatpickr's display, its month-name parse regex, and
// the mask below — they agree by construction.
const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
const MONTHS_DIGITS = MONTHS.map((_, index) => String(index + 1).padStart(2, '0'));

/**
 * Date field: typed input + popup calendar (flatpickr, no jQuery).
 *
 * Two views of one date, both dd/mm/yyyy at heart:
 *
 *  - the VISIBLE input (flatpickr's altInput) shows the month as an
 *    abbreviation — 06/jan/2022 — formatted by `altFormat: 'd/M/Y'`;
 *  - the hidden named input keeps the numeric 06/01/2022 (`dateFormat:
 *    'd/m/Y'`), which is what gets submitted and what Symfony's strict
 *    `dd/MM/yyyy` transformer parses server-side.
 *
 * The server never renders month names: under the app's pt_BR request locale
 * ICU would print "jan." (with a dot), which flatpickr's exact-match name
 * parser cannot read back — so the name lives purely in the display layer.
 *
 * Progressive enhancement: without JavaScript the field is still the plain
 * numeric dd/mm/yyyy text input with server-side validation.
 */
export default class extends Controller {
    connect() {
        this.instance = flatpickr(this.element, {
            // Inlined from flatpickr's official pt locale, with one change:
            // shorthand months are lowercase so the field reads 06/jan/2026,
            // not 06/Jan/2026.
            locale: {
                weekdays: {
                    shorthand: ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
                    longhand: ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'],
                },
                months: {
                    shorthand: MONTHS,
                    longhand: ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
                },
                rangeSeparator: ' até ',
                time_24hr: true,
            },
            altInput: true,
            altInputClass: 'form-input',
            altFormat: 'd/M/Y',
            dateFormat: 'd/m/Y',
            // The default (false) marks the input readonly, killing typing.
            // With true, flatpickr re-normalizes typed values on blur/Enter.
            allowInput: true,
            // Day aria-labels stay numeric: screen readers read "15/10/2026"
            // cleanly regardless of how the visible field spells the month.
            ariaDateFormat: 'd/m/Y',
        });

        // The mask runs on the field the user actually sees.
        this.input = this.instance.altInput;
        this.mask = (event) => {
            const input = event.target;
            // Deleting renders plain numbers so backspace into a month name
            // ("06/ja") folds back to digits instead of trapping the name.
            const deleting = (event.inputType ?? '').startsWith('delete');

            const caret = input.selectionStart ?? input.value.length;
            const digitsBeforeCaret = toDigits(input.value.slice(0, caret)).length;
            const digits = toDigits(input.value).slice(0, 8);

            const day = digits.slice(0, 2);
            const monthDigits = digits.slice(2, 4);
            const year = digits.slice(4);
            const monthNumber = Number.parseInt(monthDigits, 10);
            const monthName =
                !deleting && monthDigits.length === 2 && monthNumber >= 1 && monthNumber <= 12
                    ? MONTHS[monthNumber - 1]
                    : null;

            // Piecewise render: each part records how many digits it stands
            // for (a month name stands for its two digits), so the caret can
            // be placed by digit count even though names contain letters.
            const pieces = [{ text: day, digits: day.length }];
            if (digits.length > 2) {
                pieces.push({ text: '/', digits: 0 });
                pieces.push({ text: monthName ?? monthDigits, digits: monthName ? 2 : monthDigits.length });
                if (year.length > 0) {
                    pieces.push({ text: '/', digits: 0 });
                    pieces.push({ text: year, digits: year.length });
                }
            }

            input.value = pieces.map((piece) => piece.text).join('');

            let position = 0;
            let seen = 0;
            for (const piece of pieces) {
                if (digitsBeforeCaret <= seen + piece.digits) {
                    const offset = Math.max(digitsBeforeCaret - seen, 0);
                    // Land after the whole month name for its second digit:
                    // every prefix cutting "jan" folds to two month digits.
                    position += offset === piece.digits && piece.text.length > piece.digits
                        ? piece.text.length
                        : Math.min(offset, piece.text.length);
                    break;
                }
                seen += piece.digits;
                position += piece.text.length;
            }
            input.setSelectionRange(position, position);
        };
        this.input.addEventListener('input', this.mask);
    }

    disconnect() {
        this.input?.removeEventListener('input', this.mask);
        this.instance?.destroy();
    }
}

/**
 * Canonical digit stream for the field: month names (the display format,
 * including a partial "ja" left by backspace) fold back into their digits,
 * everything else non-numeric drops out.
 */
function toDigits(value) {
    return value
        .replace(/[a-zA-Z]+/g, (token) => {
            const letters = token.toLowerCase();
            const exact = MONTHS.indexOf(letters);
            if (exact !== -1) {
                return MONTHS_DIGITS[exact];
            }
            const partial = MONTHS.findIndex((month) => month.startsWith(letters));
            return partial !== -1 ? MONTHS_DIGITS[partial] : '';
        })
        .replace(/\D/g, '');
}
