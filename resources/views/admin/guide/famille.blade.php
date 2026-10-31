@extends('layouts.admin')

@section('title', $title)

@section('subtitle')
    {{ count($parcours) }} parcours
@endsection

@section('content')
<div class="space-y-6">
    <nav aria-label="Parcours de cette partie" class="rounded-lg border border-slate-200 bg-white px-5 py-4">
        <ul class="flex flex-wrap gap-x-6 gap-y-2">
            @foreach ($parcours as $entree)
                <li>
                    <a href="#{{ $entree['flow']->ancre() }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">
                        <span class="font-mono text-xs text-slate-500">{{ $entree['flow']->numero }}</span>
                        {{ $entree['flow']->titre }}
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    @foreach ($parcours as $entree)
        <article id="{{ $entree['flow']->ancre() }}" class="rounded-lg border border-slate-200 bg-white">
            <header class="border-b border-slate-100 px-6 py-4">
                <p class="font-mono text-xs uppercase tracking-wide text-slate-500">{{ $entree['flow']->numero }}</p>
                <h2 class="text-lg font-semibold text-slate-900">{{ $entree['flow']->titre }}</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $entree['flow']->situation }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $entree['flow']->profil }} · {{ $entree['flow']->temps }}</p>
            </header>
            <div class="guide px-6 py-4">
                {{-- The Markdown is ours and the renderer strips raw HTML
                     (ADR-0113, decision 4): what is rendered here cannot
                     carry a tag the author did not write in Markdown. --}}
                {!! $entree['html'] !!}
            </div>
        </article>
    @endforeach
</div>
@endsection
