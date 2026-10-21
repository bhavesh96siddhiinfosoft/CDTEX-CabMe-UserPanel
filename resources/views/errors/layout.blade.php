@extends('layouts.app')

@section('title', $title)
@section('container', 'mx-auto w-full max-w-lg px-4 py-20')

@section('content')
    <div class="card-pad text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl brand-tint brand-text">
            <x-icon :name="$icon ?? 'car'" class="h-7 w-7" />
        </span>

        <p class="faint mt-5 text-sm font-bold uppercase tracking-widest">{{ $code }}</p>
        <h1 class="mt-1 text-2xl font-extrabold tracking-tight">{{ $title }}</h1>
        <p class="lede mt-2 text-sm">{{ $message }}</p>

        <div class="mt-6 flex flex-wrap justify-center gap-2">
            <a href="{{ url('/') }}" class="btn-primary">{{ __('Back to home') }}</a>
            <a href="{{ route('faq') }}" class="btn-ghost">{{ __('Get help') }}</a>
        </div>
    </div>
@endsection
