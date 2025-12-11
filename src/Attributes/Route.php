<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Attributes;

use Attribute;
use ReflectionMethod;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\Interfaces\RouteParamInterface;
use gijsbos\ApiServer\Server;

/**
 * Route
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Route implements RouteInterface
{
    private string $path;
    private int $statusCode;
    private string $pathPattern = "";
    private null|array $pathVariableNames;
    private null|array $pathVariables;
    private null|string $requestURI = "";
    private null|string $className = "";
    private null|string $methodName = "";
    private null|array $attributes = [];
    private null|array $routeParams = [];
    private null|Server $server = null;
    private null|array $data = [];

    /**
     * __construct
     */
    public function __construct(private string $requestMethod, string $path, int $statusCode = 200, array $opts = [])
    {
        $this->path = str_must_not_start_with($path, "/");
        $this->statusCode = $statusCode;
        $this->pathPattern = "";
        $this->pathVariableNames = null;
        $this->pathVariables = null;
        $this->requestURI = @$opts["requestURI"];
        $this->className = @$opts["className"];
        $this->methodName = @$opts["methodName"];
        $this->attributes = is_string(@$opts["className"]) && is_string(@$opts["methodName"]) ? $this->extractRouteAttributes($opts["className"], $opts["methodName"]) : [];
        $this->routeParams = [];
        $this->data = [];
    }

    /**
     * extractRouteAttributes
     */
    public static function extractRouteAttributes(string|ReflectionMethod $classOrReflectionMethod, ?string $method = null)
    {
        $method = is_string($classOrReflectionMethod) && is_string($method) ? new ReflectionMethod($classOrReflectionMethod, $method) : $classOrReflectionMethod;

        return array_values(array_filter($method->getAttributes(), fn($a) => !is_subclass_of($a->getName(), Route::class)));
    }

    /**
     * getRequestMethod
     */
    public function getRequestMethod() : string
    {
        return strtoupper($this->requestMethod);
    }

    /**
     * getPath
     */
    public function getPath(...$pathVariableValues) : string
    {
        $path = $this->path;

        // If pathVariableValues are provided, we fill path variables.
        if(count($pathVariableValues))
        {
            if($this->pathVariableNames == null)
                $this->pathVariableNames = $this->getPathVariableNames();

            $pathVariableNames = $this->pathVariableNames;

            $params = array_map_assoc(function($k, $v) use ($pathVariableNames) {
                return [$pathVariableNames[$k], $v];
            }, $pathVariableValues);

            $path = replace_placeholder_array($params, $path, "{", "}");
        }

        return $path;
    }
    
    /**
     * getFullPath
     */
    public function getFullPath(bool $useHTTPS = false, ...$pathVariableValues) : string
    {
        // Server is set when route is resolved by server
        if($this->server)
        {
            $baseUrl = env("BASE_URL");

            if($baseUrl === false)
            {
                $http = $useHTTPS ? "https" : "http";
                $host = get_uri_part('host');
                $baseUrl = str_must_not_end_with("$http://$host", "/") . str_must_start_with(str_must_not_end_with($this->server->getPathPrefix(), "/"), "/");
            }
        }

        // No server set, requires manual base url insertion
        else
        {
            $baseUrl = env("BASE_URL", true);
        }

        // When baseUrl is set, we create the full path
        return str_must_not_end_with($baseUrl, "/") . str_must_start_with($this->getPath(...$pathVariableValues), "/");
    }

    /**
     * getStatusCode
     */
    public function getStatusCode() : int
    {
        return $this->statusCode;
    }

    /**
     * getStatusCode
     */
    public function setStatusCode(int $statusCode)
    {
        $this->statusCode = $statusCode;
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
     * setAttributes
     */
    public function setAttributes(array $attributes) : void
    {
        $this->attributes = $attributes;
    }

    /**
     * getAttributes
     */
    public function getAttributes(?string $name = null)
    {
        if(is_string($name))
        {
            $attributes = array_filter($this->attributes, fn($a) => $a->getName() == $name);

            if(count($attributes) == 0)
                return null;

            return reset($attributes);
        }

        return $this->attributes;
    }

    /**
     * hasAttribute
     */
    public function hasAttribute(string $name) : bool
    {
        return $this->getAttributes($name) !== null;
    }

    /**
     * addRouteParam
     */
    public function addRouteParam(RouteParamInterface $routeParam) : void
    {
        $this->routeParams[] = $routeParam;
    }

    /**
     * getRouteParams
     */
    public function getRouteParams(?string $key = null)
    {
        if(is_string($key))
        {
            foreach($this->routeParams as $param)
                if($param->getName() == $key)
                    return $param;
            return null;
        }

        return $this->routeParams;
    }

    /**
     * setData
     */
    public function setData(array $data)
    {
        $this->data = $data;
    }

    /**
     * addData
     */
    public function addData(string $key, $value)
    {
        $this->data[$key] = $value;
    }

    /**
     * hasData
     */
    public function hasData(?string $key = null)
    {
        if(is_string($key))
            return $this->getData($key) !== null;

        return count($this->data) > 0;
    }

    /**
     * getData
     */
    public function getData(?string $key = null)
    {
        if(is_string($key))
            return @$this->data[$key];

        return $this->data;
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
     * getClassMethod
     */
    public function getClassMethod() : string
    {
        return $this->className . "::" . $this->methodName;
    }

    /**
     * getReflectionClassMethod
     */
    public function getReflectionClassMethod() : ReflectionMethod
    {
        return new ReflectionMethod($this->className, $this->methodName);
    }

    /**
     * getExecuteBeforeRouteMethods
     */
    public function executeBeforeRouteMethods() : array
    {
        return array_map(fn($executeBeforeRoute) => $executeBeforeRoute->newInstance()->execute($this), array_filter($this->getReflectionClassMethod()->getAttributes(), fn($a) => is_subclass_of($a->getName(), ExecuteBeforeRoute::class)));
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

            $resourceURL = str_replace($match, "(.+?(?=\/|$))", $resourceURL);

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