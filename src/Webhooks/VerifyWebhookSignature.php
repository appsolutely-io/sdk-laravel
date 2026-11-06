<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Webhooks;

use Appsolutely\Sdk\Exception\WebhookVerificationException;
use Appsolutely\Sdk\Webhooks\Verifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Verifies a delivery's signature, timestamp and id on the raw body before
 * anything parses it, and hands the verified event to the route's action.
 *
 * @internal registered by Route::appsolutelyWebhooks()
 */
final readonly class VerifyWebhookSignature
{
    /** The request attribute holding the verified event. */
    public const string EVENT = 'appsolutely.webhook_event';

    private const array HEADERS = ['webhook-id', 'webhook-timestamp', 'webhook-signature'];

    public function __construct(
        private Verifier $verifier,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param Closure(Request): SymfonyResponse $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $headers = [];
        foreach (self::HEADERS as $name) {
            $headers[$name] = array_values(array_filter($request->headers->all($name), is_string(...)));
        }

        try {
            $event = $this->verifier->verify($request->getContent(), $headers);
        } catch (WebhookVerificationException $exception) {
            // The reason goes to the log, where the integrator looks when
            // deliveries keep failing; the sender only learns it failed.
            $this->logger->warning('An Appsolutely webhook delivery failed verification.', ['reason' => $exception->getMessage()]);

            return new Response('Invalid webhook delivery.', SymfonyResponse::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain']);
        }

        $request->attributes->set(self::EVENT, $event);

        return $next($request);
    }
}
