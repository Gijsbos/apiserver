<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use Exception;
use gijsbos\ApiServer\Classes\RequestHeader;
use RuntimeException;
use Throwable;
use TypeError;
use gijsbos\Http\Exceptions\ForbiddenException;
use gijsbos\Http\Exceptions\HTTPRequestException;
use gijsbos\Http\Exceptions\ResourceNotFoundException;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\Utils\ArrayToXmlParser;
use gijsbos\ApiServer\Utils\RouteMethodParamsFactory;
use gijsbos\ApiServer\Utils\RouteParser;

use function gijsbos\ApiServer\Library\log_error;

/**
 * Server
 */
class Server
{
    public static string $CACHE_FOLDER = "./cache/api-speed";
    private string $requestMethod;
    private string $requestURI;
    private string $pathPrefix;
    private null|false|RouteInterface $route;
    private null|float $requestStartTime;
    private null|float $requestEndTime;
    private bool $requireHttps;
    private bool $addRequestTime;

    /**
     * __construct
     */
    public function __construct(array $opts = [])
    {
        $this->pathPrefix = is_string(@$opts["pathPrefix"]) ? str_must_start_end_with($opts["pathPrefix"], "/") : "";
        $this->requestMethod = @$_SERVER["REQUEST_METHOD"];
        $this->requestURI = str_must_start_with(strlen($this->pathPrefix) > 0 ? str_must_not_start_with($_SERVER["REQUEST_URI"], $this->pathPrefix) : $_SERVER["REQUEST_URI"], "/");
        $this->route = null; // Keep route when resolved
        $this->requestStartTime = microtime(true); // Keep request time
        $this->requestEndTime = null;
        $this->requireHttps = array_key_exists("requireHttps", $opts) ? boolval($opts["requireHttps"]) : false;
        $this->addRequestTime = array_key_exists("addRequestTime", $opts) ? boolval($opts["addRequestTime"]) : false;
    }

    /**
     * getPathPrefix
     */
    public function getPathPrefix()
    {
        return $this->pathPrefix;
    }

    /**
     * getRequestMethod
     */
    public function getRequestMethod()
    {
        return strtoupper($this->requestMethod);
    }

    /**
     * getRequestURI
     */
    public function getRequestURI()
    {
        return $this->requestURI;
    }

    /**
     * getRequestURIIndex
     */
    public function getRequestURIIndex()
    {
        return substr_count($this->getRequestURI(), "/");
    }

    /**
     * getRoute
     */
    public function getRoute() : null | false | RouteInterface
    {
        return $this->route;
    }

    /**
     * getRequestStartTime
     */
    public function getRequestStartTime()
    {
        return $this->requestStartTime;
    }

    /**
     * getRequestEndTime
     */
    public function getRequestEndTime()
    {
        return $this->requestEndTime;
    }

    /**
     * getRequestTime
     */
    public function getRequestTime()
    {
        return ($this->requestEndTime ?? microtime(true)) - $this->requestStartTime;
    }

    /**
     * isHttps
     */
    private function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        } elseif (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * verifyHttps
     */
    private function verifyHttps()
    {
        if($this->requireHttps && !$this->isHttps())
            throw new ForbiddenException("httpsRequired", "Requests must be made over HTTPS");
    }

    /**
     * parseRoute
     */
    private function parseRoute(string $classMethod, array $pathPatternMatches = [])
    {
        [$className, $methodName] = explode("::", $classMethod);

        if(!class_exists($className))
            throw new RuntimeException("Class \"$className\" not found");

        if(!method_exists($className, $methodName))
            throw new RuntimeException("Class method \"$methodName\" not found in $className");

        // Fetch Route
        $route = RouteParser::getRoute($methodName, $className);

        // Set properties
        $route->setClassName($className);
        $route->setMethodName($methodName);
        $route->setRequestURI($this->requestURI);

        // Init path variables
        $pathVariableNames = $route->getPathVariableNames();
        if(count($pathVariableNames))
        {
            $pathVariables = [];
            foreach($pathVariableNames as $i => $name)
            {
                $pathVariables[$name] = $pathPatternMatches[$i+1];
            }

            $route->setPathVariables($pathVariables);
        }

        // Set server ref
        $route->setServer($this);

        // Done
        return $route;
    }

    /**
     * matchRoutePattern
     */
    private function matchRoutePattern(string $cacheFile) : false | RouteInterface
    {
        $handle = fopen($cacheFile, 'r');

        if (!$handle) {
            throw new RuntimeException("Cannot open file: $cacheFile");
        }

        try
        {
            while (($line = fgets($handle)) !== false)
            {
                $routeData = explode(" ", trim($line));

                if (count($routeData) < 2) continue;

                if (preg_match($routeData[0], $this->getRequestURI(), $pathPatternMatches))
                {
                    return $this->parseRoute($routeData[1], $pathPatternMatches); // found!
                }
            }
            return false;
        }
        finally
        {
            fclose($handle);
        }
    }

    /**
     * matchRoute
     */
    private function matchRoute()
    {
        $method = $this->getRequestMethod();
        $index = $this->getRequestURIIndex();

        // Route cache folder not found
        if(!is_dir(self::$CACHE_FOLDER))
            throw new ResourceNotFoundException("routesNotFound", "Could not find resources");

        // Find route index file
        $routeCacheFile = self::$CACHE_FOLDER."/$method/$index";

        // Not found
        if(!is_file($routeCacheFile))
            return false;

        // Find round
        return $this->matchRoutePattern($routeCacheFile);
    }

    /**
     * executeRoute
     */
    public function executeRoute(RouteInterface $route)
    {
        $className = $route->getClassName();
        $methodName = $route->getMethodName();
        $controller = new $className($this);
        return $controller->$methodName(...(new RouteMethodParamsFactory())->generateMethodParams($methodName, $className, $route));
    }

    /**
     * getReturnContentType
     */
    private function printReturnValue(array $result)
    {
        $contentType = RequestHeader::getHeader("content-type");

        switch($contentType) {

            case "application/xml":
                Header('Content-Type: application/xml; charset=utf-8');
                http_response_code($this->getRoute()?->getStatus() ?? 200);
                echo (new ArrayToXmlParser())->arrayToXml($result)->asXML();
            exit();

            case "application/json":
            default:
                Header('Content-Type: application/json; charset=utf-8');
                http_response_code($this->getRoute()?->getStatus() ?? 200);
                echo json_encode($result);
            exit();
        }
    }

    /**
     * listen
     */
    public function listen()
    {
        try
        {
            $this->verifyHttps();

            $this->route = $this->matchRoute();

            if($this->route === false)
                throw new ResourceNotFoundException("routeNotFound", "Resource could not be found");

            $result = $this->executeRoute($this->route);

            $this->requestEndTime = microtime(true);

            if($this->addRequestTime)
                $result["time"] = $this->getRequestTime();

            $this->printReturnValue($result);
        }
        catch(HTTPRequestException $ex)
        {
            $ex->printJson();
        }
        catch(RuntimeException | Exception | TypeError | Throwable $ex)
        {
            log_error($ex->getMessage());
            log_error($ex->getTraceAsString());
            http_response_code(500);
            print(json_encode([
                "error" => get_class($ex),
                "errorDescription" => $ex->getMessage(),
                "status" => 500,
            ]));
        }
    }

    /**
     * simulateRequest
     */
    public static function simulateRequest(string $requestMethod, string $uri = "", array $data = [], array $headers = [])
    {
        $_SERVER["REQUEST_METHOD"] = strtoupper($requestMethod);
        $_SERVER["REQUEST_URI"] = str_must_start_with($uri, "/");

        foreach($headers as $key => $value)
        {
            $key = str_starts_with(strtolower($key), "http_") ? $key : "http_$key";

            $_SERVER[strtoupper($key)] = $value;
        }
    }
}