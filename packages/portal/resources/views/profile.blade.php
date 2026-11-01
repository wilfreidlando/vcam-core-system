@extends('portal::layout')
@section('title', 'Profil')
@section('content')
@php use Core\Portal\Application\Support\PortalFormat as F; @endphp
<h1>Profil</h1>
<section class="card">
    <dl class="facts">
        <div><dt>Nom</dt><dd>{{ $profile->displayName }}</dd></div>
        <div><dt>Référence</dt><dd>{{ $profile->reference }}</dd></div>
        <div><dt>E-mail</dt><dd>{{ $profile->maskedEmail ?? '—' }}</dd></div>
        <div><dt>Téléphone</dt><dd>{{ $profile->maskedPhone ?? '—' }}</dd></div>
        <div><dt>Client depuis</dt><dd>{{ F::date($profile->memberSince) }}</dd></div>
    </dl>
    </section>
<section class="card">
    <h2 class="card__title">Sécurité</h2>
    <p class="muted">Cet espace s'ouvre depuis {{ $principal->branding->displayName }} et se ferme après 30 minutes d'inactivité.</p>
    <form method="post" action="/portal/logout" class="inline">
        <input type="hidden" name="_csrf" value="{{ $csrf }}">
        <button type="submit" class="button button--ghost">Se déconnecter</button>
    </form>
</section>
@endsection
