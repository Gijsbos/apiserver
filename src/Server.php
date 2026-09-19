<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use Exception;
use RuntimeException;
use Throwable;
use TypeError;
use UnexpectedValueException;

use gijsbos\Http\Response;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Attributes\ReturnFilter;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Authentication\AuthenticationVerifier;
use gijsbos\Http\Exceptions\HTTPRequestException;
use gijsbos\Http\Exceptions\ResourceNotFoundException;
use gijsbos\Http\Exceptions\UpgradeRequiredException;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\Parsers\ArrayToXmlParser;
use gijsbos\ApiServer\Utils\RouteMethodParamsFactory;
use gijsbos\ApiServer\Parsers\RouteParser;
use gijsbos\Logging\Classes\LogEnabledClass;

/**
 * Server
 */
class Server extends LogEnabledClass
{
    const APCU_ROUTES_KEY = "APCU_ROUTES";

    public static string $DEFAULT_ROUTES_FILE = "cache/apiserver/routes.php";
    public static null|array $ROUTE_CACHE = null;

    /**
     * @var SecurityContext|null securityContext
     *  When set, authenticate() runs on every request before route resolution
     */
    public static null|SecurityContext $securityContext = null;

    /**
     * @var SecurityContext|null securityContextAfterRouteResolve
     *  When set, authenticate() runs on every request AFTER route resolution
     */
    public static null|SecurityContext $securityContextAfterRouteResolve = null;

    /**
     * @var Cors|null cors
     *  When set, handle() runs on every request before route resolution
     */
    public static null|Cors $cors = null;

    /**
     * @var array beforeRequestHandlers
     *  Allows for custom handling before a route is resolved, beyond
     *  $securityContext/$cors - see addBeforeRequestHandler()
     */
    public static array $beforeRequestHandlers = [];

    /**
     * @var callable|null onRouteNotFoundHandler
     *  Allows for custom handling of route not found
     */
    public static $onRouteNotFoundHandler = null;

    /**
     * @var callable|null responseHandler
     *  Allows for custom response handling
     */
    public static $responseHandler = null;

    /**
     * @var array exceptionHandlers
     *  Allows for custom exception handling
     */
    public static null|array $exceptionHandlers = [];

    /**
     * @var string requestMethod
     */
    private string $requestMethod;

    /**
     * @var string requestURI
     */
    private string $requestURI;

    /**
     * @var string pathPrefix
     */
    private string $pathPrefix;

    /**
     * @var null|false|RouteInterface route
     */
    private null|false|RouteInterface $route;

    /**
     * @var null|float requestStartTime
     */
    private null|float $requestStartTime;

    /**
     * @var null|float requestEndTime
     */
    private null|float $requestEndTime;

    /**
     * @var bool $requireHttps
     */
    private bool $requireHttps;

    /**
     * @var bool $escapeResult
     */
    private bool $escapeResult;

    /**
     * @var bool $addServerTime
     */
    private bool $addServerTime;

    /**
     * @var bool $addRequestTime
     */
    private bool $addRequestTime;

    /**
     * @var string $dateTimeFormat
     */
    private string $dateTimeFormat;

    /**
     * @var string $routesFile
     */
    private string $routesFile;

    /**
     * @var null|AuthenticationVerifier $authenticationVerifier
     */
    private null|AuthenticationVerifier $authenticationVerifier;

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
        $this->authenticationVerifier = @$opts["authenticationVerifier"];

        $this->setLogOutput("file");
    }

    /**
     * getAuthenticationVerifier
     */
    public function getAuthenticationVerifier()
    {
        return $this->authenticationVerifier;
    }

    /**
     * setAuthenticationVerifier
     */
    public function setAuthenticationVerifier(AuthenticationVerifier $authenticationVerifier)
    {
        $this->authenticationVerifier = $authenticationVerifier;
    }

    /**
     * addBeforeRequestHandler
     */
    public static function addBeforeRequestHandler(callable $handler) : void
    {
        self::$beforeRequestHandlers[] = $handler;
    }

    /**
     * setResponseHandler
     */
    public static function setResponseHandler(callable $handler) : void
    {
        self::$responseHandler = $handler;
    }

    /**
     * setOnRouteNotFoundHandler
     */
    public static function setOnRouteNotFoundHandler(callable $handler) : void
    {
        self::$onRouteNotFoundHandler = $handler;
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

        return $_SERVER["REQUEST_TIME_FLOAT"];
    }

    /**
     * getRequestDuration
     */
    public function getRequestDuration() : null|float
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
            throw new UpgradeRequiredException("httpsRequired", "Requests must be made over HTTPS");
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

        // Parse path variables
        $pathVariables = array_map_assoc(function($placeholderIndex, $placeholderName) use ($pathVariables) {
            return [$placeholderName, $pathVariables[$placeholderIndex]];
        }, explode_enclosed("{", "}", $route->getPath()));

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
        if(is_string($scan = getenv("CLI_SCAN_FOLDERS")))
        {
            RouteParser::includeControllers(explode(",", $scan));
        }
        
        (new RouteParser($this->routesFile))->parseControllerFiles();
    }

    /**
     * getRoutes
     */
    private function getRoutes()
    {
        if(function_exists('apcu_fetch') && function_exists('apcu_store'))
        {
            $routes = apcu_fetch(self::APCU_ROUTES_KEY, $success);

            if ($success)
            {
                return $routes;
            }
            else
            {
                $routes = require_once $this->routesFile;

                apcu_store(self::APCU_ROUTES_KEY, $routes);

                return $routes;
            }
        }
        else
        {
            if(self::$ROUTE_CACHE !== null)
                return self::$ROUTE_CACHE;

            self::$ROUTE_CACHE = require_once $this->routesFile;

            return self::$ROUTE_CACHE;
        }
    }

    /**
     * matchRoute
     */
    public function matchRoute()
    {
        // Create routes if they don't exist
        if(!is_file($this->routesFile))
            $this->createRouteCache();

        // Include the routes into memory
        $routes = $this->getRoutes();

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

        // Traverse the trie per fragment
        while(count($fragments) > 0)
        {
            // Get next
            $fragment = array_shift($fragments);

            // Exact match
            if(array_key_exists($fragment, $routes))
            {
                $routes = $routes[$fragment];
            }

            // Search for placeholder
            else
            {
                $foundPlaceholder = false;
                
                foreach($routes as $key => $value)
                {
                    if(is_string($key) && str_starts_ends_with($key, "{", "}")) // Found placeholder
                    {
                        $routes = $routes[$key];
                        $pathVariables[unwrap($key, "{", "}")] = $fragment;
                        $foundPlaceholder = true;
                        break;
                    }
                }
                
                if(!$foundPlaceholder)
                    throw new ResourceNotFoundException("routeNotFound", "Route does not exist");
            }
        }

        if(array_key_exists(0, $routes)) // Found!
        {
            $route = $routes[0];
            return $this->parseRoute($route, array_values($pathVariables)); // Reset indices
        }
        else
        {
            throw new ResourceNotFoundException("routeNotFound", "Route does not exist");
        }
    }

    /**
     * convertObject
     */
    private function convertObject(object $data)
    {
        if($data instanceof Response)
        {
            $this->route->setStatusCode($data->getStatusCode());

            return $data->getParameters();
        }
        else if($data instanceof \stdClass)
        {
            return $data;
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
            // Called from outside $data's class, so this naturally sees only
            // public (declared + dynamic) properties - no reflection needed.
            return get_object_vars($data);
        }
    }

    /**
     * processData
     */
    private function processData($data)
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
                    $value = $this->processData($value);
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

        // Execute security context first
        self::$securityContextAfterRouteResolve?->authenticate($this);

        // Execute route
        $returnData = $controller->$methodName(...((new RouteMethodParamsFactory())->generateMethodParams($route)));

        // Process data
        $returnData = $this->processData($returnData);

        // Apply filter
        $returnData = $this->applyReturnFilter($route, $returnData);

        // Escape result for safety
        $returnData = $this->applyEscapeResult($returnData);

        // Return data or empty array
        return $returnData;
    }

    /**
     * invokeHandler
     */
    private function invokeHandler(mixed $handler, array $args) : void
    {
        if(is_callable($handler))
            $handler(...$args);
    }

    /**
     * executeBeforeRequestHandlers
     */
    private function executeBeforeRequestHandlers() : void
    {
        foreach(self::$beforeRequestHandlers as $callable)
            $this->invokeHandler($callable, [$this]);
    }

    /**
     * executeOnRouteNotFoundHandler
     */
    private function executeOnRouteNotFoundHandler() : void
    {
        $this->invokeHandler(self::$onRouteNotFoundHandler, [$this]);
    }

    /**
     * executeResponseHandler
     */
    private function executeResponseHandler(array $responseData) : void
    {
        $this->invokeHandler(self::$responseHandler, [$responseData, $this]);
    }

    /**
     * printReturnValue
     */
    private function printReturnValue(array $responseData)
    {
        $contentType = RequestHeader::getHeader("content-type");

        switch($contentType) {

            case "application/xml":
                Header('Content-Type: application/xml; charset=utf-8');
                http_response_code($this->getRoute()?->getStatusCode() ?? 200);
                echo (new ArrayToXmlParser())->arrayToXml($responseData)->asXML();
            return;

            case "application/json":
            default:
                Header('Content-Type: application/json; charset=utf-8');
                http_response_code($this->getRoute()?->getStatusCode() ?? 200);
                echo json_encode($responseData);
            return;
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

            // Handle CORS - preflight OPTIONS requests are short-circuited here,
            // before any auth check (a preflight never carries credentials)
            if(self::$cors?->handle($this) === true)
                return;

            // Enforce SecurityContext's path-based authorization rules
            self::$securityContext?->authenticate($this);

            // Execute any other registered before-request handlers
            $this->executeBeforeRequestHandlers();

            // Lookup route
            $this->route = $this->matchRoute();

            // Not found
            if($this->route === false)
            {
                // Execute security context first
                self::$securityContextAfterRouteResolve?->authenticate($this);

                // Execute onRouteNotFoundHandler
                $this->executeOnRouteNotFoundHandler();

                // Route not found
                throw new ResourceNotFoundException("routeNotFound", "Resource could not be found");
            }

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

            if($ex->getStatusCode() == 500)
            {
                log_error($ex->getMessage());
                log_error($ex->getTraceAsString());
            }
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
                return;
            }

            if($ex instanceof HTTPRequestException)
            {
                $ex->sendJson();
            }
            else
            {
                http_response_code(500);
                print(json_encode([
                    "error" => get_class($ex),
                    "errorDescription" => $ex->getMessage(),
                    "statusCode" => 500,
                ]));
                return;
            }
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