import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { PLACES } from '../data/dumaguete_places.js';

const DUMAGUETE = [9.3068, 123.3054];
const PHOTON = 'https://photon.komoot.io';
const PHILIPPINES_BBOX = '116.9,4.5,126.7,21.2';
const LIVE_INTERVAL_MS = 15000;

/*
 * Lets people choose a spot without typing coordinates:
 *  - "Use my location" asks the browser for the device's position (the browser shows Allow / Block);
 *  - typing in the address box suggests Dumaguete places at once and OpenStreetMap results as they arrive;
 *  - a map pin can be dragged, or the map tapped, to fine-tune the spot.
 * Each choice fills the hidden latitude and longitude fields and, when a coverage URL is given,
 * says whether Foodjett delivers there. With "live", the rider's position is sent every 15 seconds.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['address', 'latitude', 'longitude'];
    static values = { coverageUrl: String, live: Boolean };

    connect() {
        if (!this.hasLatitudeTarget || !this.hasLongitudeTarget) {
            return;
        }
        this.autoFilled = false;
        this.activeIndex = -1;
        this.suggestions = [];
        this.lastSent = 0;
        this.buildInterface();
        this.buildMap();

        const latitude = parseFloat(this.latitudeTarget.value);
        const longitude = parseFloat(this.longitudeTarget.value);
        if (!Number.isNaN(latitude) && !Number.isNaN(longitude)) {
            this.placePin(latitude, longitude, 16);
            this.checkCoverage(latitude, longitude);
        }

        this.onSubmit = this.onSubmit.bind(this);
        this.element.addEventListener('submit', this.onSubmit);
        if (this.liveValue) {
            this.startLiveSharing();
        }
    }

    disconnect() {
        if (undefined !== this.watchId) {
            navigator.geolocation.clearWatch(this.watchId);
        }
        this.searchAbort?.abort();
        clearTimeout(this.searchTimer);
        this.map?.remove();
        this.panel?.remove();
        this.element.removeEventListener('submit', this.onSubmit);
    }

    // ----- building the interface -------------------------------------------------------------

    buildInterface() {
        this.panel = document.createElement('div');
        this.panel.className = 'location-picker';
        this.panel.innerHTML = `
            <p class="location-picker-actions">
                <button type="button" data-action="location-picker#locate">📍 Use my location</button>
                <span class="location-picker-status" role="status" aria-live="polite"></span>
            </p>
            <ul class="location-picker-suggestions" role="listbox" hidden
                style="list-style:none;margin:0;padding:0;border:1px solid #ccc;max-height:16rem;overflow:auto;background:#fff"></ul>
            <div class="location-picker-map" style="height:260px;margin:.5rem 0"></div>
            <p class="location-picker-hint"><small>Drag the pin or tap the map to fine-tune the spot.</small></p>`;
        this.status = this.panel.querySelector('.location-picker-status');
        this.list = this.panel.querySelector('.location-picker-suggestions');

        if (this.hasAddressTarget) {
            this.addressTarget.insertAdjacentElement('afterend', this.panel);
            // The list belongs right under the box people type in.
            this.addressTarget.insertAdjacentElement('afterend', this.list);
            this.addressTarget.setAttribute('role', 'combobox');
            this.addressTarget.setAttribute('aria-autocomplete', 'list');
            this.addressTarget.addEventListener('input', () => this.onType());
            this.addressTarget.addEventListener('keydown', (event) => this.onKey(event));
            this.addressTarget.addEventListener('blur', () => setTimeout(() => this.hideSuggestions(), 150));
        } else {
            this.element.prepend(this.panel);
        }
    }

    buildMap() {
        const container = this.panel.querySelector('.location-picker-map');
        this.map = L.map(container, { scrollWheelZoom: false }).setView(DUMAGUETE, 14);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(this.map);
        this.map.on('click', (event) => this.choose(event.latlng.lat, event.latlng.lng, null, { zoom: false }));
    }

    placePin(latitude, longitude, zoom = null) {
        if (!this.pin) {
            this.pin = L.marker([latitude, longitude], {
                draggable: true,
                keyboard: true,
                title: 'Delivery spot',
                icon: L.divIcon({ className: '', html: '<span style="font-size:32px;line-height:32px">📍</span>', iconSize: [32, 32], iconAnchor: [16, 32] }),
            }).addTo(this.map);
            this.pin.on('dragend', () => {
                const { lat, lng } = this.pin.getLatLng();
                this.choose(lat, lng, null, { zoom: false });
            });
        } else {
            this.pin.setLatLng([latitude, longitude]);
        }
        if (null !== zoom) {
            this.map.setView([latitude, longitude], zoom);
        } else {
            this.map.panTo([latitude, longitude]);
        }
    }

    // ----- choosing a spot --------------------------------------------------------------------

    /** Stores the spot. Without a label, the address is looked up when the box is empty or was filled by us. */
    choose(latitude, longitude, label, { zoom = true } = {}) {
        this.latitudeTarget.value = latitude.toFixed(7);
        this.longitudeTarget.value = longitude.toFixed(7);
        this.placePin(latitude, longitude, zoom ? 17 : null);
        if (this.hasAddressTarget) {
            if (label) {
                this.addressTarget.value = label;
                this.autoFilled = false;
            } else if ('' === this.addressTarget.value.trim() || this.autoFilled) {
                this.reverseGeocode(latitude, longitude);
            }
        }
        this.checkCoverage(latitude, longitude);
    }

    locate() {
        if (!this.canUseLocation()) {
            return;
        }
        this.say('Finding your location…');
        navigator.geolocation.getCurrentPosition(
            (position) => this.choose(position.coords.latitude, position.coords.longitude, null),
            (error) => this.locationFailed(error),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 },
        );
    }

    canUseLocation() {
        if (!('geolocation' in navigator)) {
            this.say('This browser cannot share a location. Search for the address or tap the map instead.');
            return false;
        }
        if (!window.isSecureContext) {
            this.say('Location sharing only works on localhost or an https address. Search for the address or tap the map instead.');
            return false;
        }

        return true;
    }

    locationFailed(error) {
        this.say(1 === error.code
            ? 'Location access is blocked. Type the address and pick a suggestion, or tap the map.'
            : 'Could not find your location just now. Type the address and pick a suggestion, or tap the map.');
    }

    async reverseGeocode(latitude, longitude) {
        let label = null;
        try {
            const response = await fetch(`${PHOTON}/reverse?lat=${latitude}&lon=${longitude}&lang=en`);
            const feature = (await response.json()).features?.[0];
            label = feature ? this.describe(feature.properties) : null;
        } catch (error) {
            label = null;
        }
        if (!label) {
            const nearest = this.nearestPlace(latitude, longitude);
            label = nearest ? `Near ${nearest.label}` : null;
        }
        if (label && ('' === this.addressTarget.value.trim() || this.autoFilled)) {
            this.addressTarget.value = label;
            this.autoFilled = true;
        }
    }

    async checkCoverage(latitude, longitude) {
        if (!this.coverageUrlValue) {
            return;
        }
        try {
            const response = await fetch(`${this.coverageUrlValue}?lat=${latitude}&lng=${longitude}`, { headers: { Accept: 'application/json' } });
            const { covered } = await response.json();
            this.say(covered ? '✓ We deliver here.' : '✗ This spot is outside our delivery area, so orders to it will be refused.');
        } catch (error) {
            this.say('');
        }
    }

    // ----- suggestions --------------------------------------------------------------------------

    onType() {
        this.autoFilled = false;
        clearTimeout(this.searchTimer);
        const query = this.addressTarget.value.trim();
        if (query.length < 2) {
            this.hideSuggestions();
            return;
        }
        const local = this.localMatches(query);
        this.showSuggestions(local);
        this.searchTimer = setTimeout(() => this.searchOnline(query, local), 300);
    }

    localMatches(query) {
        const words = this.normalize(query).split(' ').filter(Boolean);

        return PLACES
            .filter((place) => words.every((word) => this.normalize(place.label).includes(word)))
            .slice(0, 5)
            .map((place) => ({ label: place.label, lat: place.lat, lng: place.lng }));
    }

    async searchOnline(query, local) {
        this.searchAbort?.abort();
        this.searchAbort = new AbortController();
        try {
            const url = `${PHOTON}/api/?q=${encodeURIComponent(query)}&lat=${DUMAGUETE[0]}&lon=${DUMAGUETE[1]}&bbox=${PHILIPPINES_BBOX}&limit=6&lang=en`;
            const response = await fetch(url, { signal: this.searchAbort.signal });
            const online = (await response.json()).features.map((feature) => ({
                label: this.describe(feature.properties),
                lat: feature.geometry.coordinates[1],
                lng: feature.geometry.coordinates[0],
            }));
            if (query !== this.addressTarget.value.trim()) {
                return;
            }
            const seen = new Set(local.map((place) => place.label));
            this.showSuggestions([...local, ...online.filter((place) => place.label && !seen.has(place.label) && seen.add(place.label))].slice(0, 8));
        } catch (error) {
            // Offline or blocked: the Dumaguete list already shown is the fallback.
        }
    }

    showSuggestions(places) {
        this.suggestions = places;
        this.activeIndex = -1;
        this.list.replaceChildren(...places.map((place, index) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.textContent = place.label;
            item.style.cssText = 'padding:.4rem .6rem;cursor:pointer';
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.pick(index);
            });
            return item;
        }));
        this.list.hidden = 0 === places.length;
    }

    hideSuggestions() {
        this.list.hidden = true;
        this.activeIndex = -1;
    }

    pick(index) {
        const place = this.suggestions[index];
        if (place) {
            this.hideSuggestions();
            this.choose(place.lat, place.lng, place.label);
        }
    }

    onKey(event) {
        if (this.list.hidden) {
            return;
        }
        if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
            event.preventDefault();
            const step = 'ArrowDown' === event.key ? 1 : -1;
            this.activeIndex = (this.activeIndex + step + this.suggestions.length) % this.suggestions.length;
            [...this.list.children].forEach((item, index) => {
                item.setAttribute('aria-selected', String(index === this.activeIndex));
                item.style.background = index === this.activeIndex ? '#eee' : '';
            });
        } else if ('Enter' === event.key && this.activeIndex >= 0) {
            event.preventDefault();
            this.pick(this.activeIndex);
        } else if ('Escape' === event.key) {
            this.hideSuggestions();
        }
    }

    // ----- riders: sharing the position while the page is open ------------------------------

    startLiveSharing() {
        if (!this.canUseLocation()) {
            return;
        }
        this.say('Waiting for location access…');
        this.watchId = navigator.geolocation.watchPosition(
            (position) => {
                const { latitude, longitude } = position.coords;
                this.choose(latitude, longitude, null, { zoom: 0 === this.lastSent });
                if (Date.now() - this.lastSent >= LIVE_INTERVAL_MS) {
                    this.lastSent = Date.now();
                    this.send();
                }
            },
            (error) => this.say(1 === error.code
                ? 'Location access is blocked. Drag the pin to where you are and press Share location.'
                : 'Could not read your location. Drag the pin to where you are and press Share location.'),
            { enableHighAccuracy: true, maximumAge: 10000 },
        );
    }

    async send() {
        try {
            await fetch(this.element.action, { method: 'POST', body: new FormData(this.element), headers: { Accept: 'text/html' } });
            this.say(`Location shared automatically at ${new Date().toLocaleTimeString()}.`);
        } catch (error) {
            this.say('Could not send your location; it will try again shortly.');
        }
    }

    // ----- helpers -----------------------------------------------------------------------------

    onSubmit(event) {
        if ('' === this.latitudeTarget.value || '' === this.longitudeTarget.value) {
            event.preventDefault();
            this.say('Choose the spot first: pick a suggestion, use your location, or tap the map.');
            this.hasAddressTarget ? this.addressTarget.focus() : this.panel.scrollIntoView();
        }
    }

    describe(properties) {
        const street = [properties.housenumber, properties.street].filter(Boolean).join(' ');
        const parts = [properties.name, street, properties.district || properties.locality, properties.city || properties.county, properties.state];

        return [...new Set(parts.filter(Boolean))].join(', ');
    }

    nearestPlace(latitude, longitude) {
        let best = null;
        let bestDistance = Infinity;
        for (const place of PLACES) {
            const distance = (place.lat - latitude) ** 2 + (place.lng - longitude) ** 2;
            if (distance < bestDistance) {
                best = place;
                bestDistance = distance;
            }
        }

        return bestDistance < 0.0004 ? best : null; // roughly within 2 km
    }

    normalize(text) {
        return text.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
    }

    say(message) {
        this.status.textContent = message;
    }
}
