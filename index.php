<?php

use gijsbos\ApiServer\Server;

include_once "src/Autoload.php";

try
{
    $server = new Server([
        // "pathPrefix" => "apiserver/",    // For nested paths
        // "addRequestTime" => true,        // Adds request time
    ]);

    $server->listen();
}
catch(RuntimeException | Exception | TypeError | Throwable $ex)
{
    print($ex->getMessage());
}