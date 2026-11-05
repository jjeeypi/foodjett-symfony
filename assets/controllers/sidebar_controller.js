import { Controller } from '@hotwired/stimulus';

// Must match the inline script in base.html.twig that restores the choice before the page paints.
const STORAGE_KEY = 'foodjett.sidebar';
const COLLAPSED = 'sidebar-collapsed';

/*
 * The button in the top bar that hides the sidebar down to its icons and brings it back.
 * The choice is remembered in this browser, so it stays the same on every page.
 */
export default class extends Controller {
    connect() {
        if (this.stored() === 'collapsed') {
            document.documentElement.classList.add(COLLAPSED);
        }
        this.sync();
    }

    toggle() {
        const collapsed = document.documentElement.classList.toggle(COLLAPSED);
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? 'collapsed' : 'open');
        } catch {
            // Private windows can refuse storage; the toggle still works for this page.
        }
        this.sync();
    }

    sync() {
        const collapsed = document.documentElement.classList.contains(COLLAPSED);
        const label = collapsed ? 'Show the menu' : 'Hide the menu';
        this.element.setAttribute('aria-expanded', String(!collapsed));
        this.element.setAttribute('aria-label', label);
        this.element.title = label;
    }

    stored() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch {
            return null;
        }
    }
}
