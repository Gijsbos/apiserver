<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Classes\RouteParam;
use gijsbos\ApiServer\Utils\RouteParamValidator;
use gijsbos\Http\Exceptions\BadRequestException;

final class RouteParamValidatorTest extends TestCase
{
    use IsolatesGlobalState;

    private function param(mixed $value, array $opts = [], string $name = "field") : RouteParam
    {
        $param = new RouteParam($opts);
        $param->name = $name;
        $param->value = $value;

        return $param;
    }

    private function assertRejected(string $error, RouteParam $param) : void
    {
        $this->assertHttpError(BadRequestException::class, $error, fn() => RouteParamValidator::validate($param));
    }

    private function assertAccepted(RouteParam $param) : void
    {
        RouteParamValidator::validate($param);

        $this->addToAssertionCount(1);
    }

    // ---- presence ----

    public function testMissingRequiredValueIsRejected()
    {
        $this->assertRejected("fieldInputInvalid", $this->param(null, ["required" => true]));
        $this->assertRejected("fieldInputInvalid", $this->param("", ["required" => true]));
    }

    public function testFalseIsTreatedAsMissing()
    {
        $this->assertRejected("fieldInputInvalid", $this->param(false, ["required" => true]));
    }

    public function testParamsAreRequiredUnlessStatedOtherwise()
    {
        $this->assertRejected("fieldInputInvalid", $this->param(null));
    }

    public function testMissingOptionalValueIsAccepted()
    {
        $this->assertTrue(RouteParamValidator::validate($this->param(null, ["required" => false])));
        $this->assertTrue(RouteParamValidator::validate($this->param("", ["required" => false])));
    }

    public function testErrorCodeIsPrefixedWithParamName()
    {
        $e = $this->assertHttpError(BadRequestException::class, "userIdInputInvalid", fn() => RouteParamValidator::validate($this->param(null, [], "userId")));

        $this->assertSame(400, $e->getStatusCode());
        $this->assertStringContainsString("'userId'", $e->getErrorDescription());
    }

    // ---- custom types ----

    public function testEmail()
    {
        $this->assertAccepted($this->param("jane@example.com", ["customType" => "email"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("not-an-email", ["customType" => "email"]));
    }

    public function testUrl()
    {
        $this->assertAccepted($this->param("https://example.com/path", ["customType" => "url"]));
        $this->assertAccepted($this->param("https://example.com/path", ["customType" => "uri"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("not a url", ["customType" => "url"]));
    }

    public function testIp()
    {
        $this->assertAccepted($this->param("127.0.0.1", ["customType" => "ip"]));
        $this->assertAccepted($this->param("::1", ["customType" => "ip"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("999.1.1.1", ["customType" => "ip"]));
    }

    public function testMac()
    {
        $this->assertAccepted($this->param("00:1A:2B:3C:4D:5E", ["customType" => "mac"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("00:1A:2B", ["customType" => "mac"]));
    }

    public function testIntAndFloatCustomTypes()
    {
        $this->assertAccepted($this->param("42", ["customType" => "int"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("4.2", ["customType" => "int"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("abc", ["customType" => "int"]));

        $this->assertAccepted($this->param("4.2", ["customType" => "float"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("abc", ["customType" => "float"]));
    }

    public function testTruthyBoolCustomType()
    {
        $this->assertAccepted($this->param("true", ["customType" => "bool"]));
        $this->assertAccepted($this->param("1", ["customType" => "bool"]));
    }

    public function testUnknownCustomTypeIsNotFiltered()
    {
        $this->assertAccepted($this->param("anything goes", ["customType" => "json"]));
    }

    public function testValidFalsyCustomTypeValuesAreAccepted()
    {
        // "0" is a valid int/float and "false" a valid bool, even though they parse to a falsy result
        $this->assertAccepted($this->param("0", ["customType" => "int"]));
        $this->assertAccepted($this->param("0", ["customType" => "float"]));
        $this->assertAccepted($this->param("0.0", ["customType" => "float"]));
        $this->assertAccepted($this->param("false", ["customType" => "bool"]));
        $this->assertAccepted($this->param("0", ["customType" => "bool"]));
        $this->assertAccepted($this->param("0", ["customType" => "json"])); // unknown custom types are not filtered
    }

    public function testInvalidBoolCustomTypeValuesAreStillRejected()
    {
        $this->assertRejected("fieldTypeInvalid", $this->param("maybe", ["customType" => "bool"]));
        $this->assertRejected("fieldTypeInvalid", $this->param("2", ["customType" => "bool"]));
    }

    // ---- allowed values ----

    public function testValueMustBeInAllowedSet()
    {
        $this->assertAccepted($this->param("asc", ["values" => ["asc", "desc"]]));
        $this->assertRejected("fieldValueInvalid", $this->param("sideways", ["values" => ["asc", "desc"]]));
    }

    public function testValueErrorListsAllowedValues()
    {
        $e = $this->assertHttpError(BadRequestException::class, "fieldValueInvalid", fn() => RouteParamValidator::validate($this->param("x", ["values" => ["asc", "desc"]])));

        $this->assertStringContainsString("asc|desc", $e->getErrorDescription());
    }

    // ---- numeric types ----

    public function testIntType()
    {
        $this->assertAccepted($this->param("5", ["type" => "int"]));
        $this->assertAccepted($this->param(5, ["type" => "int"]));
    }

    public function testNonNumericValueForIntTypeIsRejectedWithDetails()
    {
        $e = $this->assertHttpError(BadRequestException::class, "fieldInvalid", fn() => RouteParamValidator::validate($this->param("abc", ["type" => "int"])));

        $this->assertSame(["received" => "abc", "expected" => "int"], $e->getData("details"));
    }

    public function testNumericMinAndMaxAreInclusive()
    {
        $opts = ["type" => "int", "min" => 1, "max" => 10];

        $this->assertAccepted($this->param("1", $opts));
        $this->assertAccepted($this->param("10", $opts));
        $this->assertRejected("fieldValueMinExceeded", $this->param("0", $opts));
        $this->assertRejected("fieldValueMaxExceeded", $this->param("11", $opts));
    }

    public function testMinOfZeroIsEnforced()
    {
        $this->assertRejected("fieldValueMinExceeded", $this->param("-1", ["type" => "int", "min" => 0]));
    }

    public function testFloatAndDoubleTypes()
    {
        $this->assertAccepted($this->param("1.5", ["type" => "float", "min" => 1, "max" => 2]));
        $this->assertAccepted($this->param("1.5", ["type" => "double"]));
        $this->assertRejected("fieldValueMinExceeded", $this->param("0.5", ["type" => "float", "min" => 1]));
        $this->assertRejected("fieldInvalid", $this->param("x", ["type" => "double"]));
    }

    public function testTypeIsInferredFromNumericStrings()
    {
        // No explicit type: "5" is numeric, so min/max apply as numbers rather than string lengths
        $this->assertRejected("fieldValueMinExceeded", $this->param("5", ["min" => 10]));
        $this->assertRejected("fieldValueMaxExceeded", $this->param("50", ["max" => 10]));
    }

    public function testGetTypeFromValue()
    {
        $this->assertSame("int", RouteParamValidator::getTypeFromValue("42"));
        $this->assertSame("double", RouteParamValidator::getTypeFromValue("4.2"));
        $this->assertSame("string", RouteParamValidator::getTypeFromValue("abc"));
        $this->assertSame("string", RouteParamValidator::getTypeFromValue(42), "only strings are inferred as numeric");
        $this->assertSame("string", RouteParamValidator::getTypeFromValue(null));
    }

    // ---- bool ----

    public function testBoolAcceptsBooleanishValues()
    {
        foreach([true, 0, 1, "0", "1"] as $value)
            $this->assertAccepted($this->param($value, ["type" => "bool"]));
    }

    public function testBoolRejectsOtherValues()
    {
        foreach(["true", "yes", 2, "abc"] as $value)
            $this->assertRejected("fieldInvalid", $this->param($value, ["type" => "bool"]));
    }

    // ---- strings ----

    public function testStringLengthBounds()
    {
        $opts = ["type" => "string", "min" => 3, "max" => 5];

        $this->assertAccepted($this->param("abc", $opts));
        $this->assertAccepted($this->param("abcde", $opts));
        $this->assertRejected("fieldLengthMinExceeded", $this->param("ab", $opts));
        $this->assertRejected("fieldLengthMaxExceeded", $this->param("abcdef", $opts));
    }

    public function testStringLengthCountsCharactersNotBytes()
    {
        $this->assertAccepted($this->param("éé", ["type" => "string", "max" => 2]));
        $this->assertRejected("fieldLengthMaxExceeded", $this->param("ééé", ["type" => "string", "max" => 2]));
    }

    public function testStringPattern()
    {
        $opts = ["type" => "string", "pattern" => '/^[a-z]+$/'];

        $this->assertAccepted($this->param("abc", $opts));
        $this->assertRejected("fieldValuePatternFailure", $this->param("ABC", $opts));
    }

    public function testStringTypeRejectsNonStringValue()
    {
        $this->expectException(LogicException::class);

        RouteParamValidator::validate($this->param(5, ["type" => "string"]));
    }

    // ---- parsePatternInput ----

    private function pattern(null|string|array $pattern, string $name = "code") : ?string
    {
        return RouteParamValidator::parsePatternInput($this->param("x", ["pattern" => $pattern], $name));
    }

    public function testNullPatternIsNull()
    {
        $this->assertNull($this->pattern(null));
    }

    public function testRegexStringIsReturnedAsIs()
    {
        $this->assertSame('/^\d+$/', $this->pattern('/^\d+$/'));
    }

    public function testClassNameResolvesRegExpAttributeOfPropertyNamedAfterParam()
    {
        $this->assertSame('/^\d{3}$/', $this->pattern(\TestPatternFixture::class, "code"));
    }

    public function testSingleElementArrayResolvesPropertyNamedAfterParam()
    {
        $this->assertSame('/^\d{3}$/', $this->pattern([\TestPatternFixture::class], "code"));
    }

    public function testClassAndPropertyPair()
    {
        $this->assertSame('/^\d{3}$/', $this->pattern([\TestPatternFixture::class, "code"], "somethingElse"));
    }

    public function testClassAndStaticMethodPair()
    {
        $this->assertSame('/^[a-z]+$/', $this->pattern([\TestPatternFixture::class, "pattern"]));
    }

    public function testMethodMustReturnAString()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pattern([\TestPatternFixture::class, "notAString"]);
    }

    public function testUnknownPropertyOrMethodIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pattern([\TestPatternFixture::class, "doesNotExist"]);
    }

    public function testPropertyWithoutRegExpAttributeIsRejected()
    {
        $this->expectException(LogicException::class);

        $this->pattern([\TestPatternFixture::class, "plain"]);
    }

    public function testSingleElementArrayNeedsExistingClass()
    {
        $this->expectException(LogicException::class);

        $this->pattern(["NoSuchClass"]);
    }

    public function testSingleElementArrayNeedsPropertyMatchingParamName()
    {
        $this->expectException(LogicException::class);

        $this->pattern([\TestPatternFixture::class], "missingProperty");
    }

    public function testPairOfNonStringsIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pattern([1, 2]);
    }

    public function testArraysOfOtherSizesAreRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pattern(["a", "b", "c"]);
    }

    public function testExtractPatternAttributeFromPropertyCanReturnFalseInsteadOfThrowing()
    {
        $this->assertFalse(RouteParamValidator::extractPatternAttributeFromProperty(\TestPatternFixture::class, "plain", false));
    }
}
