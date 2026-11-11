import { Controller } from '@hotwired/stimulus';

/*
 * The eye button inside a password field: shows the typed password so a typo can be spotted,
 * and hides it again. The field always goes back to hidden before the form is sent,
 * so the browser never stores the password as plain text in its autofill history.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'button'];

    connect() {
        this.hideBeforeSubmit = () => this.show(false);
        this.inputTarget.form?.addEventListener('submit', this.hideBeforeSubmit);
        this.show(false);
    }

    disconnect() {
        this.inputTarget.form?.removeEventListener('submit', this.hideBeforeSubmit);
    }

    toggle() {
        this.show(this.inputTarget.type === 'password');
        this.inputTarget.focus();
    }

    show(visible) {
        this.inputTarget.type = visible ? 'text' : 'password';
        const label = visible ? 'Hide password' : 'Show password';
        this.buttonTarget.setAttribute('aria-pressed', String(visible));
        this.buttonTarget.setAttribute('aria-label', label);
        this.buttonTarget.title = label;
    }
}
