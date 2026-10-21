{{-- Shown whenever v1/settings/ could not be fetched: the panel is then
     running on fallback branding and empty data, which reads as "my theme
     reverted" unless said out loud. --}}
@if (! $settings->loaded())
    <div class="border-b border-amber-300 bg-amber-50 px-4 py-2 text-center text-sm font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
        {{ __('Cannot reach the CabMe admin at') }}
        <code class="font-mono">{{ config('cabme.base_url') }}</code>
        — {{ __('showing default branding. Check CABME_API_URL and that the admin is running') }}
        (<code class="font-mono">php artisan cabme:check</code>).
    </div>
@endif
