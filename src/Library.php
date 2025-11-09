<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Library;

function write_log_to_file(string $message, string $type)
{
    $fileName = $type == "error" ? "error.log" : "api.log";

    if(!is_dir("logs"))
        mkdir("logs");

    file_put_contents("logs/$fileName", $message, FILE_APPEND);
}

function extract_object_log_params()
{
    $params = [];

    $backtraces = array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS|DEBUG_BACKTRACE_PROVIDE_OBJECT, 4), 3);

    if(count($backtraces))
    {
        $backtrace = reset($backtraces);

        if(is_object($backtrace["object"]))
        {   
            $calledClassArray = explode("\\", get_class($backtrace["object"]));

            $params["callingClass"] = end($calledClassArray);

            if(property_exists($backtrace["object"], "logLevel"))
                $params["logLevel"] = $backtrace["object"]->logLevel;

            if(property_exists($backtrace["object"], "logOutput"))
                $params["logOutput"] = $backtrace["object"]->logOutput;
        }
    }

    return $params;
}

function write_log(string $message, string $type = "")
{
    $logParams = strlen($type) > 0 ? extract_object_log_params() : [];

    $logLevel = getenv("LOG_LEVEL") !== false ? getenv("LOG_LEVEL") : @$logParams["logLevel"] ?? "";
    $logOutput = getenv("LOG_OUTPUT") !== false ? getenv("LOG_OUTPUT") : @$logParams["logOutput"] ?? "file";
    $callingClass = @$logParams["callingClass"] ?? "";

    if(strlen($logLevel) > 0 && strlen($message) > 0)
    {
        $print = "[".(new \DateTime())->format("Y-m-d H:i:s")."]";

        if(strlen($callingClass))
            $print .= "[$callingClass]";

        if(strlen($type) && $type !== "info")
            $print .= "[$type]";

        $print .= " $message\n";

        if($logOutput == "console")
            print($print);
        else
            write_log_to_file($print, $type);
    }
}

function log_info(string $message)
{
    write_log($message, "info");
}

function log_request(string $message)
{
    write_log($message, "request");
}

function log_debug(string $message)
{
    write_log($message, "debug");
}

function log_error(string $message)
{
    write_log($message, "error");
}