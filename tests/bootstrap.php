<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 *  tests/Autoload.php is also what index.php includes for the local dev server, so
 *  fixtures and helpers that only the unit tests need are loaded here instead. The
 *  fixture controllers must be declared before Autoload.php runs, because that file
 *  ends by generating the route cache from every controller declared at that point.
 */

include_once "vendor/autoload.php";

# Fixtures and helpers used by the unit tests
include_once "tests/Files/TestParamsController.php";
include_once "tests/Files/IsolatesGlobalState.php";

# Shared with index.php
include_once "tests/Autoload.php";
