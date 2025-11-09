<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Classes;

use Attribute;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\Server;

/**
 * Route
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Route implements RouteInterface
{
    private string $path;
    private int $status;
    private string $pathPattern;
    private null|array $pathVariableNames;
    private null|array $pathVariables;
    private null|string $requestURI;
    private null|string $className;
    private null|string $methodName;
    private null|Server $server;

    /**
     * __construct
     */
    public function __construct(private string $requestMethod, string $path, int $status = 200)
    {
        $this->path = str_must_start_with($path, "/");
        $this->status = $status;
        $this->pathPattern = "";
        $this->pathVariableNames = null;
        $this->pathVariables = null;
    }

    /**
     * getRequestMethod
     */
    public function getRequestMethod() : string
    {
        return $this->requestMethod;
    }

    /**
     * getPath
     */
    public function getPath() : string
    {
        return $this->path;
    }

    /**
     * getStatus
     */
    public function getStatus() : int
    {
        return $this->status;
    }

    /**
     * getServer
     */
    public function getServer() : null | Server
    {
        return $this->server;
    }

    /**
     * setServer
     */
    public function setServer(Server $server) : void
    {
        $this->server = $server;
    }

    /**
     * getPathPattern
     */
    public function getPathPattern() : string
    {
        if(strlen($this->pathPattern))
            return $this->pathPattern;

        $pathData = $this->parsePathData();

        $this->pathPattern = $pathData["pathPattern"];
        $this->pathVariableNames = $pathData["pathVariableNames"];

        return $this->pathPattern;
    }

    /**
     * getPathVariableNames
     */
    public function getPathVariableNames() : array
    {
        if(is_array($this->pathVariableNames))
            return $this->pathVariableNames;

        $pathData = $this->parsePathData();

        $this->pathPattern = $pathData["pathPattern"];
        $this->pathVariableNames = $pathData["pathVariableNames"];

        return $this->pathVariableNames;
    }

    /**
     * getPathVariables
     */
    public function getPathVariables(null|string $key = null)
    {
        if(is_string($key))
            return @$this->pathVariables[$key];

        return $this->pathVariables;
    }

    /**
     * setPathVariables
     */
    public function setPathVariables(array $pathVariables) : void
    {
        $this->pathVariables = $pathVariables;
    }

    /**
     * getRequestURI
     */
    public function getRequestURI() : null|string
    {
        return $this->requestURI;
    }

    /**
     * setRequestURI
     */
    public function setRequestURI(string $requestURI) : void
    {
        $this->requestURI = $requestURI;
    }

    /**
     * getClassName
     */
    public function getClassName() : null|string
    {
        return $this->className;
    }

    /**
     * setClassName
     */
    public function setClassName(string $className) : void
    {
        $this->className = $className;
    }

    /**
     * getMethodName
     */
    public function getMethodName() : null|string
    {
        return $this->methodName;
    }

    /**
     * setMethodName
     */
    public function setMethodName(string $methodName) : void
    {
        $this->methodName = $methodName;
    }

    /**
     * parsePathData
     */
    public function parsePathData(array $params = []) : array
    {
        $resourceURL = str_replace("/", "\/", $this->path);
        $pathVariableNames = [];

        // Replace resource params in uri
        preg_match_all("/\{(.*?\:)?([a-zA-Z0-9]+)\}/", $this->path, $matches, PREG_SET_ORDER);

        // Iterate over results
        foreach($matches as $details)
        {
            $match = $details[0];
            $dataType = $details[1];
            $variableName = $details[2];

            $resourceURL = str_replace($match, "(.+?(?=\/))", $resourceURL);

            $pathVariableNames[] = $variableName;

            if(array_key_exists($variableName, $params))
            {
                $value = $params[$variableName];
                $resourceURL = str_replace($match, $value, $this->path);
            }
        }

        return [
            "pathPattern" => "/^$resourceURL$/",
            "pathVariableNames" => $pathVariableNames,
        ];
    }
}