@extends('layouts.app')

@section('title', $title)
@section('container', 'mx-auto w-full max-w-3xl px-4 py-8')

@section('content')
    <x-page-header :title="$title" />

    {{-- The admin stores these as rich text authored in its CMS. It is trusted
         operator-authored content, so it is rendered unescaped. --}}
    <div class="card-pad text-sm [&_a]:underline [&_h2]:mt-6 [&_h2]:mb-2 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:mb-1 [&_h3]:font-semibold [&_li]:my-1 [&_p]:my-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5">
        {!! $body !!}
    </div>
@endsection
