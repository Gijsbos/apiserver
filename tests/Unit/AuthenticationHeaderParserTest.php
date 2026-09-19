<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Authentication\AuthenticationHeaderParser;
use gijsbos\ApiServer\Authentication\AuthenticationScheme;
use gijsbos\Http\Exceptions\UnauthorizedException;

final class AuthenticationHeaderParserTest extends TestCase
{
    use IsolatesGlobalState;

    private function parse(?string $header) : mixed
    {
        if($header !== null)
            $_SERVER["HTTP_AUTHORIZATION"] = $header;

        return (new AuthenticationHeaderParser())->parse();
    }

    public function testReturnsNullWhenHeaderIsMissing()
    {
        $this->assertNull($this->parse(null));
    }

    public function testReturnsNullWhenHeaderIsEmpty()
    {
        $this->assertNull($this->parse(""));
    }

    public function testParsesBearerToken()
    {
        $credentials = $this->parse("Bearer abc.def.ghi");

        $this->assertSame(AuthenticationScheme::Bearer, $credentials->scheme);
        $this->assertSame("abc.def.ghi", $credentials->value);
    }

    public function testParsesBasicCredentials()
    {
        $credentials = $this->parse("Basic dXNlcjpwYXNz");

        $this->assertSame(AuthenticationScheme::Basic, $credentials->scheme);
        $this->assertSame("dXNlcjpwYXNz", $credentials->value);
    }

    public function testSchemeIsCaseInsensitiveButTokenCaseIsPreserved()
    {
        $credentials = $this->parse("BeArEr AbCdEf");

        $this->assertSame(AuthenticationScheme::Bearer, $credentials->scheme);
        $this->assertSame("AbCdEf", $credentials->value);
    }

    public function testSurroundingWhitespaceIsTrimmed()
    {
        $credentials = $this->parse("  Bearer   token123  ");

        $this->assertSame(AuthenticationScheme::Bearer, $credentials->scheme);
        $this->assertSame("token123", $credentials->value);
    }

    public function testFallsBackToRedirectHeaderSetByModRewrite()
    {
        // Apache CGI/FastCGI workaround exposes the header as REDIRECT_HTTP_AUTHORIZATION
        $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] = "Bearer viaRedirect";

        $credentials = (new AuthenticationHeaderParser())->parse();

        $this->assertSame("viaRedirect", $credentials->value);
    }

    #[DataProvider('invalidHeaders')]
    public function testRejectsMalformedHeaders(string $header)
    {
        $this->assertHttpError(UnauthorizedException::class, "authorizationHeaderInvalid", fn() => $this->parse($header));
    }

    public static function invalidHeaders() : array
    {
        return [
            "unknown scheme" => ["Digest abc"],
            "scheme without credentials" => ["Bearer"],
            "credentials with spaces" => ["Bearer abc def"],
            "no scheme" => ["justatoken"],
        ];
    }

    public function testMalformedHeaderIsA401()
    {
        $e = $this->assertHttpError(UnauthorizedException::class, "authorizationHeaderInvalid", fn() => $this->parse("nope"));

        $this->assertSame(401, $e->getStatusCode());
    }
}
