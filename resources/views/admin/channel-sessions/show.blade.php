@extends('layouts.admin')

@section('title', $label)
@section('subtitle', $sessionRef)

@section('content')
<div class="max-w-lg mx-auto space-y-4">
    <div class="bg-white border border-slate-200 rounded-lg p-6 text-center">
        @if ($image)
            <img src="{{ $image }}" alt="{{ $label }} — {{ $sessionRef }}" class="mx-auto max-w-full rounded-md border border-slate-200">
            <p class="mt-3 text-base text-slate-600">Actualisez la page pour obtenir une nouvelle image.</p>
        @else
            <p class="text-base text-rose-700">{{ $error }}</p>
            <p class="mt-2 text-sm text-slate-500">Si la session est déjà liée à un téléphone, le QR code n'est plus disponible.</p>
        @endif
    </div>
    <div class="text-right">
        <a href="{{ $backUrl }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">← Retour aux sessions</a>
    </div>
</div>
@endsection
