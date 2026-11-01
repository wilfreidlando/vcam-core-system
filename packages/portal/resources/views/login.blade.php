@extends('portal::layout')
@section('title', 'Connexion · '.$branding->displayName)
@section('content')
<section class="notice notice--form">
    @include('portal::partials.guest-brand')
    <h1>Accéder à votre espace client</h1>
    <p class="muted">Saisissez le numéro de téléphone de votre compte. Nous vous envoyons un code à 6 chiffres par WhatsApp.</p>

    @isset($error)
        <div class="alert" id="login-error" role="alert">{{ $error }}</div>
    @endisset

    <form method="post" action="/portal/{{ $projectId }}/login" class="form">
        <div class="field">
            <label class="field__label" for="phone">Numéro de téléphone</label>
            <span id="phone-hint" class="muted small">Format : +237 6XX XX XX XX, avec l'indicatif du pays.</span>
            <input class="field__input" id="phone" type="tel" name="phone" inputmode="tel" autocomplete="tel" placeholder="+237 6XX XX XX XX" value="{{ $phone ?? '' }}" maxlength="40" aria-describedby="{{ isset($error) ? 'login-error phone-hint' : 'phone-hint' }}" @isset($error) aria-invalid="true" @endisset required>
        </div>
        <button type="submit" class="button button--block">Recevoir mon code</button>
    </form>
</section>
@endsection
