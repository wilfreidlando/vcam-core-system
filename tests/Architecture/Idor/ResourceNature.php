<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * The nature of the resource a console route addresses — it tells the
 * scenario factory what to fabricate for "organization B" (and, for the
 * positive control, for organization A).
 */
enum ResourceNature: string
{
    /** The tenant root itself. */
    case Organization = 'organization';

    /** A membership of an organization (route carries `organizationId` + `operatorId`). */
    case OrganizationMember = 'organization-member';

    /** A Project, owned by one Organization. */
    case Project = 'project';

    /** An API key, owned by a Project. */
    case ApiKey = 'api-key';

    /** A provider application (credentials), owned by a Project. */
    case ProviderApplication = 'provider-application';

    /** A webhook endpoint (receiver), owned by a Project. */
    case WebhookEndpoint = 'webhook-endpoint';

    /** A webhook delivery (attempt), owned by a Project through its endpoint. */
    case WebhookDelivery = 'webhook-delivery';

    /** A WhatsApp channel session owned by one Project. */
    case ChannelSession = 'channel-session';

    /** A Wallet, owned by a Project. */
    case Wallet = 'wallet';

    /** A Notification, owned by a Project. */
    case Notification = 'notification';

    /** A Payment, owned by a Project. */
    case Payment = 'payment';

    /** A money exception, owned by a Project. */
    case MoneyException = 'money-exception';

    /** A console account — owned by the platform, by no organization. */
    case Operator = 'operator';

    /** A platform-global channel session — owned by the platform, by no organization. */
    case GlobalChannelSession = 'global-channel-session';
}
