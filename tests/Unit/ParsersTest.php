<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Parsers\ArrayToXmlParser;
use gijsbos\ApiServer\Parsers\EnumRouteArgumentParser;
use gijsbos\Http\Exceptions\BadRequestException;

final class ParsersTest extends TestCase
{
    use IsolatesGlobalState;

    // ---- ArrayToXmlParser ----

    private function xml(array $data) : \SimpleXMLElement
    {
        return (new ArrayToXmlParser())->arrayToXml($data);
    }

    public function testXmlRootIsRoot()
    {
        $xml = $this->xml(["a" => "1"]);

        $this->assertSame("root", $xml->getName());
    }

    public function testXmlScalarsBecomeChildElements()
    {
        $xml = $this->xml(["name" => "alice", "age" => 30, "active" => true]);

        $this->assertSame("alice", (string) $xml->name);
        $this->assertSame("30", (string) $xml->age);
        $this->assertSame("1", (string) $xml->active);
    }

    public function testXmlNestedAssociativeArraysBecomeNestedElements()
    {
        $xml = $this->xml(["user" => ["name" => "alice", "address" => ["city" => "Utrecht"]]]);

        $this->assertSame("alice", (string) $xml->user->name);
        $this->assertSame("Utrecht", (string) $xml->user->address->city);
    }

    public function testXmlListsOfRecordsBecomeRepeatedItemElements()
    {
        $xml = $this->xml(["users" => [["name" => "a"], ["name" => "b"]]]);

        $this->assertCount(2, $xml->users->item);
        $this->assertSame("a", (string) $xml->users->item[0]->name);
        $this->assertSame("b", (string) $xml->users->item[1]->name);
    }

    public function testXmlSpecialCharactersRoundTrip()
    {
        $xml = $this->xml(["text" => 'a < b & "c"']);

        $reparsed = simplexml_load_string($xml->asXML());

        $this->assertNotFalse($reparsed, "output must be well-formed XML");
        $this->assertSame('a < b & "c"', (string) $reparsed->text);
    }

    public function testXmlIntoExistingElementAppendsToIt()
    {
        $existing = new \SimpleXMLElement("<envelope/>");

        $result = (new ArrayToXmlParser())->arrayToXml(["a" => "1"], $existing);

        $this->assertSame($existing, $result);
        $this->assertSame("1", (string) $existing->a);
    }

    public function testXmlEmptyArrayGivesEmptyRoot()
    {
        $this->assertCount(0, $this->xml([])->children());
    }

    public function testXmlListsOfScalarsAreSupported()
    {
        $xml = $this->xml(["tags" => ["a", "b"]]);

        $this->assertCount(2, $xml->tags->item);
        $this->assertSame("a", (string) $xml->tags->item[0]);
        $this->assertSame("b", (string) $xml->tags->item[1]);
    }

    public function testXmlScalarListItemsAreEscapedAndCastToStrings()
    {
        $xml = $this->xml(["values" => [1, 2.5, true, 'a & b']]);

        $reparsed = simplexml_load_string($xml->asXML());

        $this->assertNotFalse($reparsed);
        $this->assertSame(["1", "2.5", "1", "a & b"], array_map('strval', iterator_to_array($reparsed->values->item, false)));
    }

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
