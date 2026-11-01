<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * L'invitation d'un opérateur de la console, envoyée à l'invité.
 *
 * Le jeton d'activation voyage **en clair dans ce message** : c'est le
 * seul endroit où il doit se trouver, et il n'est valable qu'une fois
 * (ADR-0081 S-07). Il n'est **pas** porté par l'adresse — une URL finit
 * dans les journaux, les historiques et les référents ; un corps de
 * message, non — et c'est pour la même raison que la page
 * `/admin/invitation` demande de le coller.
 *
 * Le mot de passe n'est pas dans le message : l'invité le choisit
 * lui-même, et il enrôle son MFA dans la foulée. Le système ne choisit
 * jamais un mot de passe et n'en transmet aucun.
 */
final class OperatorInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly string $token,
        public readonly string $expiresAt,
        public readonly string $invitationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre invitation à la console opérateur',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.operator-invitation',
            with: [
                'email' => $this->email,
                'token' => $this->token,
                'expiresAt' => $this->expiresAt,
                'invitationUrl' => $this->invitationUrl,
            ],
        );
    }
}
