<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Authentication\AuthenticationCredentials;
use gijsbos\ApiServer\Authentication\AuthenticationScheme;
use gijsbos\ApiServer\Authentication\AuthenticationVerifier;
use gijsbos\Http\Exceptions\UnauthorizedException;

final class AuthenticationVerifierTest extends TestCase
{
    use IsolatesGlobalState;

    private function basic(string $userAndPassword) : AuthenticationCredentials
    {
        return new AuthenticationCredentials(AuthenticationScheme::Basic, base64_encode($userAndPassword));
    }

    private function bearer(string $token) : AuthenticationCredentials
    {
        return new AuthenticationCredentials(AuthenticationScheme::Bearer, $token);
    }

    public function testReportsWhichSchemesAreConfigured()
    {
        $this->assertFalse((new AuthenticationVerifier())->hasViaBasic());
        $this->assertFalse((new AuthenticationVerifier())->hasViaBearer());

        $verifier = new AuthenticationVerifier(viaBasic: fn() => true);
        $this->assertTrue($verifier->hasViaBasic());
        $this->assertFalse($verifier->hasViaBearer());

        $verifier = new AuthenticationVerifier(viaBearer: fn() => true);
        $this->assertFalse($verifier->hasViaBasic());
        $this->assertTrue($verifier->hasViaBearer());
    }

    public function testBearerPassesTokenToCallback()
    {
        $received = null;

        $verifier = new AuthenticationVerifier(viaBearer: function($token) use (&$received) {
            $received = $token;
            return true;
        });

        $verifier->verify($this->bearer("tok-123"));

        $this->assertSame("tok-123", $received);
    }

    public function testBearerRejectedWhenCallbackReturnsFalsy()
    {
        $verifier = new AuthenticationVerifier(viaBearer: fn() => false);

        $this->assertHttpError(UnauthorizedException::class, "tokenInvalid", fn() => $verifier->verify($this->bearer("x")));
    }

    public function testBearerNotSupportedWithoutCallback()
    {
        $verifier = new AuthenticationVerifier(viaBasic: fn() => true);

        $this->assertHttpError(UnauthorizedException::class, "schemeNotSupported", fn() => $verifier->verify($this->bearer("x")));
    }

    public function testBasicDecodesUsernameAndPassword()
    {
        $received = null;

        $verifier = new AuthenticationVerifier(viaBasic: function($user, $pass) use (&$received) {
            $received = [$user, $pass];
            return true;
        });

        $verifier->verify($this->basic("alice:secret"));

        $this->assertSame(["alice", "secret"], $received);
    }

    public function testBasicSplitsOnFirstColonOnly()
    {
        $received = null;

        $verifier = new AuthenticationVerifier(viaBasic: function($user, $pass) use (&$received) {
            $received = [$user, $pass];
            return true;
        });

        $verifier->verify($this->basic("alice:pa:ss:word"));

        $this->assertSame(["alice", "pa:ss:word"], $received);
    }

    public function testBasicAllowsEmptyPassword()
    {
        $received = null;

        $verifier = new AuthenticationVerifier(viaBasic: function($user, $pass) use (&$received) {
            $received = [$user, $pass];
            return true;
        });

        $verifier->verify($this->basic("alice:"));

        $this->assertSame(["alice", ""], $received);
    }

    public function testBasicRejectedWhenCallbackReturnsFalsy()
    {
        $verifier = new AuthenticationVerifier(viaBasic: fn() => false);

        $this->assertHttpError(UnauthorizedException::class, "credentialsInvalid", fn() => $verifier->verify($this->basic("a:b")));
    }

    public function testBasicRejectsInvalidBase64()
    {
        $verifier = new AuthenticationVerifier(viaBasic: fn() => true);
        $credentials = new AuthenticationCredentials(AuthenticationScheme::Basic, "!!!not-base64!!!");

        $this->assertHttpError(UnauthorizedException::class, "credentialsFormatInvalid", fn() => $verifier->verify($credentials));
    }

    public function testBasicRejectsCredentialsWithoutColon()
    {
        $verifier = new AuthenticationVerifier(viaBasic: fn() => true);

        $this->assertHttpError(UnauthorizedException::class, "credentialsFormatInvalid", fn() => $verifier->verify($this->basic("nocolonhere")));
    }

    public function testBasicNotSupportedWithoutCallback()
    {
        $verifier = new AuthenticationVerifier(viaBearer: fn() => true);

        $this->assertHttpError(UnauthorizedException::class, "schemeNotSupported", fn() => $verifier->verify($this->basic("a:b")));
    }

    public function testCredentialsAreReadonlyValueObject()
    {
        $credentials = $this->bearer("abc");

        $this->assertSame(AuthenticationScheme::Bearer, $credentials->scheme);
        $this->assertSame("abc", $credentials->value);

        $this->expectException(\Error::class);
        $credentials->value = "changed";
    }

    public function testSchemeEnumMapsFromLowercaseNames()
    {
        $this->assertSame(AuthenticationScheme::Bearer, AuthenticationScheme::from("bearer"));
        $this->assertSame(AuthenticationScheme::Basic, AuthenticationScheme::from("basic"));
        $this->assertNull(AuthenticationScheme::tryFrom("digest"));
    }
}
