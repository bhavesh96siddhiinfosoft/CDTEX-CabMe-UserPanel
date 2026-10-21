import './bootstrap';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';
import persist from '@alpinejs/persist';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

import './shell-guard';
import './stores/theme';
import './stores/toasts';
import './push';
import './components/place-picker';
import './components/map-booking';
import './components/ride-tracker';
import './components/ride-pay';
import './components/chat';
import './components/wallet-topup';
import './components/prepaid-checkout';
import './components/phone-input';
import './components/firebase-auth';

// Pages with a one-off inline map (coverage) use the global rather than each
// shipping its own bundle. Set before Alpine.start() so x-init can use it.
window.L = L;

Alpine.plugin(collapse);
Alpine.plugin(focus);
Alpine.plugin(persist);

window.Alpine = Alpine;
Alpine.start();
