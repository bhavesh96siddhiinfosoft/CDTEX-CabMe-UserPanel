import Alpine from 'alpinejs';

Alpine.store('toasts', {
    items: [],
    seq: 0,

    push(message, type = 'info', timeout = 4500) {
        const id = ++this.seq;
        this.items.push({ id, message, type });
        setTimeout(() => this.dismiss(id), timeout);
    },

    success(message) { this.push(message, 'success'); },
    error(message) { this.push(message, 'error'); },

    dismiss(id) {
        this.items = this.items.filter((t) => t.id !== id);
    },
});

// Server-side flash/validation messages queue on window.__flashQueue (see
// partials/flash.blade.php) and surface as toasts here.
window.__drainFlashQueue = () => {
    const queue = window.__flashQueue ?? [];
    while (queue.length) {
        const { type, message } = queue.shift();
        if (message) Alpine.store('toasts').push(message, type ?? 'info', type === 'error' ? 7000 : 4500);
    }
};

document.addEventListener('alpine:init', () => window.__drainFlashQueue());
