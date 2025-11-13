<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use Exception;
use RuntimeException;
use Throwable;
use TypeError;
use ReflectionClass;
use ReflectionAttribute;

use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\ReturnFilter;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\Http\Exceptions\ForbiddenException;
use gijsbos\Http\Exceptions\HTTPRequestException;
use gijsbos\Http\Exceptions\ResourceNotFoundException;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\Utils\ArrayToXmlParser;
use gijsbos\ApiServer\Utils\RouteMethodParamsFactory;
use gijsbos\ApiServer\Utils\RouteParser;
use gijsbos\Logging\Classes\LogEnabledClass;

use function gijsbos\Logging\Library\log_error;

/**
 * Server
 */
class Server extends LogEnabledClass
{
    public static string $DEFAULT_CACHE_FOLDER = "./cache/apiserver";

    private string $requestMethod;
    private string $requestURI;
    private string $pathPrefix;
    private null|false|RouteInterface $route;
    private null|float $requestStartTime;
    private null|float $requestEndTime;
    private bool $requireHttps;
    private bool $escapeResult;
    private bool $addServerTime;
    private bool $addRequestTime;
    private string $dateTimeFormat;
    private string $cacheFolder;

    /**
     * __construct
     */
    public function __construct(array $opts = [])
    {
        parent::__construct();

        $this->pathPrefix = is_string(@$opts["pathPrefix"]) ? str_must_start_end_with($opts["pathPrefix"], "/") : "";
        $this->requestMethod = @$_SERVER["REQUEST_METHOD"];
        $this->requestURI = $this->extractRequestURI();
        $this->route = null; // Keep route when resolved
        $this->requestStartTime = microtime(true); // Keep request time
        $this->requestEndTime = null;
        $this->requireHttps = array_key_exists("requireHttps", $opts) ? boolval($opts["requireHttps"]) : false;
        $this->escapeResult = array_key_exists("escapeResult", $opts) ? boolval($opts["escapeResult"]) : true;
        $this->addServerTime = array_key_exists("addServerTime", $opts) ? boolval($opts["addServerTime"]) : false;
        $this->addRequestTime = array_key_exists("addRequestTime", $opts) ? boolval($opts["addRequestTime"]) : false;
        $this->dateTimeFormat = @$opts["dateTimeFormat"] ?? "ISO8601";
        $this->cacheFolder = @$opts["cacheFolder"] ?? self::$DEFAULT_CACHE_FOLDER;

        $this->setLogOutput("file");
    }

    /**
     * extractRequestURI
     */
    private function extractRequestURI()
    {
        $requestURI = str_must_start_with(strlen($this->pathPrefix) > 0 ? str_must_not_start_with($_SERVER["REQUEST_URI"], $this->pathPrefix) : $_SERVER["REQUEST_URI"], "/");

        $parts = parse_url($requestURI);

        return @$parts["path"] ?? "/";
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
    public function getRequestTime() : null|float
    {
        if(!isset($_SERVER["REQUEST_TIME_FLOAT"]))
            return null;

        return microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
    }

    /**
     * getServerTime
     */
    public function getServerTime()
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

        // Class not found
        if(!class_exists($className))
            throw new RuntimeException("Class \"$className\" not found");

        // Method not found
        if(!method_exists($className, $methodName))
            throw new RuntimeException("Class method \"$methodName\" not found in $className");

        // Fetch Route
        $route = RouteParser::getRoute($methodName, $className);

        // Set properties
        $route->setClassName($className);
        $route->setMethodName($methodName);
        $route->setRequestURI($this->requestURI);

        // Add attributes
        $route->setAttributes(Route::extractRouteAttributes($className, $methodName));

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
     * createRouteCache
     *  Only fires when there is no cache folder
     */
    private function createRouteCache()
    {
        (new RouteParser($this->cacheFolder))
        ->parseControllerFiles();
    }

    /**
     * matchRoute
     */
    private function matchRoute()
    {
        $method = $this->getRequestMethod();
        $index = $this->getRequestURIIndex();

        // Route cache folder not found
        if(!is_dir($this->cacheFolder))
            $this->createRouteCache();

        // Find route index file
        $routeCacheFile = $this->cacheFolder."/$method/$index";

        // Not found
        if(!is_file($routeCacheFile))
            return false;

        // Find round
        return $this->matchRoutePattern($routeCacheFile);
    }

    /**
     * convertObjects
     */
    private function convertObject(object $data)
    {
        if($data instanceof \DateTime)
        {
            $format = $this->dateTimeFormat;

            // Return ISO8601 format
            if($format == "ISO8601" || $format == "c")
                return date('c', $data->getTimestamp());

            // Return format string
            return $data->format($format);
        }
        else
        {
            $reflectionClass = new ReflectionClass($data);

            // Filter out non-public properties
            return array_filter((array) $data, function($key) use ($reflectionClass)
            {
                if($reflectionClass->hasProperty($key))
                    return $reflectionClass->getProperty($key)->isPublic();
                else
                    return true;
            }, ARRAY_FILTER_USE_KEY);
        }
    }

    /**
     * toArray
     */
    private function toArray($data) : array
    {
        if(is_object($data))
            return $this->convertObject($data);

        foreach($data as $key => &$value)
        {
            // Convert object
            if(is_object($value))
                $value = $this->convertObject($value);

            // Repeat for every array
            if(is_array($value) || is_object($value))
                $value = $this->toArray($value);
        }

        return $data;
    }

    /**
     * applyReturnFilter
     */
    private function applyReturnFilter(Route $route, array $returnData)
    {
        if($route->hasAttribute(ReturnFilter::class))
        {
            $returnFilterAttribute = $route->getAttributes(ReturnFilter::class);

            if($returnFilterAttribute !== null)
            {
                $returnData = $returnFilterAttribute->newInstance()->applyFilter($returnData);
            }
        }
        return $returnData;
    }

    /**
     * applyEscapeResult
     */
    private function applyEscapeResult(array $data)
    {
        if($this->escapeResult)
        {
            array_walk_recursive($data, function(&$value, $key)
            {
                if(is_string($value))
                    $value = htmlspecialchars($value);
            });
        }

        return $data;
    }

    /**
     * executeRoute
     */
    public function executeRoute(Route $route)
    {
        $className = $route->getClassName();
        $methodName = $route->getMethodName();

        // Create controller
        $controller = new $className($this);

        // ExecuteBeforeRoute
        $route->executeBeforeRouteMethods();

        // Execute route
        $returnData = $controller->$methodName(...((new RouteMethodParamsFactory())->generateMethodParams($route)));

        // Turn data into array
        $returnData = $this->toArray($returnData);

        // Apply filter
        $returnData = $this->applyReturnFilter($route, $returnData);

        // Escape result for safety
        $returnData = $this->applyEscapeResult($returnData);

        // Apply return filter if applicable
        return $returnData;
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
    public function listen(bool $printReturnValue = true)
    {
        try
        {
            // Verify if https is required
            $this->verifyHttps();

            // Lookup route
            $this->route = $this->matchRoute();

            // Not found
            if($this->route === false)
                throw new ResourceNotFoundException("routeNotFound", "Resource could not be found");

            // Execute route
            $response = $this->executeRoute($this->route);

            // Record request time
            $this->requestEndTime = microtime(true);

            // Include request time; if response is list, then keys are added to data e.g. {"0": <response>, "requestTime": 0.00764..} will be created
            if($this->addServerTime)
                $response["serverTime"] = $this->getServerTime();

            if($this->addRequestTime)
                $response["requestTime"] = $this->getRequestTime();

            // Print result
            if($printReturnValue)
                $this->printReturnValue($response);

            // Return result
            else
                return $response;
        }
        catch(HTTPRequestException $ex)
        {
            $ex->sendJson();
        }
        catch(RuntimeException | Exception | TypeError | Throwable $ex)
        {
            try
            {
                log_error($ex->getMessage());
                log_error($ex->getTraceAsString());
            }
            catch(RuntimeException $rex)
            {
                http_response_code(500);
                print(json_encode([
                    "error" => get_class($rex),
                    "errorDescription" => $rex->getMessage(),
                    "statusCode" => 500,
                ]));
                exit(0);
            }
            
            http_response_code(500);
            print(json_encode([
                "error" => get_class($ex),
                "errorDescription" => $ex->getMessage(),
                "statusCode" => 500,
            ]));
            exit(0);
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