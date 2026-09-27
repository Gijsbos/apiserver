<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Parsers\EnumRouteArgumentParser;
use gijsbos\Http\Exceptions\BadRequestException;

final class ParsersTest extends TestCase
{
    use IsolatesGlobalState;

    // ---- EnumRouteArgumentParser ----

    public function testEnumValueIsMappedToCase()
    {
        $this->assertSame(\TestSuit::Hearts, EnumRouteArgumentParser::parse("suit", \TestSuit::class, "hearts"));
        $this->assertSame(\TestSuit::Spades, EnumRouteArgumentParser::parse("suit", \TestSuit::class, "spades"));
    }

    public function testInvalidEnumValueIsABadRequestListingTheAcceptedValues()
    {
        $e = $this->assertHttpError(BadRequestException::class, "suitValueInvalid", fn() => EnumRouteArgumentParser::parse("suit", \TestSuit::class, "clubs"));

        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString("'clubs'", $e->getErrorDescription());
        $this->assertStringContainsString("hearts|spades", $e->getErrorDescription());
        $this->assertStringContainsString("'TestSuit'", $e->getErrorDescription());
    }
}
