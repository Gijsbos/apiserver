<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use gijsbos\ApiServer\Utils\RouteTestGenerator;
use PHPUnit\Framework\TestCase;

final class RouteTestGeneratorTest extends TestCase
{
    public function testGenerate()
    {
        $generator = new RouteTestGenerator();

        $outputFolder = "temp/GeneratedTests";

        // Set expectation
        $expectedOutputFile = "$outputFolder/TestControllerTest.php";

        // Remove if exists
        if(is_file($expectedOutputFile))
            unlink($expectedOutputFile);

        // Generate tests
        $generator->generateTests($outputFolder);

        // File exists
        $this->assertTrue(is_file("$outputFolder/TestControllerTest.php"));
    }
}