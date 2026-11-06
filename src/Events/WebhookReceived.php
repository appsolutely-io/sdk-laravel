<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Events;

use Appsolutely\Sdk\Webhooks\Event;

/**
 * A delivery that passed verification and has not been handled before.
 *
 * Every listener runs before the endpoint answers, and the delivery counts
 * as handled only when none of them throws: a listener that throws makes
 * the endpoint answer 500, the site resends the delivery, and the resend
 * runs every listener again, those that succeeded included. Make listeners
 * idempotent, keyed on the delivery id. Keep them quick (the sender waits
 * a few seconds at most) and queue slow work; a queued listener counts as
 * handled once it is queued.
 *
 * A delivery of a type the SDK knows is then dispatched again as the SDK's
 * typed event (see Appsolutely\Sdk\Webhooks\Events\TypedEvent::from()), and
 * the same holds for its listeners.
 */
final readonly class WebhookReceived
{
    public function __construct(
        public Event $event,
    ) {}
}
