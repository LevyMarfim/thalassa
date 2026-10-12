// assets/controllers/password_toggle_controller.js
//
// Shared show/hide password toggle. Wrap the input + button in an element
// carrying data-controller="password-toggle", mark the input with
// data-password-toggle-target="input" and wire the button with
// data-action="click->password-toggle#toggle".
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input'];

    toggle(event) {
        const button = event.currentTarget;
        const input = this.inputTarget;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        // Single source of truth: the button's aria-pressed state.
        // CSS shows/hides the icons based on this attribute.
        button.setAttribute('aria-pressed', String(isPassword));
        button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    }
}
