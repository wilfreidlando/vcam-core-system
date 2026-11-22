<?php

declare(strict_types=1);

namespace Core\Billing\Tests\Integration;

use ArrayObject;
use Core\Billing\Contracts\AcceptTenantSchema;
use Core\Billing\Contracts\AcceptTenantSchemaCommand;
use Core\Billing\Contracts\ProposeTenantSchema;
use Core\Billing\Contracts\ProposeTenantSchemaCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Billing\Domain\Port\CustomerDeclaredAttributeRepository;
use Core\Events\Contracts\DomainEvent;
use Core\Events\Contracts\EventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0130 §1 — **l'ordre** des écritures.
 *
 * Les valeurs déclarées du client sont écrites **avant** que l'événement
 * ne parte. L'application qui relit le client depuis `customer.created`
 * trouve ainsi les valeurs que l'opérateur vient de saisir.
 *
 * Le test intercepte l'événement **au moment où il est écrit** dans
 * l'outbox — donc à l'intérieur de la transaction — et relit le client
 * depuis le client que l'événement nomme. Si l'écriture des valeurs
 * venait après l'événement, la relecture ne trouverait rien : le test
 * échouerait.
 *
 * ADR-0130 §1 — les valeurs **ne voyagent pas** dans le payload : elles
 * restent dans la base du Core, l'application les y relit.
 */
final class DeclaredAttributeWriteOrderTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT = '11111111-1111-4111-8111-111111111111';

    #[Test]
    public function the_declared_values_are_written_before_the_event_leaves(): void
    {
        $this->acceptAForm();

        // Ce que l'application lit **au moment** où l'événement est écrit.
        $read = $this->interceptTheOutbox();

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'AE-0042',
            displayName: 'Auto-école de Douala',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
            declaredAttributes: ['localisation' => 'Douala'],
        ));

        // Au moment où `customer.created` a été écrit, les valeurs were
        // already there.
        self::assertArrayHasKey('customer.created', $read->getArrayCopy());
        self::assertSame(['localisation' => 'Douala'], $read['customer.created']);

        // ADR-0130 §1 — et elles ne voyagent **pas** dans le payload.
        $row = DB::table('event_outbox')->where('type', 'customer.created')->first();
        self::assertNotNull($row);
        $payload = $row->payload;
        self::assertIsString($payload);
        self::assertStringNotContainsString('Douala', $payload);
    }

    #[Test]
    public function a_customer_without_declared_values_is_still_written(): void
    {
        $this->acceptAForm();

        $read = $this->interceptTheOutbox();

        app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand(
            projectId: self::PROJECT,
            externalRef: 'AE-0043',
            displayName: 'Auto-école de Yaoundé',
            email: null,
            phone: null,
            locale: 'fr',
            metadata: [],
            expectedVersion: null,
            actor: 'test:billing',
            correlationId: null,
        ));

        self::assertSame([], $read['customer.created']);
    }

    /**
     * L'outbox est interceptée : au moment où l'événement est écrit, le
     * client que l'événement nomme est relu.
     *
     * @return ArrayObject<string, array<string, string>>
     */
    private function interceptTheOutbox(): ArrayObject
    {
        /** @var ArrayObject<string, array<string, string>> $read */
        $read = new ArrayObject;

        // L'outbox réelle est résolue **avant** que le décorateur ne prenne
        // sa place : le décorateur ne connaît que le **port** (`Contracts`),
        // jamais l'implémentation d'un autre module (G3c).
        $inner = app(EventOutbox::class);

        $this->app->bind(EventOutbox::class, static fn (): EventOutbox => new class($inner, $read) implements EventOutbox
        {
            /**
             * @param ArrayObject<string, array<string, string>> $read
             */
            public function __construct(
                private readonly EventOutbox $inner,
                private readonly ArrayObject $read,
            ) {}

            public function append(DomainEvent $event): void
            {
                $customerId = $event->payload['customer_id'] ?? null;

                // La relecture se fait **depuis l'événement** : le client
                // que l'événement nomme.
                $this->read[$event->type] = is_string($customerId)
                    ? app(CustomerDeclaredAttributeRepository::class)->forCustomer($event->projectId, $customerId)
                    : [];

                $this->inner->append($event);
            }
        });

        return $read;
    }

    /**
     * Une forme en vigueur qui déclare une valeur.
     */
    private function acceptAForm(): void
    {
        $proposed = app(ProposeTenantSchema::class)->propose(new ProposeTenantSchemaCommand(
            projectId: self::PROJECT,
            uniqueKey: 'code_etablissement',
            uniqueKeyPattern: 'AE-[0-9]{4}',
            fields: [[
                'key' => 'localisation',
                'label' => 'Localisation',
                'type' => 'string',
                'required' => false,
                'max_length' => 120,
                'source' => 'declared.localisation',
            ]],
            actor: 'project:'.self::PROJECT,
            correlationId: null,
        ));

        app(AcceptTenantSchema::class)->accept(new AcceptTenantSchemaCommand(
            projectId: self::PROJECT,
            schemaId: $proposed->schema->id,
            actor: 'operator:test',
            correlationId: null,
        ));
    }
}
