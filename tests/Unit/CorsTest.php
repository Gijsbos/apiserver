<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;

/**
 * CorsTest
 *  Cors::handle() decides two things: whether the request is a preflight that must
 *  short-circuit (return value) and which headers to emit. The PHP CLI keeps no
 *  response headers to inspect, so these tests cover the decision (return value)
 *  and use ob_start() to keep header() calls from tripping "headers already sent".
 */
final class CorsTest extends TestCase
{
    use IsolatesGlobalState;

    private function handle(Cors $cors, string $method, ?string $origin) : bool
    {
        Server::simulateRequest($method, "/anything", [], $origin === null ? [] : ["Origin" => $origin]);

        $server = new Server();

        ob_start();

        try
        {
            return $cors->handle($server);
        }
        finally
        {
            ob_end_clean();
        }
    }

    public function testDefaults()
    {
        $cors = new Cors();

        $this->assertSame([], $cors->allowedOrigins);
        $this->assertSame(["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"], $cors->allowedMethods);
        $this->assertSame(["Authorization", "Content-Type"], $cors->allowedHeaders);
        $this->assertFalse($cors->allowCredentials);
        $this->assertSame(86400, $cors->maxAgeSeconds);
    }

    public function testRequestWithoutOriginIsNotHandled()
    {
        $this->assertFalse($this->handle(new Cors(["https://app.example.com"]), "OPTIONS", null));
    }

    public function testDisallowedOriginPreflightIsNotShortCircuited()
    {
        $this->assertFalse($this->handle(new Cors(["https://app.example.com"]), "OPTIONS", "https://evil.example.com"));
    }

    public function testNoAllowedOriginsMeansNothingIsAllowed()
    {
        $this->assertFalse($this->handle(new Cors(), "OPTIONS", "https://app.example.com"));
    }

    public function testAllowedOriginPreflightIsShortCircuited()
    {
        $this->assertTrue($this->handle(new Cors(["https://app.example.com"]), "OPTIONS", "https://app.example.com"));
    }

    public function testAllowedOriginActualRequestContinuesToTheRoute()
    {
        $this->assertFalse($this->handle(new Cors(["https://app.example.com"]), "GET", "https://app.example.com"));
        $this->assertFalse($this->handle(new Cors(["https://app.example.com"]), "POST", "https://app.example.com"));
    }

    public function testWildcardAllowsAnyOrigin()
    {
        $cors = new Cors(["*"]);

        $this->assertTrue($this->handle($cors, "OPTIONS", "https://one.example.com"));
        $this->assertTrue($this->handle($cors, "OPTIONS", "https://two.example.org"));
    }

    public function testOriginMatchIsExact()
    {
        $cors = new Cors(["https://app.example.com"]);

        $this->assertFalse($this->handle($cors, "OPTIONS", "https://app.example.com.evil.io"));
        $this->assertFalse($this->handle($cors, "OPTIONS", "http://app.example.com"));
        $this->assertFalse($this->handle($cors, "OPTIONS", "https://APP.example.com"));
    }

    public function testCredentialedWildcardStillHandlesPreflight()
    {
        $this->assertTrue($this->handle(new Cors(["*"], allowCredentials: true), "OPTIONS", "https://app.example.com"));
    }

    public function testMethodComparisonIsCaseInsensitiveViaServer()
    {
        $this->assertTrue($this->handle(new Cors(["*"]), "options", "https://app.example.com"));
    }
}
