<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

/**
 * LogEnabledClass
 */
class LogEnabledClass
{
    public function __construct(public string $logLevel = "", public string $logOutput = "file")
    { }

    public function setLogLevel(string $logLevel)
    {
        $this->logLevel = $logLevel;
        return $this;
    }

    public function setLogOutput(string $logOutput)
    {
        $this->logOutput = $logOutput;
        return $this;
    }
}