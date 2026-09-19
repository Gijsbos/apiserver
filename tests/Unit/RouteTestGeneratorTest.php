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

    public function testAuthorityRouteWithoutAnAuthorizationParameterGeneratesWithoutWarnings()
    {
        // Own process: the generator include_once's any generated file it finds, so a second
        // generation into another folder within this process would redeclare the test classes.
        $outputFolder = "temp/GeneratedTests/authority";
        $expectedOutputFile = "$outputFolder/TestParamsControllerTest.php";

        foreach(glob("$outputFolder/*.php") ?: [] as $file)
            unlink($file);

        $script = 'include "tests/bootstrap.php"; (new gijsbos\\ApiServer\\Utils\\RouteTestGenerator())->generateTests($argv[1]);';

        exec(sprintf("%s -d display_errors=1 -d error_reporting=-1 -r %s %s 2>&1", escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg($outputFolder)), $output, $exitCode);

        $output = implode("\n", $output);

        $this->assertSame(0, $exitCode, $output);
        $this->assertDoesNotMatchRegularExpression('/warning|notice|deprecated|fatal/i', $output);

        // The synthesized token is sent as a bearer Authorization header
        $this->assertMatchesRegularExpression('/function testAuthorityAllow.*?"Authorization" => "Bearer \$token"/s', file_get_contents($expectedOutputFile));
    }
}
