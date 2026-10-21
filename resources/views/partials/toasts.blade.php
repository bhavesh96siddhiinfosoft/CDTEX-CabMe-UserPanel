{{-- Toast stack, driven by the Alpine store. --}}
<div class="pointer-events-none fixed inset-x-4 top-20 z-50 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-5" x-data>
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition.opacity.duration.200ms
             class="card pointer-events-auto flex w-full items-center gap-3 border-l-4 px-4 py-3 sm:w-auto sm:min-w-[17rem] sm:max-w-sm"
             style="box-shadow: var(--shadow-lg);"
             :class="{
                 'border-l-emerald-500': toast.type === 'success',
                 'border-l-rose-500': toast.type === 'error',
                 'border-l-[var(--color-brand-600)]': toast.type === 'info',
             }">
            <span class="text-sm font-medium" x-text="toast.message"></span>
            <button type="button" class="ms-auto shrink-0 faint transition-opacity hover:opacity-60"
                    @click="$store.toasts.dismiss(toast.id)" aria-label="{{ __('Dismiss') }}">&times;</button>
        </div>
    </template>
</div>
