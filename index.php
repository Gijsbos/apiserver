<?php

use gijsbos\ApiServer\Server;

include_once "tests/Autoload.php";

try
{
    $server = new Server([
        "requireHttps" => false,        // Must use HTTPS or receive error, defaults to false
        "pathPrefix" => "",             // Used for subpaths e.g. localhost/mysubpath/
        "escapeResult" => true,         // Escaped special characters, defaults to true
        "addServerTime" => false,       // Adds code execution time
        "addRequestTime" => false,      // Adds total server response time
    ]);

    $server->listen();
}
catch(RuntimeException | Exception | TypeError | Throwable $ex)
{
    print($ex->getMessage());
}