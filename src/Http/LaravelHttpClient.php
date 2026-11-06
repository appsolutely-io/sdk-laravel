<?php

declare(strict_types=1);

namespace Appsolutely\Sdk\Laravel\Http;

use Appsolutely\Sdk\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\StrayRequestException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The SDK's PSR-18 client, sending through Laravel's HTTP client so that
 * Http::fake(), Http::preventStrayRequests(), the global middleware and
 * options, and the request events (seen by Telescope and Pulse) apply to
 * the SDK's requests as to the application's own. A header the SDK sets
 * replaces a global header of the same name, in any case; the application's
 * global credentials are never sent; other global headers still apply.
 *
 * It goes through PendingRequest::send(), the public way in, rather than
 * the Guzzle client underneath: Laravel's handlers rely on options only
 * send() sets. A pending request is made per call because it copies the
 * factory's fakes when it is created, so one made with the client would
 * miss a fake registered after the client was resolved.
 *
 * @internal
 */
final readonly class LaravelHttpClient implements ClientInterface
{
    /**
     * @param float $timeout seconds a request may take, end to end
     * @param float $connectTimeout seconds to establish a connection
     */
    public function __construct(
        private Factory $http,
        private float $timeout = Config::DEFAULT_TIMEOUT,
        private float $connectTimeout = Config::DEFAULT_CONNECT_TIMEOUT,
    ) {}

    /**
     * Headers Guzzle sets again from the URL; sent twice, a server refuses
     * the request. Compared in lower case, as header names are.
     */
    private const array DERIVED_HEADERS = ['host'];

    /**
     * The application's own credentials, which may sit in Laravel's global
     * options for its own services. The SDK sets every credential its
     * requests need, so these never travel to the site or its issuer.
     */
    private const array CREDENTIAL_HEADERS = ['Authorization', 'Proxy-Authorization', 'Cookie'];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (!in_array(strtolower($name), self::DERIVED_HEADERS, true)) {
                $headers[self::canonicalName($name)] = $values;
            }
        }

        // PSR-18 hands back every response, redirects and errors included,
        // for the SDK to judge; Laravel's client throws on neither unless
        // told to, and follows redirects unless told not to.
        //
        // The headers are settled on the outgoing request itself, after the
        // global options have been merged in: each of the request's headers
        // replaces a global one of that name in any case, and global
        // credentials are dropped whether or not the request carries one.
        $pending = $this->http->createPendingRequest()
            ->withRequestMiddleware(static function (RequestInterface $outgoing) use ($headers): RequestInterface {
                foreach ([...self::CREDENTIAL_HEADERS, ...array_keys($headers)] as $name) {
                    $outgoing = $outgoing->withoutHeader($name);
                }
                foreach ($headers as $name => $values) {
                    $outgoing = $outgoing->withHeader($name, $values);
                }

                return $outgoing;
            })
            ->withoutRedirecting()
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        // The body goes in as Guzzle's raw `body` option rather than through
        // withBody(), which adds a Content-Type of its own: the request's
        // Content-Type, whatever the case of its name, is then the only one
        // sent, and a request without one is sent without one.
        $body = (string) $request->getBody();
        $options = $body === '' ? [] : ['body' => $body];

        try {
            return $pending->send($request->getMethod(), (string) $request->getUri(), $options)->toPsrResponse();
        } catch (ConnectionException $exception) {
            throw new NetworkException($exception->getMessage(), $request, $exception);
        } catch (RequestException $exception) {
            // Thrown on an error status only when a global middleware or
            // option tells Laravel's client to; PSR-18 hands every response
            // back for the SDK to judge.
            return $exception->response->toPsrResponse();
        } catch (StrayRequestException|HttpClientException $exception) {
            // Anything else Laravel's client throws means the request was
            // not sent at all; the SDK catches only PSR-18 exceptions.
            throw new ClientException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * `content-type` as `Content-Type`: Laravel 12's Request::header() and
     * hasHeader(), which Http::assertSent() callbacks use, match header
     * names case-sensitively, so a name in another case would escape an
     * application's assertions about the SDK's requests.
     */
    private static function canonicalName(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', strtolower($name))));
    }
}
