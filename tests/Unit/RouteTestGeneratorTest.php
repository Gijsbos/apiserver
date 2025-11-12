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

        $generator->generate("cache/Files/RouteTestGenerator");
    }
}