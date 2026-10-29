@extends('portal::layout')
@section('title', $title)
@section('content')
<section class="notice">
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    @isset($principal)
        <p><a class="button button--ghost" href="/portal">Retour à l'accueil</a></p>
    @endisset
</section>
@endsection
