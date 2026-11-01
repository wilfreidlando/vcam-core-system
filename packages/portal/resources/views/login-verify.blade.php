@extends('portal::layout')
@section('title', 'Saisir le code · '.$branding->displayName)
@section('content')
<section class="notice notice--form">
    @include('portal::partials.guest-brand')
    <h1>Saisissez votre code</h1>
    <p class="muted">Si ce numéro correspond à un compte, un code à 6 chiffres vient d'être envoyé par WhatsApp. Il est valable 10 minutes.</p>

    @isset($error)
        <div class="alert" id="code-error" role="alert">{{ $error }}</div>
    @endisset

    <form method="post" action="/portal/{{ $projectId }}/login/verify" class="form">
        <input type="hidden" name="phone" value="{{ $phone }}">
        <div class="field">
            <label class="field__label" for="login_pin">Code à 6 chiffres</label>
            <input class="field__input field__input--code" id="login_pin" type="text" name="login_pin" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,8}" maxlength="8" placeholder="000000" @isset($error) aria-describedby="code-error" aria-invalid="true" @endisset required>
        </div>
        <button type="submit" class="button button--block">Se connecter</button>
    </form>

    <p class="muted small"><a href="/portal/{{ $projectId }}/login">Recevoir un nouveau code</a> (au plus un par minute)</p>
</section>
@endsection
