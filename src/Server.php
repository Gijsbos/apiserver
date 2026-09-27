<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use Exception;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

use gijsbos\Http\Response;
use gijsbos\Http\Utils\ArrayToXml;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Attributes\ReturnFilter;
use gijsbos\ApiServer\Attributes\Route;
use gijsbos\ApiServer\Interfaces\AuthorizationHeaderVerifierInterface;
use gijsbos\Http\Exceptions\HTTPRequestException;
use gijsbos\Http\Exceptions\InternalServerErrorException;
use gijsbos\Http\Exceptions\ResourceNotFoundException;
use gijsbos\Http\Exceptions\UpgradeRequiredException;
use gijsbos\ApiServer\Interfaces\RouteInterface;
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
    public static array $exceptionHandlers = [];

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
     * @var null|AuthorizationHeaderVerifierInterface $authorizationHeaderVerifier
     */
    private null|AuthorizationHeaderVerifierInterface $authorizationHeaderVerifier;

    /**
     * @var null|array $authorizationResult
     */
    private null|array $authorizationResult;

    /**
     * @var string $requestId
     *  Generated for every request (UUID v4), never taken from the client: unique and trustworthy
     */
    private string $requestId;

    /**
     * @var string $correlationId
     *  Shared by all requests of a chain across services, see extractCorrelationId
     */
    private string $correlationId;

    /**
     * @var array $trustedProxies
     *  IP addresses or CIDR ranges of the reverse proxies / load balancers in front of the server.
     *  Only requests from these may set X-Forwarded-For and X-Forwarded-Proto.
     */
    private array $trustedProxies;

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
        $this->addServerTime = array_key_exists("addServerTime", $opts) ? boolval($opts["addServerTime"]) : false;
        $this->addRequestTime = array_key_exists("addRequestTime", $opts) ? boolval($opts["addRequestTime"]) : false;
        $this->dateTimeFormat = @$opts["dateTimeFormat"] ?? "ISO8601";
        $this->routesFile = @$opts["routesFile"] ?? self::$DEFAULT_ROUTES_FILE;
        $this->authorizationHeaderVerifier = @$opts["authorizationHeaderVerifier"];
        $this->authorizationResult = null;
        $this->trustedProxies = is_array(@$opts["trustedProxies"]) ? array_values($opts["trustedProxies"]) : [];
        $this->requestId = uuid4();
        $this->correlationId = self::extractCorrelationId() ?? $this->requestId;

        $this->setLogOutput("file");
    }

    /**
     * extractCorrelationId
     *  The id of the chain this request belongs to, from the first valid header:
     *      traceparent         - W3C Trace Context, the trace-id
     *      X-Correlation-ID    - a correlation id
     *      X-Request-Id        - the request id of the caller
     *  Headers are client supplied: an id that does not match (control characters, too long) is ignored,
     *  it never reaches the logs. Returns null when there is none, the request then starts a new chain.
     */
    private static function extractCorrelationId() : null|string
    {
        $traceparent = RequestHeader::getHeader("traceparent");

        // version-traceid-parentid-flags, an all zero trace-id and version ff are invalid
        if(is_string($traceparent) && preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-[0-9a-f]{16}-[0-9a-f]{2}$/D', strtolower(trim($traceparent)), $match) && $match[1] !== "ff" && $match[2] !== str_repeat("0", 32))
            return $match[2];

        foreach(["x-correlation-id", "x-request-id"] as $headerName)
        {
            $value = RequestHeader::getHeader($headerName);

            if(is_string($value) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', trim($value)))
                return trim($value);
        }

        return null;
    }

    /**
     * getRequestId
     *  The id of this request, see $requestId. Add it to log entries and return it to the caller for support.
     */
    public function getRequestId() : string
    {
        return $this->requestId;
    }

    /**
     * getCorrelationId
     *  The id of the chain this request belongs to (see extractCorrelationId), the request id when it starts a new chain.
     *  Add it to log entries and pass it on to calls the request makes.
     */
    public function getCorrelationId() : string
    {
        return $this->correlationId;
    }

    /**
     * ipInRange
     *  $range is an IP address or a CIDR range (e.g. 10.0.0.0/8, fd00::/8)
     */
    private static function ipInRange(string $ip, string $range) : bool
    {
        [$subnet, $bits] = str_contains($range, "/") ? explode("/", $range, 2) : [$range, null];

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary))
            return false;

        $bits = $bits === null ? strlen($ipBinary) * 8 : (int) $bits;

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if(substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes))
            return false;

        if($remainder === 0)
            return true;

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }

    /**
     * isTrustedProxy
     */
    private function isTrustedProxy(null|string $ip) : bool
    {
        if(!is_string($ip))
            return false;

        foreach($this->trustedProxies as $range)
            if(self::ipInRange($ip, (string) $range))
                return true;

        return false;
    }

    /**
     * getClientIp
     *  REMOTE_ADDR, or behind trusted proxies the first address in X-Forwarded-For that is not a trusted proxy
     *  (read from the right: every entry left of the last trusted proxy can be set by the client)
     */
    public function getClientIp() : null|string
    {
        $remoteAddress = $_SERVER["REMOTE_ADDR"] ?? null;

        if(!$this->isTrustedProxy($remoteAddress))
            return $remoteAddress;

        $forwardedFor = RequestHeader::getHeader("x-forwarded-for");

        if(!is_string($forwardedFor))
            return $remoteAddress;

        foreach(array_reverse(array_map("trim", explode(",", $forwardedFor))) as $ip)
        {
            if(filter_var($ip, FILTER_VALIDATE_IP) === false)
                return $remoteAddress;

            if(!$this->isTrustedProxy($ip))
                return $ip;
        }

        return $remoteAddress;
    }

    /**
     * getAuthorizationHeaderVerifier
     */
    public function getAuthorizationHeaderVerifier() : null|AuthorizationHeaderVerifierInterface
    {
        return $this->authorizationHeaderVerifier;
    }

    /**
     * setAuthorizationHeaderVerifier
     */
    public function setAuthorizationHeaderVerifier(AuthorizationHeaderVerifierInterface $authorizationHeaderVerifier)
    {
        $this->authorizationHeaderVerifier = $authorizationHeaderVerifier;
    }

    /**
     * getAuthorizationResult
     */
    public function getAuthorizationResult() : null|array
    {
        return $this->authorizationResult;
    }

    /**
     * setAuthorizationResult
     */
    public function setAuthorizationResult(array $authorizationResult)
    {
        $this->authorizationResult = $authorizationResult;
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
        } elseif ($this->isTrustedProxy($_SERVER['REMOTE_ADDR'] ?? null)) {
            // TLS terminated by a trusted proxy, a client can set this header too so it is only read from a proxy
            return strtolower(trim((string) RequestHeader::getHeader('x-forwarded-proto'))) === 'https';
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

        // Execute the security context after the before route methods: they can configure how the request is verified
        // (e.g. set the AuthorizationHeaderVerifier with the authority of the route)
        self::$securityContextAfterRouteResolve?->authenticate($this);

        // Execute route
        $returnData = $controller->$methodName(...((new RouteMethodParamsFactory())->generateMethodParams($route)));

        // Process data
        $returnData = $this->processData($returnData);

        // Apply filter
        $returnData = $this->applyReturnFilter($route, $returnData);

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
     * getResponseHeaders
     *  Sent at the start of every request (OWASP REST Security Cheat Sheet), a route may replace them
     *  (e.g. an HTML page sets its own Content-Security-Policy and Cache-Control):
     *      X-Content-Type-Options      - the browser follows the content type, a response is never sniffed as HTML
     *      Cache-Control               - responses carry personal data and tokens, they are not stored
     *      Content-Security-Policy     - a JSON response loads nothing and is never framed
     *      Strict-Transport-Security   - over HTTPS only, the browser keeps using HTTPS
     *      X-Request-Id                - this request, for support
     *      X-Correlation-ID            - the chain, for the caller's logs
     */
    public function getResponseHeaders() : array
    {
        return array_filter([
            "X-Content-Type-Options" => "nosniff",
            "Cache-Control" => "no-store",
            "Content-Security-Policy" => "default-src 'none'; frame-ancestors 'none'",
            "Strict-Transport-Security" => $this->isHttps() ? "max-age=31536000" : null,
            "X-Request-Id" => $this->requestId,
            "X-Correlation-ID" => $this->correlationId,
        ], fn($value) => $value !== null);
    }

    /**
     * sendResponseHeaders
     */
    private function sendResponseHeaders() : void
    {
        // Set by PHP when expose_php is on, it reveals the PHP version (banner grabbing)
        header_remove("X-Powered-By");

        foreach($this->getResponseHeaders() as $name => $value)
            header("$name: $value");
    }

    /**
     * negotiateFormat
     *  The response format from the Accept header (the Content-Type of a request describes its body, not the answer):
     *  "xml" when application/xml is preferred over application/json, "json" otherwise (also for any type or no header)
     */
    public static function negotiateFormat(null|string $accept) : string
    {
        $best = ["json" => null, "xml" => null]; // Per format: [quality, position]

        foreach(explode(",", (string) $accept) as $position => $mediaRange)
        {
            $parts = array_map("trim", explode(";", $mediaRange));

            $format = match(strtolower(array_shift($parts))) {
                "application/json", "application/problem+json" => "json",
                "application/xml", "text/xml", "application/problem+xml" => "xml",
                default => null,
            };

            if($format === null)
                continue;

            $quality = 1.0;

            foreach($parts as $parameter)
                if(preg_match('/^q=([0-9.]+)$/i', $parameter, $match))
                    $quality = (float) $match[1];

            if($best[$format] === null || $quality > $best[$format][0])
                $best[$format] = [$quality, $position];
        }

        [$xml, $json] = [$best["xml"], $best["json"]];

        if($xml === null || $xml[0] <= 0)
            return "json";

        if($json === null || $json[0] <= 0)
            return "xml";

        // Equal quality: the first listed wins
        return $xml[0] > $json[0] || ($xml[0] === $json[0] && $xml[1] < $json[1]) ? "xml" : "json";
    }

    /**
     * getResponseFormat
     *  The format of this request's response, results and errors alike, see negotiateFormat
     */
    public function getResponseFormat() : string
    {
        return self::negotiateFormat(RequestHeader::getHeader("accept"));
    }

    /**
     * printReturnValue
     *  Responses are data, not HTML: strings are returned as is and encoded by the client for where they are shown.
     *  The content type (and nosniff, see getResponseHeaders) make sure a browser never renders a response as HTML.
     */
    private function printReturnValue(array $responseData)
    {
        switch($this->getResponseFormat()) {

            case "xml":
                header("Content-Type: application/xml; charset=utf-8");
                http_response_code($this->getRoute()?->getStatusCode() ?? 200);
                echo ArrayToXml::convert($responseData)->asXML();
            return;

            case "json":
            default:
                header("Content-Type: application/json; charset=utf-8");
                http_response_code($this->getRoute()?->getStatusCode() ?? 200);
                echo json_encode($responseData);
            return;
        }
    }

    /**
     * sendException
     *  Errors are answered in the negotiated format too; the exception sends its own content type and status code.
     *  As RFC 9457 problem details (HTTPRequestException::$useRfc9457) the instance is this request.
     */
    private function sendException(HTTPRequestException $exception) : void
    {
        $exception->send($this->getResponseFormat(), "urn:uuid:{$this->requestId}");
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
            // Every response, also HTML pages and errors, see getResponseHeaders
            $this->sendResponseHeaders();

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
            $this->sendException($ex);

            if($ex->getStatusCode() == 500)
            {
                log_error($ex->getMessage());
                log_error($ex->getTraceAsString());
            }
        }
        catch(Throwable $ex)
        {
            $customException = $this->applyExceptionHandlers($ex);

            if($customException !== false)
            {
                $ex = $customException;
            }

            // An unexpected error can carry internals (SQL, file paths), in production they are only logged
            $production = \gijsbos\ExtFuncs\Utils\Environment::isProduction();

            try
            {
                log_error($ex->getMessage());
                log_error($ex->getTraceAsString());
            }
            catch(RuntimeException $rex)
            {
                // Logging failed (e.g. the log file is not writable), its message names the log file: hidden in production too
                $this->sendException(new InternalServerErrorException(
                    $production ? "internalError" : get_class($rex),
                    $production ? "An internal error occurred" : $rex->getMessage(),
                ));
                return;
            }

            if($ex instanceof HTTPRequestException)
            {
                $this->sendException($ex);
            }
            else
            {
                $this->sendException(new InternalServerErrorException(
                    $production ? "internalError" : get_class($ex),
                    $production ? "An internal error occurred" : $ex->getMessage(),
                ));
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
            // As a web server does: "X-Custom-Header" becomes HTTP_X_CUSTOM_HEADER
            $key = str_replace("-", "_", str_starts_with(strtolower($key), "http_") ? $key : "http_$key");

            $_SERVER[strtoupper($key)] = $value;
        }
    }
}