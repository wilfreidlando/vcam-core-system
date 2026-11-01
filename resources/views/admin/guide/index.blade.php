@extends('layouts.admin')

@section('title', $title)

@section('subtitle')
    {{ collect($familles)->flatMap(fn (array $famille) => $famille['parcours'])->count() }} parcours, cinq familles
@endsection

@section('content')
<div class="space-y-6">
    <p class="text-base text-slate-700 max-w-3xl">
        Chaque parcours répond à une situation : ce que vous voulez faire, quand le faire,
        et ce que vous voyez à l’écran. Lisez-en un seul, il se suffit à lui-même.
    </p>

    @foreach ($familles as $famille)
        <section class="rounded-lg border border-slate-200 bg-white">
            <div class="border-b border-slate-100 px-5 py-3 flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-900">{{ $famille['titre'] }}</h2>
                <a href="{{ route('admin.guide.'.$famille['slug']) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">Ouvrir cette partie</a>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach ($famille['parcours'] as $flow)
                    <li class="px-5 py-3 flex flex-wrap items-start justify-between gap-x-6 gap-y-1">
                        <div class="min-w-0">
                            <a href="{{ route('admin.guide.'.$famille['slug']) }}#{{ $flow->ancre() }}" class="font-medium text-indigo-700 hover:text-indigo-900">{{ $flow->titre }}</a>
                            <p class="text-sm text-slate-600">{{ $flow->situation }}</p>
                        </div>
                        <p class="shrink-0 text-xs text-slate-500">
                            <span class="font-mono">{{ $flow->numero }}</span> · {{ $flow->profil }} · {{ $flow->temps }}
                        </p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
@endsection
