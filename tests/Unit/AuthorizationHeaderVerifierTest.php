<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Authorization\AuthorizationCredentials;
use gijsbos\ApiServer\Authorization\AuthorizationScheme;
use gijsbos\ApiServer\Authorization\AuthorizationHeaderVerifier;
use gijsbos\Http\Exceptions\UnauthorizedException;

final class AuthorizationHeaderVerifierTest extends TestCase
{
    use IsolatesGlobalState;

    private function basic(string $userAndPassword) : AuthorizationCredentials
    {
        return new AuthorizationCredentials(AuthorizationScheme::Basic, base64_encode($userAndPassword));
    }

    private function bearer(string $token) : AuthorizationCredentials
    {
        return new AuthorizationCredentials(AuthorizationScheme::Bearer, $token);
    }

    public function testReportsWhichSchemesAreConfigured()
    {
        $this->assertFalse((new AuthorizationHeaderVerifier())->hasViaBasic());
        $this->assertFalse((new AuthorizationHeaderVerifier())->hasViaBearer());

        $verifier = new AuthorizationHeaderVerifier(viaBasic: fn() => true);
        $this->assertTrue($verifier->hasViaBasic());
        $this->assertFalse($verifier->hasViaBearer());

        $verifier = new AuthorizationHeaderVerifier(viaBearer: fn() => true);
        $this->assertFalse($verifier->hasViaBasic());
        $this->assertTrue($verifier->hasViaBearer());
    }

    public function testBearerPassesTokenToCallback()
    {
        $received = null;

        $verifier = new AuthorizationHeaderVerifier(viaBearer: function($token) use (&$received) {
            $received = $token;
            return true;
        });

        $verifier->verify($this->bearer("tok-123"));

        $this->assertSame("tok-123", $received);
    }

    public function testBearerRejectedWhenCallbackReturnsFalsy()
    {
        $verifier = new AuthorizationHeaderVerifier(viaBearer: fn() => false);

        $this->assertHttpError(UnauthorizedException::class, "tokenInvalid", fn() => $verifier->verify($this->bearer("x")));
    }

    public function testBearerNotSupportedWithoutCallback()
    {
        $verifier = new AuthorizationHeaderVerifier(viaBasic: fn() => true);

        $this->assertHttpError(UnauthorizedException::class, "schemeNotSupported", fn() => $verifier->verify($this->bearer("x")));
    }

    public function testBasicDecodesUsernameAndPassword()
    {
        $received = null;

        $verifier = new AuthorizationHeaderVerifier(viaBasic: function($user, $pass) use (&$received) {
            $received = [$user, $pass];
            return true;
        });

        $verifier->verify($this->basic("alice:secret"));

        $this->assertSame(["alice", "secret"], $received);
    }

    public function testBasicSplitsOnFirstColonOnly()
    {
        $received = null;

        $verifier = new AuthorizationHeaderVerifier(viaBasic: function($user, $pass) use (&$received) {
            $received = [$user, $pass];
            return true;
        });

        $verifier->verify($this->basic("alice:pa:ss:word"));

        $this->assertSame(["alice", "pa:ss:word"], $received);
    }

    public function testBasicAllowsEmptyPassword()
    {
        $received = null;

        $verifier = new AuthorizationHeaderVerifier(viaBasic: function($user, $pass) use (&$received) {
            $received = [$user, $pass];
            return true;
        });

        $verifier->verify($this->basic("alice:"));

        $this->assertSame(["alice", ""], $received);
    }

    public function testBasicRejectedWhenCallbackReturnsFalsy()
    {
        $verifier = new AuthorizationHeaderVerifier(viaBasic: fn() => false);

        $this->assertHttpError(UnauthorizedException::class, "credentialsInvalid", fn() => $verifier->verify($this->basic("a:b")));
    }

    public function testBasicRejectsInvalidBase64()
    {
        $verifier = new AuthorizationHeaderVerifier(viaBasic: fn() => true);
        $credentials = new AuthorizationCredentials(AuthorizationScheme::Basic, "!!!not-base64!!!");

        $this->assertHttpError(UnauthorizedException::class, "credentialsFormatInvalid", fn() => $verifier->verify($credentials));
    }

    public function testBasicRejectsCredentialsWithoutColon()
    {
        $verifier = new AuthorizationHeaderVerifier(viaBasic: fn() => true);

        $this->assertHttpError(UnauthorizedException::class, "credentialsFormatInvalid", fn() => $verifier->verify($this->basic("nocolonhere")));
    }

    public function testBasicNotSupportedWithoutCallback()
    {
        $verifier = new AuthorizationHeaderVerifier(viaBearer: fn() => true);

        $this->assertHttpError(UnauthorizedException::class, "schemeNotSupported", fn() => $verifier->verify($this->basic("a:b")));
    }

    public function testCredentialsAreReadonlyValueObject()
    {
        $credentials = $this->bearer("abc");

        $this->assertSame(AuthorizationScheme::Bearer, $credentials->scheme);
        $this->assertSame("abc", $credentials->value);

        $this->expectException(\Error::class);
        $credentials->value = "changed";
    }

    public function testSchemeEnumMapsFromLowercaseNames()
    {
        $this->assertSame(AuthorizationScheme::Bearer, AuthorizationScheme::from("bearer"));
        $this->assertSame(AuthorizationScheme::Basic, AuthorizationScheme::from("basic"));
        $this->assertNull(AuthorizationScheme::tryFrom("digest"));
    }
}
