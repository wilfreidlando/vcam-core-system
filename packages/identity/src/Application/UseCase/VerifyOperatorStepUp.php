<?php

declare(strict_types=1);

namespace Core\Identity\Application\UseCase;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\TotpProvider;
use Core\Support\Clock;

/**
 * Écart #5 de la recette 2026-10-29 (annexe sécurité, garde-fou G-4) — l'élévation
 * de privilège sur action sensible (« step-up »).
 *
 * Le MFA n'est qu'une porte d'entrée : une fois la session ouverte, une action
 * qui déplace de l'argent ou des secrets doit demander une preuve fraîche. Ce cas
 * d'usage vérifie un code TOTP **déjà connu de l'opérateur connecté** — il réutilise
 * le vérificateur du login et son ANTI-REJEU : le pas de temps accepté est
 * enregistré (`mfaLastVerifiedTimestep`), de sorte qu'un code déjà consommé, au
 * login ou à une élévation précédente, est refusé.
 *
 * Il ne gère pas la session : l'horodatage d'élévation appartient à la couche
 * HTTP (`StepUpGate`).
 */
final readonly class VerifyOperatorStepUp
{
    public function __construct(
        private OperatorRepository $operators,
        private TotpProvider $totp,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @throws InvalidMfaCode code faux, périmé ou déjà utilisé
     * @throws MfaNotEnrolled l'opérateur n'a pas de MFA configurée
     * @throws OperatorRevoked
     * @throws OperatorNotFound
     */
    public function __invoke(string $operatorId, string $code): void
    {
        $operator = $this->operators->find($operatorId)
            ?? throw OperatorNotFound::withId($operatorId);

        if ($operator->isRevoked()) {
            throw OperatorRevoked::withId($operator->id);
        }

        $secret = $operator->mfaSecret()
            ?? throw MfaNotEnrolled::forOperator($operator->id);

        $timestep = $this->totp->verify($secret, $code, $operator->mfaLastVerifiedTimestep());
        if ($timestep === false) {
            $this->record($operator->id, 'operator.step_up_failed');

            throw InvalidMfaCode::make();
        }

        $operator->recordMfaVerification($timestep);
        $this->operators->save($operator);

        $this->record($operator->id, 'operator.step_up_succeeded');
    }

    /**
     * L'élévation est-elle exigible pour cet opérateur ? Vrai quand il a une MFA
     * activée : sans MFA (cas de `IDENTITY_DISABLE_MFA`), il n'y a rien à ressaisir.
     */
    public function isRequiredFor(string $operatorId): bool
    {
        $operator = $this->operators->find($operatorId);

        return $operator !== null && $operator->mfaEnabled() && $operator->mfaSecret() !== null;
    }

    private function record(string $operatorId, string $action): void
    {
        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operatorId,
            action: $action,
            resource: 'operator:'.$operatorId,
            before: null,
            after: null,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));
    }
}
