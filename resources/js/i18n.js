/**
 * Translate a runtime string. The server renders window.__i18n (see
 * partials/i18n.blade.php) keyed by the English source string; anything not
 * in the map falls back to the English key, so a missing translation is
 * cosmetic rather than an error.
 */
export function t(key) {
    return window.__i18n?.[key] ?? key;
}
