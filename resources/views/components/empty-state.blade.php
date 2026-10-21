@props(['title' => __('Nothing here yet'), 'message' => null, 'icon' => 'pin'])

<div class="card flex flex-col items-center gap-3 px-6 py-14 text-center">
    <span class="grid h-12 w-12 place-items-center rounded-2xl muted-surface faint">
        <x-icon :name="$icon" class="h-6 w-6" />
    </span>
    <h3 class="text-base font-bold">{{ $title }}</h3>
    @if ($message)
        <p class="lede max-w-sm text-sm">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
