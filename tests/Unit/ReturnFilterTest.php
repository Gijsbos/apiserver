<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use gijsbos\ApiServer\Attributes\ReturnFilter;

final class ReturnFilterTest extends TestCase
{
    // ---- construction ----

    public function testArrayFilterIsKeptAsIs()
    {
        $this->assertSame(["id", "name"], (new ReturnFilter(["id", "name"]))->getFilter());
    }

    public function testClassNameExpandsToPublicPropertyNames()
    {
        $filter = (new ReturnFilter(\TestFilterAddress::class))->getFilter();

        $this->assertSame(["city", "zip"], $filter);
    }

    public function testClassPropertiesTypedByVarDocCommentExpandRecursively()
    {
        $filter = (new ReturnFilter(\TestFilterUser::class))->getFilter();

        $this->assertSame(["name", "email", "address" => ["city", "zip"]], $filter);
    }

    public function testClassNameInsideArrayExpands()
    {
        $filter = (new ReturnFilter(["id", \TestFilterAddress::class]))->getFilter();

        $this->assertSame(["id", ["city", "zip"]], $filter);
    }

    public function testUnknownClassNameIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);

        new ReturnFilter("No\\Such\\Class");
    }

    public function testSanitizeReturnDataKey()
    {
        $this->assertSame("ab-c_d1", ReturnFilter::santizeReturnDataKey("a b-c_d!1"));
        $this->assertSame("", ReturnFilter::santizeReturnDataKey("!@# $%"));
    }

    // ---- applyFilter ----

    private function apply(array $filter, array $data) : array
    {
        return (new ReturnFilter($filter))->applyFilter($data);
    }

    public function testKeepsListedKeysAndDropsTheRest()
    {
        $result = $this->apply(["id", "name"], ["id" => 1, "name" => "a", "secret" => "s"]);

        $this->assertSame(["id" => 1, "name" => "a"], $result);
    }

    public function testEmptyFilterRemovesEverything()
    {
        $this->assertSame([], $this->apply([], ["id" => 1, "name" => "a"]));
    }

    public function testListedKeyMissingFromDataIsSimplyAbsent()
    {
        $this->assertSame(["id" => 1], $this->apply(["id", "name"], ["id" => 1]));
    }

    public function testPlainKeyKeepsWholeNestedValue()
    {
        $data = ["user" => ["name" => "a", "password" => "p"], "other" => 1];

        $this->assertSame(["user" => ["name" => "a", "password" => "p"]], $this->apply(["user"], $data));
    }

    public function testNestedFilterNarrowsNestedArray()
    {
        $data = ["user" => ["name" => "a", "password" => "p"], "other" => 1];

        $this->assertSame(["user" => ["name" => "a"]], $this->apply(["user" => ["name"]], $data));
    }

    public function testNestedFilterAppliesToEveryItemOfAList()
    {
        $data = ["users" => [
            ["id" => 1, "password" => "x"],
            ["id" => 2, "password" => "y"],
        ]];

        $result = $this->apply(["users" => ["id"]], $data);

        $this->assertSame(["users" => [["id" => 1], ["id" => 2]]], $result);
    }

    public function testTopLevelListIsFilteredItemByItem()
    {
        $data = [
            ["id" => 1, "secret" => "x"],
            ["id" => 2, "secret" => "y"],
        ];

        $this->assertSame([["id" => 1], ["id" => 2]], $this->apply(["id"], $data));
    }

    public function testNestedFilterOnScalarValueKeepsIt()
    {
        $this->assertSame(["user" => "plain"], $this->apply(["user" => ["name"]], ["user" => "plain"]));
    }

    public function testExplicitExclusionMarkers()
    {
        $filter = ["name", "!id", "secret!"];
        $data = ["id" => 1, "name" => "a", "secret" => "s"];

        $this->assertSame(["name" => "a"], $this->apply($filter, $data));
    }

    public function testExclusionMarkerWithNestedFilterDropsTheKey()
    {
        $filter = ["name", "!user" => ["name"]];
        $data = ["name" => "a", "user" => ["name" => "n"]];

        $this->assertSame(["name" => "a"], $this->apply($filter, $data));
    }

    public function testOptionalMarkersKeepTheKeyWhenPresent()
    {
        $this->assertSame(["id" => 1, "nickname" => "n"], $this->apply(["id", "?nickname"], ["id" => 1, "nickname" => "n", "x" => 1]));
        $this->assertSame(["id" => 1, "nickname" => "n"], $this->apply(["id", "nickname?"], ["id" => 1, "nickname" => "n"]));
        $this->assertSame(["id" => 1], $this->apply(["id", "?nickname"], ["id" => 1]));
    }

    public function testOptionalMarkerSupportsNestedFilters()
    {
        $data = ["address" => ["city" => "c", "zip" => "z"]];

        $this->assertSame(["address" => ["city" => "c"]], $this->apply(["?address" => ["city"]], $data));
    }

    public function testListsOfScalarsAreLeftUntouched()
    {
        $this->assertSame(["tags" => ["a", "b"]], $this->apply(["tags"], ["tags" => ["a", "b"]]));
    }

    public function testFilterBuiltFromClassAppliesToData()
    {
        $filter = new ReturnFilter(\TestFilterUser::class);

        $result = $filter->applyFilter([
            "name" => "n",
            "email" => "e",
            "password" => "p",
            "address" => ["city" => "c", "zip" => "z", "geo" => "g"],
        ]);

        $this->assertSame(["name" => "n", "email" => "e", "address" => ["city" => "c", "zip" => "z"]], $result);
    }

    public function testFilterCanBeReused()
    {
        $filter = new ReturnFilter(["id"]);

        $first = $filter->applyFilter(["id" => 1, "x" => 1]);
        $second = $filter->applyFilter(["id" => 2, "y" => 2]);

        $this->assertSame(["id" => 1], $first);
        $this->assertSame(["id" => 2], $second);
    }
}
