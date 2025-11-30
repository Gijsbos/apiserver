<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use Exception;
use RuntimeException;
use Throwable;
use TypeError;
use ReflectionClass;
use UnexpectedValueException;

use gijsbos\Http\Response;
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


/**
 * Server
 */
class Server extends LogEnabledClass
{
    public static string $DEFAULT_ROUTES_FILE = "cache/apiserver/routes.php";

    /**
     * @var array responseHandler
     *  Allows for custom response handling
     */
    public static $responseHandler = null;

    /**
     * @var array exceptionHandlers
     *  Allows for custom exception handling
     */
    public static $exceptionHandlers = [];

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
    private string $routesFile;

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
        $this->routesFile = @$opts["routesFile"] ?? self::$DEFAULT_ROUTES_FILE;

        $this->setLogOutput("file");
    }

    /**
     * setResponseHandler
     */
    public static function setResponseHandler(callable $handler) : void
    {
        self::$responseHandler = $handler;
    }

    /**
     * addExceptionHandler
     */
    public static function addExceptionHandler(string $exceptionClassName, callable $handler) : void
    {
        self::$exceptionHandlers[] = [
            "className" => $exceptionClassName,
            "function" => $handler,
        ];
    }

    /**
     * extractRequestURI
     */
    private function extractRequestURI()
    {
        $requestURI = str_must_not_start_with(strlen($this->pathPrefix) > 0 ? str_must_not_start_with($_SERVER["REQUEST_URI"], $this->pathPrefix) : $_SERVER["REQUEST_URI"], "/");

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
    private function parseRoute(string $classMethod, array $pathVariables)
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

        // Route not found
        if($route === false)
            return false;

        // Set properties
        $route->setClassName($className);
        $route->setMethodName($methodName);
        $route->setRequestURI($this->requestURI);

        // Add attributes
        $route->setAttributes(Route::extractRouteAttributes($className, $methodName));

        // Init path variables
        $route->setPathVariables($pathVariables);

        // Set server ref
        $route->setServer($this);

        // Done
        return $route;
    }

    /**
     * createRouteCache
     *  Only fires when there is no cache folder
     */
    private function createRouteCache()
    {
        (new RouteParser($this->routesFile))
        ->parseControllerFiles();
    }

    /**
     * matchRoute
     */
    private function matchRoute()
    {
        // Create routes if they don't exist
        if(!is_file($this->routesFile))
            $this->createRouteCache();

        // Include the routes into memory
        $routes = require_once $this->routesFile;

        // Get method
        $requestMethod = $this->getRequestMethod();

        // Route method does not exist
        if(!array_key_exists($requestMethod, $routes))
            throw new ResourceNotFoundException("routeNotFound", "Route does not exist");

        // All requestMethod routes
        $routes = $routes[$requestMethod];

        // Resolve path and store vars along the way
        $pathVariables = [];
        $uri = $this->getRequestURI();
        $fragments = explode("/", $uri);

        while(count($fragments) > 0)
        {
            $fragment = array_shift($fragments);

            if(array_key_exists($fragment, $routes))
            {
                $routes = $routes[$fragment];
            }
            else
            {
                foreach($routes as $key => $value)
                {
                    if(is_string($key) && str_starts_ends_with($key, "{", "}"))
                    {
                        $pathVariables[unwrap($key, "{", "}")] = $fragment;
                        $routes = $routes[$key];
                        break;
                    }
                }
            }
        }

        if(array_key_exists(0, $routes)) // Found!
        {
            $route = $routes[0];

            return $this->parseRoute($route, $pathVariables);
        }
        else
        {
            throw new ResourceNotFoundException("routeNotFound", "Route does not exist");
        }
    }

    /**
     * convertObjects
     */
    private function convertObject(object $data)
    {
        if($data instanceof Response)
        {
            $this->route->setStatusCode($data->getStatusCode());
            
            return $data->getParameters();
        }
        else if($data instanceof \DateTime)
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

        if(is_array($data))
        {
            foreach($data as $key => &$value)
            {
                // Convert object
                if(is_object($value))
                    $value = $this->convertObject($value);

                // Repeat for every array
                if(is_array($value) || is_object($value))
                    $value = $this->toArray($value);
            }
        }

        return $data ?? [];
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

        // Return data or empty array
        return $returnData;
    }

    /**
     * executeResponseHandler
     */
    private function executeResponseHandler(array $responseData)
    {
        if(@Server::$responseHandler !== null && is_callable($method = @Server::$responseHandler))
            $method($responseData, $this); 
    }

    /**
     * getReturnContentType
     */
    private function printReturnValue(array $responseData)
    {
        $contentType = RequestHeader::getHeader("content-type");

        switch($contentType) {

            case "application/xml":
                Header('Content-Type: application/xml; charset=utf-8');
                http_response_code($this->getRoute()?->getStatusCode() ?? 200);
                echo (new ArrayToXmlParser())->arrayToXml($responseData)->asXML();
            exit();

            case "application/json":
            default:
                Header('Content-Type: application/json; charset=utf-8');
                http_response_code($this->getRoute()?->getStatusCode() ?? 200);
                echo json_encode($responseData);
            exit();
        }
    }

    /**
     * applyExceptionHandlers
     */
    private function applyExceptionHandlers($exception) : false | object
    {
        $exceptionClassName = get_class($exception);

        foreach(self::$exceptionHandlers as $handler)
        {
            $className = $handler["className"];
            $function = $handler["function"];

            if($exceptionClassName == $className)
            {
                $result = $function($exception);

                if($result instanceof Exception === false)
                    throw new UnexpectedValueException("Custom exception handler expects a return value of type Exception, received " . get_type($result));

                return $result;
            }
        }

        return false;
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
            $responseData = $this->executeRoute($this->route);

            // Record request time
            $this->requestEndTime = microtime(true);

            // Include request time; if response is list, then keys are added to data e.g. {"0": <response>, "requestTime": 0.00764..} will be created
            if($this->addServerTime)
                $responseData["serverTime"] = $this->getServerTime();

            if($this->addRequestTime)
                $responseData["requestTime"] = $this->getRequestTime();

            // Execute executeResponseHandler
            $this->executeResponseHandler($responseData);

            // Print result
            if($printReturnValue)
                $this->printReturnValue($responseData);

            // Return result
            else
                return $responseData;
        }
        catch(HTTPRequestException $ex)
        {
            $ex->sendJson();
        }
        catch(RuntimeException | Exception | TypeError | Throwable $ex)
        {
            $customException = $this->applyExceptionHandlers($ex);

            if($customException !== false)
            {
                $ex = $customException;
            }

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