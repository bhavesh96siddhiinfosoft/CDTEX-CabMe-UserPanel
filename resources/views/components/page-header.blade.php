@props(['title', 'subtitle' => null])

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
        @if ($subtitle)
            <p class="lede mt-1.5 text-sm">{{ $subtitle }}</p>
        @endif
    </div>
    @if (trim($slot) !== '')
        <div class="flex items-center gap-2">{{ $slot }}</div>
    @endif
</div>
