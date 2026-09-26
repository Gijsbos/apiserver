<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Authorization\AuthorizationHeaderVerifier;
use gijsbos\Http\Exceptions\InternalServerErrorException;
use gijsbos\Http\Exceptions\UnauthorizedException;

final class SecurityContextTest extends TestCase
{
    use IsolatesGlobalState;

    private function serverFor(string $uri, array $opts = []) : Server
    {
        Server::simulateRequest("GET", $uri);

        return new Server($opts);
    }

    private function acceptingVerifier() : AuthorizationHeaderVerifier
    {
        return new AuthorizationHeaderVerifier(viaBearer: fn($token) => $token === "good");
    }

    // ---- requiresAuth (rule matching) ----

    public function testUnmatchedPathsRequireAuthByDefault()
    {
        $this->assertTrue((new SecurityContext())->requiresAuth("anything/at/all"));
    }

    public function testPermitAllExemptsExactPath()
    {
        $context = (new SecurityContext())->permitAll("/health");

        $this->assertFalse($context->requiresAuth("health"));
        $this->assertTrue($context->requiresAuth("healthz"));
        $this->assertTrue($context->requiresAuth("health/deep"));
    }

    public function testLeadingSlashIsNormalizedOnBothPatternAndPath()
    {
        $context = (new SecurityContext())->permitAll("health", "/status");

        $this->assertFalse($context->requiresAuth("/health"));
        $this->assertFalse($context->requiresAuth("health"));
        $this->assertFalse($context->requiresAuth("status"));
    }

    public function testDoubleStarMatchesAcrossSegments()
    {
        $context = (new SecurityContext())->permitAll("/public/**");

        $this->assertFalse($context->requiresAuth("public/a"));
        $this->assertFalse($context->requiresAuth("public/a/b/c"));
        $this->assertTrue($context->requiresAuth("private/a"));
    }

    public function testSingleStarMatchesOneSegmentOnly()
    {
        $context = (new SecurityContext())->permitAll("/users/*/avatar");

        $this->assertFalse($context->requiresAuth("users/42/avatar"));
        $this->assertTrue($context->requiresAuth("users/42/other/avatar"));
        $this->assertTrue($context->requiresAuth("users/avatar"));
    }

    public function testPatternsAreLiteralApartFromWildcards()
    {
        $context = (new SecurityContext())->permitAll("/v1.0/ping");

        $this->assertFalse($context->requiresAuth("v1.0/ping"));
        $this->assertTrue($context->requiresAuth("v1x0/ping"), "'.' must not act as a regex wildcard");
    }

    public function testFirstMatchingRuleWins()
    {
        $context = (new SecurityContext())
            ->permitAll("/user/login")
            ->requireAuth("/user/**");

        $this->assertFalse($context->requiresAuth("user/login"));
        $this->assertTrue($context->requiresAuth("user/profile"));
    }

    public function testRegistrationOrderMattersWhenGeneralRuleComesFirst()
    {
        $context = (new SecurityContext())
            ->requireAuth("/user/**")
            ->permitAll("/user/login");

        $this->assertTrue($context->requiresAuth("user/login"));
    }

    public function testRulesAreChainable()
    {
        $context = new SecurityContext();

        $this->assertSame($context, $context->permitAll("/a"));
        $this->assertSame($context, $context->requireAuth("/b"));
    }

    // ---- authenticate ----

    public function testPermittedPathNeedsNoCredentials()
    {
        $context = (new SecurityContext())->permitAll("/health");

        $context->authenticate($this->serverFor("/health"));

        $this->addToAssertionCount(1); // no exception
    }

    public function testProtectedPathWithoutHeaderIsRejected()
    {
        $context = new SecurityContext();

        $e = $this->assertHttpError(UnauthorizedException::class, "authorizationRequired", fn() => $context->authenticate($this->serverFor("/user/me")));

        $this->assertSame(401, $e->getStatusCode());
    }

    public function testValidCredentialsAreDelegatedToVerifier()
    {
        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer good";

        $server = $this->serverFor("/user/me", ["authorizationHeaderVerifier" => $this->acceptingVerifier()]);

        (new SecurityContext())->authenticate($server);

        $this->addToAssertionCount(1); // no exception
    }

    public function testInvalidCredentialsAreRejectedByVerifier()
    {
        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer bad";

        $server = $this->serverFor("/user/me", ["authorizationHeaderVerifier" => $this->acceptingVerifier()]);

        $this->assertHttpError(UnauthorizedException::class, "tokenInvalid", fn() => (new SecurityContext())->authenticate($server));
    }

    public function testMalformedHeaderIsRejectedBeforeVerification()
    {
        $_SERVER["HTTP_AUTHORIZATION"] = "Digest nope";

        $server = $this->serverFor("/user/me", ["authorizationHeaderVerifier" => $this->acceptingVerifier()]);

        $this->assertHttpError(UnauthorizedException::class, "authorizationHeaderInvalid", fn() => (new SecurityContext())->authenticate($server));
    }

    public function testSecondCallOnSameInstanceIsSkippedOnceAuthenticated()
    {
        // The same context can be registered for both before- and after-route-resolution;
        // the "executed" flag stops the verifier running twice for one request.
        $calls = 0;
        $verifier = new AuthorizationHeaderVerifier(viaBearer: function() use (&$calls) {
            $calls++;
            return true;
        });

        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer good";
        $server = $this->serverFor("/user/me", ["authorizationHeaderVerifier" => $verifier]);

        $context = new SecurityContext();
        $context->authenticate($server);
        $context->authenticate($server);

        $this->assertSame(1, $calls);
    }

    public function testAuthenticatedStateDoesNotLeakIntoTheNextRequest()
    {
        // Server::$securityContext is static, so one instance serves every request
        // in a long-running worker. Request 2 must not inherit request 1's success.
        $context = new SecurityContext();

        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer good";
        $context->authenticate($this->serverFor("/user/me", ["authorizationHeaderVerifier" => $this->acceptingVerifier()]));

        unset($_SERVER["HTTP_AUTHORIZATION"]);
        $secondRequest = $this->serverFor("/user/me", ["authorizationHeaderVerifier" => $this->acceptingVerifier()]);

        $this->assertHttpError(UnauthorizedException::class, "authorizationRequired", fn() => $context->authenticate($secondRequest));
    }

    public function testFailedAuthenticationIsNotCachedAsExecuted()
    {
        $context = new SecurityContext();
        $server = $this->serverFor("/user/me");

        // First attempt fails (no header) ...
        $this->assertHttpError(UnauthorizedException::class, "authorizationRequired", fn() => $context->authenticate($server));

        // ... and a retry must be evaluated afresh, not waved through
        $this->assertHttpError(UnauthorizedException::class, "authorizationRequired", fn() => $context->authenticate($server));
    }

    public function testCredentialsWithoutAVerifierFailClosedWithAServerError()
    {
        // Nothing can vouch for the credential, so it must not be waved through.
        // A setup mistake is a 500, not a 401: the client did nothing wrong.
        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer literally-anything";

        $context = new SecurityContext();
        $server = $this->serverFor("/user/me");

        $e = $this->assertHttpError(InternalServerErrorException::class, "authenticationNotConfigured", fn() => $context->authenticate($server));

        $this->assertSame(500, $e->getStatusCode());
        $this->assertStringContainsString("AuthorizationHeaderVerifier", $e->getErrorDescription());
    }

    public function testMissingCredentialsAreStillA401EvenWithoutAVerifier()
    {
        $this->assertHttpError(UnauthorizedException::class, "authorizationRequired", fn() => (new SecurityContext())->authenticate($this->serverFor("/user/me")));
    }

    public function testPermittedPathsNeedNoVerifier()
    {
        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer whatever";

        (new SecurityContext())->permitAll("/health")->authenticate($this->serverFor("/health"));

        $this->addToAssertionCount(1); // no exception
    }

    public function testMisconfiguredRequestIsNotRememberedAsAuthenticated()
    {
        $_SERVER["HTTP_AUTHORIZATION"] = "Bearer anything";

        $context = new SecurityContext();
        $server = $this->serverFor("/user/me");

        $this->assertHttpError(InternalServerErrorException::class, "authenticationNotConfigured", fn() => $context->authenticate($server));
        $this->assertHttpError(InternalServerErrorException::class, "authenticationNotConfigured", fn() => $context->authenticate($server));
    }
}
