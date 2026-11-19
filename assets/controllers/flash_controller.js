import { Controller } from '@hotwired/stimulus';

/**
 * Auto-dismissing toasts for Symfony flash messages.
 *
 * The flash bag and alert styling stay on the server. A CSS progress bar counts
 * down over the same duration; when its animation ends, this controller removes
 * the toast. Hovering or focusing the stack pauses the CSS animation, so the
 * visible bar and the remaining time can never drift apart.
 */
export default class extends Controller {
    static targets = ['toast'];
    static values = {
        duration: { type: Number, default: 5000 },
    };

    connect() {
        this.element.style.setProperty('--flash-duration', `${this.durationValue}ms`);
        this.onAnimationEnd = (event) => {
            if ('flash-toast-progress' === event.animationName) {
                this.hide(event.target.closest('[data-flash-target="toast"]'));
            }
        };
        this.element.addEventListener('animationend', this.onAnimationEnd);
    }

    disconnect() {
        this.element.removeEventListener('animationend', this.onAnimationEnd);
    }

    dismiss(event) {
        const button = event.target instanceof Element ? event.target : null;
        this.hide(button?.closest('[data-flash-target="toast"]'));
    }

    hide(toast) {
        if (!toast || toast.classList.contains('flash-toast--leaving')) {
            return;
        }

        toast.classList.add('flash-toast--leaving');
        // The timeout also covers prefers-reduced-motion, where the leave
        // transition is disabled and no transitionend event would fire.
        setTimeout(() => toast.remove(), 250);
    }
}
