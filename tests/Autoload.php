<?php
declare(strict_types=1);

use gijsbos\ApiServer\Utils\RouteParser;

# Source
include_once "vendor/autoload.php";

# Controllers
include_once "tests/Files/TestController.php";

# Run
RouteParser::run();