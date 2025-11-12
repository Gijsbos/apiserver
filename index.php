<?php

use gijsbos\ApiServer\Server;

include_once "tests/Autoload.php";

try
{
    $server = new Server([
        "pathPrefix" => "apiserver/",   // For nested paths
        "addServerTime" => true,        // Adds server time
        "addRequestTime" => true,       // Adds request time
    ]);

    $server->listen();
}
catch(RuntimeException | Exception | TypeError | Throwable $ex)
{
    print($ex->getMessage());
}