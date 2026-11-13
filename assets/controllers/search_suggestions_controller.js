import { Controller } from '@hotwired/stimulus';

/*
 * Suggestions under a search box: while the customer types, asks the server for the best matching
 * restaurants and dishes and lists them. Arrow keys move through the list, Enter opens the highlighted
 * suggestion (or searches when none is highlighted), Escape closes it. Without JavaScript the box is
 * still a plain search form.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { url: String };

    connect() {
        this.active = -1;
        this.inputTarget.setAttribute('role', 'combobox');
        this.inputTarget.setAttribute('aria-autocomplete', 'list');
        this.inputTarget.setAttribute('aria-controls', this.listTarget.id);
        this.inputTarget.setAttribute('autocomplete', 'off');
        this.leave = (event) => {
            if (!this.element.contains(event.relatedTarget)) {
                this.close();
            }
        };
        this.element.addEventListener('focusout', this.leave);
        this.close();
    }

    disconnect() {
        this.element.removeEventListener('focusout', this.leave);
        clearTimeout(this.timer);
        this.request?.abort();
    }

    query() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 150);
    }

    async load() {
        const query = this.inputTarget.value.trim();
        this.request?.abort();
        if (query.length < 2) {
            this.close();
            return;
        }
        this.request = new AbortController();
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('q', query);
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.request.signal });
            if (response.ok) {
                this.render(await response.json(), query);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.close();
            }
        }
    }

    render(data, query) {
        this.listTarget.replaceChildren();
        this.active = -1;
        this.inputTarget.removeAttribute('aria-activedescendant');
        let count = 0;
        for (const [label, rows] of [['Restaurants', data.restaurants], ['Dishes you can order now', data.dishes]]) {
            if (!rows.length) {
                continue;
            }
            const heading = document.createElement('div');
            heading.className = 'suggestions-label';
            heading.setAttribute('role', 'presentation');
            heading.textContent = label;
            this.listTarget.append(heading);
            for (const row of rows) {
                this.listTarget.append(this.option(row.url, row.name, row.detail, count++));
            }
        }
        if (count === 0) {
            const empty = document.createElement('div');
            empty.className = 'suggestions-empty';
            empty.setAttribute('role', 'presentation');
            empty.textContent = 'No quick matches. Press Enter to search everything.';
            this.listTarget.append(empty);
        } else if (data.all) {
            const all = this.option(data.all, `See all results for “${query}”`, '', count);
            all.classList.add('suggestion-all');
            this.listTarget.append(all);
        }
        this.listTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    option(href, name, detail, index) {
        const link = document.createElement('a');
        link.className = 'suggestion';
        link.href = href;
        link.id = `${this.listTarget.id}-${index}`;
        link.setAttribute('role', 'option');
        link.setAttribute('aria-selected', 'false');
        link.tabIndex = -1;
        const title = document.createElement('strong');
        title.textContent = name;
        link.append(title);
        if (detail) {
            const small = document.createElement('small');
            small.textContent = detail;
            link.append(small);
        }
        return link;
    }

    get options() {
        return [...this.listTarget.querySelectorAll('[role="option"]')];
    }

    navigate(event) {
        const options = this.options;
        if (event.key === 'Escape') {
            this.close();
            return;
        }
        if (this.listTarget.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const next = this.active < 0 ? (step === 1 ? 0 : options.length - 1) : (this.active + step + options.length) % options.length;
            this.highlight(options, next);
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            options[this.active].click();
        }
    }

    highlight(options, index) {
        options.forEach((option, i) => option.setAttribute('aria-selected', String(i === index)));
        this.active = index;
        this.inputTarget.setAttribute('aria-activedescendant', options[index].id);
        options[index].scrollIntoView({ block: 'nearest' });
    }

    close() {
        this.listTarget.hidden = true;
        this.active = -1;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }
}
