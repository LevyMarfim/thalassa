// assets/controllers/security/login_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['password'];

    togglePassword(event) {
        const button = event.currentTarget;
        const input = this.passwordTarget;

        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        // Single source of truth: the button's aria-pressed state.
        // CSS shows/hides the icons based on this attribute.
        button.setAttribute('aria-pressed', String(isPassword));
        button.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    }
}
