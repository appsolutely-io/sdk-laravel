<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Webhooks;

use Appsolutely\Sdk\Laravel\Events\WebhookReceived;
use Appsolutely\Sdk\Webhooks\Deliveries;
use Appsolutely\Sdk\Webhooks\Event;
use Appsolutely\Sdk\Webhooks\Events\TypedEvent;
use Appsolutely\Sdk\Webhooks\Events\UnknownEvent;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Dispatches a verified delivery that is not yet handled: always as
 * WebhookReceived, and, when the SDK knows its type, also as the SDK's
 * typed event (an OrderPaidEvent, a PingEvent, ...), so a listener can
 * type-hint either. A listener that throws leaves the delivery unhandled,
 * so its resend dispatches both again.
 *
 * The answer is always 200, 409 or 500, never a redirect (a 3xx counts as
 * a failed attempt) and never 410, which would switch the endpoint off. A
 * 409 means another attempt of the same delivery is being handled: like
 * any status but 2xx, the sender retries it later, as Standard Webhooks
 * retries every failed attempt.
 *
 * @internal registered by Route::appsolutelyWebhooks()
 */
final class HandleWebhook
{
    /**
     * The services arrive per call rather than through the constructor:
     * the router keeps a route's controller instance, so a long-lived
     * worker (Octane) would otherwise reuse the first request's.
     */
    public function __invoke(Request $request, Deliveries $deliveries, DeliveryLock $lock, Dispatcher $events, ExceptionHandler $exceptions): Response
    {
        $event = $request->attributes->get(VerifyWebhookSignature::EVENT);
        if (!$event instanceof Event) {
            throw new LogicException('The Appsolutely webhook action runs only behind VerifyWebhookSignature.');
        }

        // A retry or a redelivery keeps its id: acknowledge it again
        // without running the listeners twice.
        if ($deliveries->wasHandled($event->id)) {
            return new Response('', SymfonyResponse::HTTP_OK);
        }

        // Two attempts of one delivery arriving together would both pass the
        // check above; the one that finds the lock held is answered 409 and
        // its retry finds the delivery handled, or runs if the other failed.
        return $lock->run($event->id, static fn(): Response => self::handle($event, $deliveries, $events, $exceptions))
            ?? new Response('Another attempt of this webhook delivery is being handled.', SymfonyResponse::HTTP_CONFLICT, ['Content-Type' => 'text/plain']);
    }

    private static function handle(Event $event, Deliveries $deliveries, Dispatcher $events, ExceptionHandler $exceptions): Response
    {
        // Checked again under the lock: the attempt that held it may have
        // finished between the first check and this one.
        if ($deliveries->wasHandled($event->id)) {
            return new Response('', SymfonyResponse::HTTP_OK);
        }

        try {
            // Read before anything is dispatched: a known type whose data
            // breaks its shape fails the delivery whole, so no listener has
            // run when the retry comes.
            $typed = TypedEvent::from($event);

            $events->dispatch(new WebhookReceived($event));
            if (!$typed instanceof UnknownEvent) {
                $events->dispatch($typed);
            }
        } catch (Throwable $exception) {
            // Reported, not rendered: the sender gets no detail, and the 500
            // makes it retry; the id stays unmarked so the retry runs again.
            $exceptions->report($exception);

            return new Response('The webhook delivery could not be handled.', SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR, ['Content-Type' => 'text/plain']);
        }

        $deliveries->markHandled($event->id);

        return new Response('', SymfonyResponse::HTTP_OK);
    }
}
