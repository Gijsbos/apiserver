<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ServerHeadersTest
 *  Request and correlation ids, the response headers, trusted proxies and content negotiation.
 *  The PHP CLI keeps no response headers, so the headers are tested through getResponseHeaders().
 */
final class ServerHeadersTest extends TestCase
{
    use IsolatesGlobalState;

    const UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
    const TRACE_ID = "4bf92f3577b34da6a3ce929d0e0e4736";

    private static function server(array $headers = [], array $serverVars = [], array $opts = []) : Server
    {
        Server::simulateRequest("GET", "/anything", [], $headers);

        foreach($serverVars as $key => $value)
            $_SERVER[$key] = $value;

        return new Server($opts);
    }

    // request id

    public function testRequestIdIsAGeneratedUuid() : void
    {
        $this->assertMatchesRegularExpression(self::UUID4, self::server()->getRequestId());
    }

    public function testRequestIdIsNeverTakenFromTheClient() : void
    {
        $server = self::server(["X-Request-Id" => "client-chosen-request-id"]);

        $this->assertMatchesRegularExpression(self::UUID4, $server->getRequestId());
    }

    public function testEveryRequestHasItsOwnId() : void
    {
        $this->assertNotSame(self::server()->getRequestId(), self::server()->getRequestId());
    }

    // correlation id

    public function testNewChainUsesTheRequestId() : void
    {
        $server = self::server();

        $this->assertSame($server->getRequestId(), $server->getCorrelationId());
    }

    public function testTraceparentContinuesTheChain() : void
    {
        $server = self::server(["traceparent" => "00-" . self::TRACE_ID . "-00f067aa0ba902b7-01"]);

        $this->assertSame(self::TRACE_ID, $server->getCorrelationId());
    }

    public function testCorrelationIdHeaderContinuesTheChain() : void
    {
        $this->assertSame("order-flow-12345", self::server(["X-Correlation-ID" => "order-flow-12345"])->getCorrelationId());
    }

    public function testRequestIdOfTheCallerContinuesTheChain() : void
    {
        $this->assertSame("caller-request-id-1", self::server(["X-Request-Id" => "caller-request-id-1"])->getCorrelationId());
    }

    public function testTraceparentTakesPrecedence() : void
    {
        $server = self::server([
            "traceparent" => "00-" . self::TRACE_ID . "-00f067aa0ba902b7-01",
            "X-Correlation-ID" => "order-flow-12345",
        ]);

        $this->assertSame(self::TRACE_ID, $server->getCorrelationId());
    }

    public static function invalidCorrelationHeaders() : array
    {
        return [
            "all zero trace-id" => [["traceparent" => "00-" . str_repeat("0", 32) . "-00f067aa0ba902b7-01"]],
            "version ff" => [["traceparent" => "ff-" . self::TRACE_ID . "-00f067aa0ba902b7-01"]],
            "malformed traceparent" => [["traceparent" => "not-a-traceparent"]],
            "line break (log injection)" => [["X-Correlation-ID" => "abcdefgh\r\nfake log line"]],
            "too long" => [["X-Correlation-ID" => str_repeat("a", 129)]],
            "too short" => [["X-Correlation-ID" => "abc"]],
            "markup" => [["X-Correlation-ID" => "<script>alert(1)</script>"]],
        ];
    }

    #[DataProvider("invalidCorrelationHeaders")]
    public function testInvalidCorrelationHeaderStartsANewChain(array $headers) : void
    {
        $server = self::server($headers);

        $this->assertSame($server->getRequestId(), $server->getCorrelationId());
    }

    // response headers

    public function testResponseHeaders() : void
    {
        $server = self::server(["X-Correlation-ID" => "order-flow-12345"]);

        $headers = $server->getResponseHeaders();

        $this->assertSame("nosniff", $headers["X-Content-Type-Options"]);
        $this->assertSame("no-store", $headers["Cache-Control"]);
        $this->assertSame("default-src 'none'; frame-ancestors 'none'", $headers["Content-Security-Policy"]);
        $this->assertSame($server->getRequestId(), $headers["X-Request-Id"]);
        $this->assertSame("order-flow-12345", $headers["X-Correlation-ID"]);
    }

    public function testStrictTransportSecurityOnlyOverHttps() : void
    {
        $this->assertArrayNotHasKey("Strict-Transport-Security", self::server()->getResponseHeaders());
        $this->assertSame("max-age=31536000", self::server([], ["HTTPS" => "on"])->getResponseHeaders()["Strict-Transport-Security"]);
    }

    // trusted proxies

    public function testForwardedProtoOfAClientIsIgnored() : void
    {
        $server = self::server(["X-Forwarded-Proto" => "https"], ["REMOTE_ADDR" => "203.0.113.7"], ["trustedProxies" => ["10.0.0.0/8"]]);

        $this->assertArrayNotHasKey("Strict-Transport-Security", $server->getResponseHeaders());
    }

    public function testForwardedProtoOfATrustedProxyCounts() : void
    {
        $server = self::server(["X-Forwarded-Proto" => "https"], ["REMOTE_ADDR" => "10.1.2.3"], ["trustedProxies" => ["10.0.0.0/8"]]);

        $this->assertArrayHasKey("Strict-Transport-Security", $server->getResponseHeaders());
    }

    public function testClientIpIsTheRemoteAddressWithoutTrustedProxy() : void
    {
        $server = self::server(["X-Forwarded-For" => "198.51.100.1"], ["REMOTE_ADDR" => "203.0.113.7"]);

        $this->assertSame("203.0.113.7", $server->getClientIp());
    }

    public function testClientIpIsReadFromTrustedProxy() : void
    {
        // The client prepended a fake address, the entry added by the trusted proxy is the real client
        $server = self::server(["X-Forwarded-For" => "1.1.1.1, 198.51.100.1"], ["REMOTE_ADDR" => "10.1.2.3"], ["trustedProxies" => ["10.0.0.0/8"]]);

        $this->assertSame("198.51.100.1", $server->getClientIp());
    }

    public function testClientIpSkipsChainedTrustedProxies() : void
    {
        $server = self::server(["X-Forwarded-For" => "198.51.100.1, 10.9.9.9"], ["REMOTE_ADDR" => "10.1.2.3"], ["trustedProxies" => ["10.0.0.0/8"]]);

        $this->assertSame("198.51.100.1", $server->getClientIp());
    }

    public function testTrustedProxyMatchesIpv6Range() : void
    {
        $server = self::server(["X-Forwarded-For" => "2001:db8::1"], ["REMOTE_ADDR" => "fd00::5"], ["trustedProxies" => ["fd00::/8"]]);

        $this->assertSame("2001:db8::1", $server->getClientIp());
    }

    // content negotiation

    public static function acceptHeaders() : array
    {
        return [
            "no header" => [null, "json"],
            "any type" => ["*/*", "json"],
            "json" => ["application/json", "json"],
            "xml" => ["application/xml", "xml"],
            "text/xml" => ["text/xml", "xml"],
            "problem+json" => ["application/problem+json", "json"],
            "problem+xml" => ["application/problem+xml", "xml"],
            "xml preferred by quality" => ["application/json;q=0.5, application/xml", "xml"],
            "json preferred by quality" => ["application/xml;q=0.5, application/json", "json"],
            "equal quality, first listed" => ["application/xml, application/json", "xml"],
            "xml refused" => ["application/xml;q=0", "json"],
            "unknown type" => ["text/html", "json"],
            "browser navigation" => ["text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8", "json"],
            "browser navigation, xml first" => ["application/xml,text/html;q=0.9", "json"],
        ];
    }

    #[DataProvider("acceptHeaders")]
    public function testNegotiatesFormatOnAccept(null|string $accept, string $format) : void
    {
        $this->assertSame($format, Server::negotiateFormat($accept));
    }

    // fixed format

    public function testRouteWithResponseFormatIgnoresAccept() : void
    {
        $server = $this->dispatch("GET", "/params/json-only", ["Accept" => "application/xml"])["server"];

        $this->assertSame("json", $server->getResponseFormat());
    }

    public function testRouteWithoutResponseFormatFollowsAccept() : void
    {
        $server = $this->dispatch("GET", "/params/escape", ["Accept" => "application/xml"])["server"];

        $this->assertSame("xml", $server->getResponseFormat());
    }

    public function testUnsupportedResponseFormatIsRefused() : void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \gijsbos\ApiServer\Attributes\ResponseFormat("yaml");
    }
}
