// assets/controllers/security/registration_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['password', 'strength', 'strengthLabel'];

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

    updateStrength() {
        const value = this.passwordTarget.value ?? '';
        const score = this.#score(value);

        this.strengthTarget.dataset.level = String(score);

        const messages = [
            'Use 6+ characters with a mix of letters, numbers, and symbols.',
            'Weak password — add more characters.',
            'Fair password — consider adding numbers or symbols.',
            'Good password — almost there.',
            'Strong password — great choice!',
        ];

        this.strengthLabelTarget.textContent = messages[score];
    }

    validate(event) {
        // Light client-side validation; server-side validation remains authoritative.
        const email = this.element.querySelector('input[type="email"]');
        const password = this.passwordTarget;

        if (email && !email.checkValidity()) {
            event.preventDefault();
            email.focus();
            return;
        }

        if (password.value.length < 6) {
            event.preventDefault();
            password.focus();
        }
    }

    #score(password) {
        if (!password) return 0;

        let score = 0;
        if (password.length >= 12) score++;
        if (password.length >= 16) score++;
        if (/[A-Z]/.test(password) && /[a-z]/.test(password)) score++;
        if (/\d/.test(password)) score++;
        if (/[^A-Za-z0-9]/.test(password)) score++;

        return Math.min(score, 4);
    }
}