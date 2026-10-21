import Alpine from 'alpinejs';

/** Light/dark preference, persisted per browser. */
Alpine.store('theme', {
    current: 'light',

    init() {
        this.current = localStorage.getItem('cabme_theme')
            || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        this.apply();
    },

    toggle() {
        this.current = this.current === 'dark' ? 'light' : 'dark';
        localStorage.setItem('cabme_theme', this.current);
        this.apply();
    },

    apply() {
        document.documentElement.setAttribute('data-theme', this.current);
    },
});
