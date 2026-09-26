<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use gijsbos\ApiServer\Authorization\AuthorizationHeaderVerifier;
use gijsbos\Http\Exceptions\ForbiddenException;
use gijsbos\Http\Exceptions\UnauthorizedException;

/**
 * ServerBehaviorTest
 *  Drives Server::listen() end to end. Query-string and body parameters can't be
 *  simulated in the CLI (RequestParam reads them through filter_input()/php://input),
 *  so parameter handling is exercised through path variables and request headers.
 */
final class ServerBehaviorTest extends TestCase
{
    use IsolatesGlobalState;

    protected function setUp() : void
    {
        \TestAllowAuthorityCheck::$calls = [];
    }

    // ---- routing ----

    public function testKnownRouteReturnsItsResult()
    {
        $r = $this->dispatch("GET", "/foo/hi");

        $this->assertSame(["result" => "testRoute7"], $r["result"]);
        $this->assertSame("", $r["body"], "listen(false) returns the data instead of printing it");
    }

    public function testPathVariablesAreExtractedByName()
    {
        $r = $this->dispatch("GET", "/foo/x/bar/y/");

        $this->assertSame(["result" => "testRoute5"], $r["result"]);
        $this->assertSame(["a" => "x", "b" => "y"], $r["server"]->getRoute()->getPathVariables());
    }

    public function testQueryStringDoesNotAffectRouting()
    {
        $r = $this->dispatch("GET", "/foo/hi?x=1&y=2");

        $this->assertSame(["result" => "testRoute7"], $r["result"]);
        $this->assertSame("foo/hi", $r["server"]->getRequestURI());
    }

    public function testRequestUriHasNoLeadingSlashAndMethodIsUppercased()
    {
        Server::simulateRequest("get", "/foo/hi");
        $server = new Server();

        $this->assertSame("foo/hi", $server->getRequestURI());
        $this->assertSame("GET", $server->getRequestMethod());
    }

    public function testPathPrefixIsStrippedFromTheRequestUri()
    {
        $r = $this->dispatch("GET", "/api/foo/hi", [], ["pathPrefix" => "api"]);

        $this->assertSame("/api/", $r["server"]->getPathPrefix());
        $this->assertSame(["result" => "testRoute7"], $r["result"]);
    }

    public function testUnknownPathIsA404()
    {
        $r = $this->dispatch("GET", "/nope/nothing");

        $this->assertNull($r["result"]);
        $this->assertSame(404, $r["status"]);
        $this->assertSame(["statusCode" => 404, "error" => "routeNotFound", "errorDescription" => "Route does not exist"], $r["json"]);
    }

    public function testRequestMethodWithoutAnyRoutesIsA404()
    {
        $r = $this->dispatch("PATCH", "/foo/hi");

        $this->assertSame(404, $r["status"]);
        $this->assertSame("routeNotFound", $r["json"]["error"]);
    }

    public function testRightPathWrongMethodIsA404()
    {
        $r = $this->dispatch("POST", "/foo/hi");

        $this->assertSame(404, $r["status"]);
    }

    public function testSimulateRequestSetsServerGlobals()
    {
        Server::simulateRequest("post", "no/leading/slash", [], ["Token" => "abc", "http_other" => "x"]);

        $this->assertSame("POST", $_SERVER["REQUEST_METHOD"]);
        $this->assertSame("/no/leading/slash", $_SERVER["REQUEST_URI"]);
        $this->assertSame("abc", $_SERVER["HTTP_TOKEN"]);
        $this->assertSame("x", $_SERVER["HTTP_OTHER"]);
    }

    // ---- https ----

    public function testHttpsIsNotRequiredByDefault()
    {
        $r = $this->dispatch("GET", "/foo/hi");

        $this->assertSame(["result" => "testRoute7"], $r["result"]);
    }

    public function testPlainHttpIsRejectedWhenHttpsIsRequired()
    {
        $r = $this->dispatch("GET", "/foo/hi", [], ["requireHttps" => true]);

        $this->assertSame(426, $r["status"]);
        $this->assertSame("httpsRequired", $r["json"]["error"]);
    }

    public function testHttpsFlagSatisfiesRequireHttps()
    {
        $_SERVER["HTTPS"] = "on";

        $this->assertSame(["result" => "testRoute7"], $this->dispatch("GET", "/foo/hi", [], ["requireHttps" => true])["result"]);
    }

    public function testPort443SatisfiesRequireHttps()
    {
        $_SERVER["SERVER_PORT"] = 443;

        $this->assertSame(["result" => "testRoute7"], $this->dispatch("GET", "/foo/hi", [], ["requireHttps" => true])["result"]);
    }

    public function testHttpsOffDoesNotSatisfyRequireHttps()
    {
        $_SERVER["HTTPS"] = "off";

        $this->assertSame(426, $this->dispatch("GET", "/foo/hi", [], ["requireHttps" => true])["status"]);
    }

    // ---- parameter handling ----

    public function testIntPathVariableIsConvertedToInt()
    {
        $r = $this->dispatch("GET", "/params/int/5");

        $this->assertSame(["id" => 5, "type" => "integer"], $r["result"]);
    }

    public function testNonNumericPathVariableIsRejectedForIntParam()
    {
        $r = $this->dispatch("GET", "/params/int/abc");

        $this->assertSame(400, $r["status"]);
        $this->assertSame("idInvalid", $r["json"]["error"]);
    }

    public function testPathVariableBoundsAreEnforced()
    {
        $this->assertSame("idValueMinExceeded", $this->dispatch("GET", "/params/int/0")["json"]["error"]);
        $this->assertSame("idValueMaxExceeded", $this->dispatch("GET", "/params/int/101")["json"]["error"]);
        $this->assertSame(["id" => 100, "type" => "integer"], $this->dispatch("GET", "/params/int/100")["result"]);
    }

    public function testFloatPathVariable()
    {
        $r = $this->dispatch("GET", "/params/float/1.5");

        $this->assertSame(["ratio" => 1.5, "type" => "double"], $r["result"]);
        $this->assertSame("ratioValueMaxExceeded", $this->dispatch("GET", "/params/float/2.5")["json"]["error"]);
    }

    public function testHeaderParameterIsReadFromTheRequest()
    {
        $r = $this->dispatch("GET", "/params/header", ["Token" => "abc"]);

        $this->assertSame(["token" => "abc"], $r["result"]);
    }

    public function testMissingOptionalHeaderFallsBackToItsDefault()
    {
        $r = $this->dispatch("GET", "/params/header");

        $this->assertSame(["token" => "none"], $r["result"]);
    }

    public function testBoolHeaderIsConverted()
    {
        $this->assertSame(["flag" => true], $this->dispatch("GET", "/params/bool", ["Flag" => "1"])["result"]);
        $this->assertSame(["flag" => false], $this->dispatch("GET", "/params/bool", ["Flag" => "0"])["result"]);
        $this->assertSame("flagInvalid", $this->dispatch("GET", "/params/bool", ["Flag" => "yes"])["json"]["error"]);
    }

    public function testMissingRequiredParameterIsA400()
    {
        $r = $this->dispatch("GET", "/params/required");

        $this->assertSame(400, $r["status"]);
        $this->assertSame("nameInputInvalid", $r["json"]["error"]);
    }

    public function testMissingOptionalParameterFallsBackToItsDefault()
    {
        $this->assertSame(["name" => "john"], $this->dispatch("GET", "/params/default")["result"]);
    }

    public function testOptRequestParamIsOptional()
    {
        $this->assertSame(["note" => ""], $this->dispatch("GET", "/params/optional")["result"]);
    }

    public function testJsonCustomTypeDecodesTheValue()
    {
        $r = $this->dispatch("GET", '/params/json/{"a":1,"b":[2,3]}');

        $this->assertSame(["decoded" => ["a" => 1, "b" => [2, 3]]], $r["result"]);
        $this->assertSame(["decoded" => [1, 2]], $this->dispatch("GET", "/params/json/[1,2]")["result"]);
    }

    public function testInvalidJsonForJsonCustomTypeIsA400()
    {
        $r = $this->dispatch("GET", '/params/json/{oops');

        $this->assertSame(400, $r["status"]);
        $this->assertSame("jsonInputInvalid", $r["json"]["error"]);
    }

    public function testBase64CustomTypeDecodesTheValue()
    {
        $r = $this->dispatch("GET", "/params/base64/" . base64_encode("hello world"));

        $this->assertSame(["decoded" => "hello world"], $r["result"]);
    }

    public function testEnumParameterIsResolvedToItsCase()
    {
        $r = $this->dispatch("GET", "/params/enum/hearts");

        $this->assertSame(["suit" => "hearts"], $r["result"]);
    }

    public function testInvalidEnumValueIsA400()
    {
        $r = $this->dispatch("GET", "/params/enum/clubs");

        $this->assertSame(400, $r["status"]);
        $this->assertSame("suitValueInvalid", $r["json"]["error"]);
    }

    // ---- response processing ----

    public function testObjectsAreConvertedToPublicProperties()
    {
        $r = $this->dispatch("GET", "/params/objects", [], ["dateTimeFormat" => "Y-m-d H:i:s"]);

        $this->assertSame("2024-01-02 03:04:05", $r["result"]["date"]);
        $this->assertSame(["name" => "dto", "count" => 3], $r["result"]["dto"], "private properties are not exposed");
        $this->assertSame(["name" => "dto", "count" => 3], $r["result"]["nested"]["dto"], "nested objects are converted too");
    }

    public function testDatesDefaultToIso8601()
    {
        $r = $this->dispatch("GET", "/params/objects");

        $this->assertMatchesRegularExpression('/^2024-01-0[12]T\d\d:\d\d:\d\d[+-]\d\d:\d\d$/', $r["result"]["date"]);
    }

    public function testStringsAreHtmlEscapedByDefault()
    {
        $r = $this->dispatch("GET", "/params/escape");

        $this->assertSame(["html" => '&lt;b&gt;&quot;hi&quot;&lt;/b&gt; &amp; bye', "count" => 3], $r["result"]);
    }

    public function testEscapingCanBeDisabled()
    {
        $r = $this->dispatch("GET", "/params/escape", [], ["escapeResult" => false]);

        $this->assertSame(['html' => '<b>"hi"</b> & bye', "count" => 3], $r["result"]);
    }

    public function testReturnFilterRemovesUnlistedKeys()
    {
        $r = $this->dispatch("GET", "/params/filtered");

        $this->assertSame(["name" => "a"], $r["result"]);
    }

    public function testRouteStatusCodeIsTakenFromTheAttribute()
    {
        $this->assertSame(201, $this->dispatch("GET", "/params/created")["server"]->getRoute()->getStatusCode());
        $this->assertSame(200, $this->dispatch("GET", "/foo/hi")["server"]->getRoute()->getStatusCode());
    }

    // ---- timing ----

    public function testTimingIsNotAddedByDefault()
    {
        $result = $this->dispatch("GET", "/foo/hi")["result"];

        $this->assertArrayNotHasKey("serverTime", $result);
        $this->assertArrayNotHasKey("requestTime", $result);
    }

    public function testTimingCanBeAddedToTheResponse()
    {
        $result = $this->dispatch("GET", "/foo/hi", [], ["addServerTime" => true, "addRequestTime" => true])["result"];

        $this->assertIsFloat($result["serverTime"]);
        $this->assertGreaterThanOrEqual(0.0, $result["serverTime"]);
        $this->assertSame($_SERVER["REQUEST_TIME_FLOAT"], $result["requestTime"]);
    }

    public function testRequestTimeAccessors()
    {
        $r = $this->dispatch("GET", "/foo/hi");
        $server = $r["server"];

        $this->assertIsFloat($server->getRequestStartTime());
        $this->assertGreaterThanOrEqual($server->getRequestStartTime(), $server->getRequestEndTime());
        $this->assertSame($_SERVER["REQUEST_TIME_FLOAT"], $server->getRequestTime());
        $this->assertGreaterThanOrEqual(0.0, $server->getRequestDuration());
        $this->assertGreaterThanOrEqual(0.0, $server->getServerTime());
    }

    public function testRequestEndTimeIsNullBeforeTheRequestCompletes()
    {
        Server::simulateRequest("GET", "/foo/hi");

        $this->assertNull((new Server())->getRequestEndTime());
    }

    public function testRequestTimeAccessorsAreNullWithoutRequestTimeFloat()
    {
        unset($_SERVER["REQUEST_TIME_FLOAT"]);
        Server::simulateRequest("GET", "/foo/hi");
        $server = new Server();

        $this->assertNull($server->getRequestTime());
        $this->assertNull($server->getRequestDuration());
    }

    // ---- errors and exception handlers ----

    public function testUnexpectedExceptionsBecomeA500()
    {
        $r = $this->dispatch("GET", "/params/boom");

        $this->assertSame(500, $r["status"]);
        $this->assertSame(["error" => "RuntimeException", "errorDescription" => "boom", "statusCode" => 500], $r["json"]);
    }

    public function testCustomExceptionHandlerCanTranslateAnException()
    {
        Server::addExceptionHandler(RuntimeException::class, fn(RuntimeException $e) => new ForbiddenException("mapped", "mapped: " . $e->getMessage()));

        $r = $this->dispatch("GET", "/params/boom");

        $this->assertSame(403, $r["status"]);
        $this->assertSame("mapped", $r["json"]["error"]);
        $this->assertSame("mapped: boom", $r["json"]["errorDescription"]);
    }

    public function testExceptionHandlerOnlyAppliesToItsExactClass()
    {
        Server::addExceptionHandler(\LogicException::class, fn($e) => new ForbiddenException("mapped", "nope"));

        $r = $this->dispatch("GET", "/params/boom");

        $this->assertSame(500, $r["status"]);
        $this->assertSame("RuntimeException", $r["json"]["error"]);
    }

    // ---- handlers ----

    public function testBeforeRequestHandlersRunInOrderBeforeRouteResolution()
    {
        $log = [];

        Server::addBeforeRequestHandler(function(Server $server) use (&$log) { $log[] = ["first", $server->getRoute()]; });
        Server::addBeforeRequestHandler(function(Server $server) use (&$log) { $log[] = ["second", $server->getRoute()]; });

        $this->dispatch("GET", "/foo/hi");

        $this->assertSame([["first", null], ["second", null]], $log);
    }

    public function testBeforeRequestHandlerCanAbortTheRequest()
    {
        Server::addBeforeRequestHandler(function() { throw new UnauthorizedException("blocked", "Blocked by handler"); });

        $r = $this->dispatch("GET", "/foo/hi");

        $this->assertNull($r["result"]);
        $this->assertSame(401, $r["status"]);
        $this->assertSame("blocked", $r["json"]["error"]);
    }

    public function testResponseHandlerReceivesTheResponseAndServer()
    {
        $received = null;

        Server::setResponseHandler(function(array $response, Server $server) use (&$received) { $received = [$response, $server]; });

        $r = $this->dispatch("GET", "/foo/hi");

        $this->assertSame(["result" => "testRoute7"], $received[0]);
        $this->assertSame($r["server"], $received[1]);
    }

    public function testResponseHandlerIsNotCalledWhenTheRequestFails()
    {
        $called = false;

        Server::setResponseHandler(function() use (&$called) { $called = true; });

        $this->dispatch("GET", "/nope");

        $this->assertFalse($called);
    }

    // ---- security context ----

    private function bearerVerifier() : AuthorizationHeaderVerifier
    {
        return new AuthorizationHeaderVerifier(viaBearer: fn($token) => $token === "good");
    }

    public function testPermittedPathsSkipAuthentication()
    {
        Server::$securityContext = (new SecurityContext())->permitAll("/foo/**");

        $this->assertSame(["result" => "testRoute7"], $this->dispatch("GET", "/foo/hi")["result"]);
    }

    public function testProtectedPathWithoutCredentialsIsA401()
    {
        Server::$securityContext = (new SecurityContext())->permitAll("/foo/**");

        $r = $this->dispatch("GET", "/account/abc/");

        $this->assertNull($r["result"]);
        $this->assertSame(401, $r["status"]);
        $this->assertSame("authorizationRequired", $r["json"]["error"]);
    }

    public function testProtectedPathWithValidCredentialsIsServed()
    {
        Server::$securityContext = new SecurityContext();

        $r = $this->dispatch("GET", "/account/abc/", ["Authorization" => "Bearer good"], ["authorizationHeaderVerifier" => $this->bearerVerifier()]);

        $this->assertSame(["result" => "testRoute8"], $r["result"]);
    }

    public function testProtectedPathWithInvalidCredentialsIsA401()
    {
        Server::$securityContext = new SecurityContext();

        $r = $this->dispatch("GET", "/account/abc/", ["Authorization" => "Bearer bad"], ["authorizationHeaderVerifier" => $this->bearerVerifier()]);

        $this->assertSame(401, $r["status"]);
        $this->assertSame("tokenInvalid", $r["json"]["error"]);
    }

    public function testCredentialsOnAProtectedPathWithoutAVerifierAreA500()
    {
        Server::$securityContext = new SecurityContext();

        $r = $this->dispatch("GET", "/account/abc/", ["Authorization" => "Bearer anything"]);

        $this->assertNull($r["result"]);
        $this->assertSame(500, $r["status"]);
        $this->assertSame("authenticationNotConfigured", $r["json"]["error"]);
    }

    public function testSecurityContextAfterRouteResolveGuardsTheRouteBeforeItRuns()
    {
        Server::$securityContextAfterRouteResolve = new SecurityContext();

        $r = $this->dispatch("GET", "/foo/hi");

        $this->assertNull($r["result"]);
        $this->assertSame(401, $r["status"]);
    }

    public function testAuthorizationHeaderVerifierCanBeSetAfterConstruction()
    {
        Server::simulateRequest("GET", "/foo/hi");
        $server = new Server();

        $this->assertNull($server->getAuthorizationHeaderVerifier());

        $verifier = $this->bearerVerifier();
        $server->setAuthorizationHeaderVerifier($verifier);

        $this->assertSame($verifier, $server->getAuthorizationHeaderVerifier());
    }

    // ---- authority ----

    public function testRequiresAuthorityAllowsRouteWhenCheckPasses()
    {
        $r = $this->dispatch("GET", "/params/authority-allow");

        $this->assertSame(["authority" => ["admin", "write"]], $r["result"]);
        $this->assertCount(1, \TestAllowAuthorityCheck::$calls);
    }

    public function testRequiresAuthorityBlocksRouteWhenCheckThrows()
    {
        $r = $this->dispatch("GET", "/params/authority-deny");

        $this->assertNull($r["result"]);
        $this->assertSame(403, $r["status"]);
        $this->assertSame("insufficientAuthority", $r["json"]["error"]);
        $this->assertArrayNotHasKey("reached", $r["json"]);
    }

    // ---- cors ----

    public function testPreflightForAllowedOriginIsAnsweredWithoutRunningTheRoute()
    {
        Server::$cors = new Cors(["https://app.example.com"]);

        $r = $this->dispatch("OPTIONS", "/foo/hi", ["Origin" => "https://app.example.com"]);

        $this->assertNull($r["result"]);
        $this->assertSame("", $r["body"]);
    }

    public function testPreflightBypassesAuthentication()
    {
        Server::$cors = new Cors(["https://app.example.com"]);
        Server::$securityContext = new SecurityContext();

        $r = $this->dispatch("OPTIONS", "/account/abc/", ["Origin" => "https://app.example.com"]);

        $this->assertSame("", $r["body"], "a preflight never carries credentials, so no 401");
    }

    public function testPreflightFromUnknownOriginFallsThroughToRouting()
    {
        Server::$cors = new Cors(["https://app.example.com"]);

        $r = $this->dispatch("OPTIONS", "/foo/hi", ["Origin" => "https://evil.example.com"]);

        $this->assertSame(404, $r["status"], "no OPTIONS route exists");
    }

    public function testActualRequestFromAllowedOriginIsServedNormally()
    {
        Server::$cors = new Cors(["https://app.example.com"]);

        $r = $this->dispatch("GET", "/foo/hi", ["Origin" => "https://app.example.com"]);

        $this->assertSame(["result" => "testRoute7"], $r["result"]);
    }
}
