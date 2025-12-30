<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use gijsbos\ApiServer\Attributes\Published;
use gijsbos\ApiServer\Attributes\Route;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use gijsbos\ApiServer\RouteController;
use gijsbos\ApiServer\Server;
use gijsbos\CLIParser\CLIParser\Command;
use gijsbos\Logging\Classes\LogEnabledClass;
use ReflectionAttribute;

/**
 * RouteParser
 */
class RouteParser extends LogEnabledClass
{
    /**
     * __construct
     */
    public function __construct(private string $routesFile, array $opts = [])
    {
        parent::__construct($opts);
    }

    /**
     * includeControllers
     */
    public static function includeControllers(array $includes)
    {
        foreach($includes as $include)
            include_recursive($include);
    }

    /**
     * getRouteControllerClasses
     */
    public static function getRouteControllerClasses()
    {
        return array_map(fn($class) => new ReflectionClass($class), array_values(array_filter(get_declared_classes(), fn($c) => is_subclass_of($c, RouteController::class))));
    }

    /**
     * getRouteControllerClassMethods
     * 
     * @return ReflectionMethod[]
     */
    public static function getRouteControllerClassMethods(ReflectionClass $reflection)
    {
        return array_values(array_filter($reflection->getMethods(), fn($m) => $m->isPublic() && $m->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF)));
    }

    /**
     * getRoute
     */
    public static function getRoute(string|array|ReflectionMethod $method, null|string $className = null)
    {
        if(is_array($method) && is_null($className))
        {
            if(count($method) !== 2)
                throw new InvalidArgumentException("Argument 1 'method' using array must be of format [className, method]");

            [$className, $method] = $method;
        }

        if(is_string($method))
        {
            if(!is_string($className))
                throw new InvalidArgumentException(__METHOD__ . " failed: Argument 2 'className' must not be null when argument 1 is a string");
            else
                $method = new ReflectionMethod($className, $method);
        }

        $routeAttributes = self::getReflectionMethodAttributeOfClass($method, Route::class);
        $routeInheritedAttributes = self::getReflectionMethodAttributeOfSubclass($method, Route::class);
        $routeAttributes = array_merge($routeAttributes, $routeInheritedAttributes);

        if(count($routeAttributes) == 0)
            return false;
        else if(count($routeAttributes) > 1)
            throw new InvalidArgumentException("Multiple Route attributes set, only one permitted");

        return reset($routeAttributes)->newInstance();
    }

    /**
     * buildTrix
     */
    private function buildTrix(Route $route, array &$prefixTree, string $classMethod)
    {
        $path = $route->getPath();
        $requestMethod = $route->getRequestMethod();

        if(!array_key_exists($requestMethod, $prefixTree))
            $prefixTree[$requestMethod] = [];

        $keys = explode("/", $path);

        $current = &$prefixTree[$requestMethod];
        $depth = 0;

        while(count($keys) > 0)
        {
            $key = array_shift($keys);

            $isPlaceholder = str_starts_ends_with($key, "{", "}");

            $key = $isPlaceholder ? "{{$depth}}" : $key;

            if(!array_key_exists($key, $current))
            {
                if(count($keys) == 0)
                {
                    $current[$key][] = $classMethod;
                }
                else
                {
                    $current[$key] = [];
                }

                $depth += 1;
                $current = &$current[$key];
            }
            else
            {
                if(count($keys) == 0)
                {
                    $current[$key][] = $classMethod;
                }
                else
                {
                    $depth += 1;
                    $current = &$current[$key];
                }
            }
        }
    }   

    /**
     * parseMethods
     * 
     * @param ReflectionMethod[] $methods
     */
    private function parseMethods(string $className, array $methods, array &$prefixTree)
    {
        log_debug("Parsing \"$className\", found \"".count($methods)."\" controller methods");

        foreach($methods as $method)
        {
            $methodName = $method->getName();

            log_debug("Parsing method \"$methodName\"");

            $route = $this->getRoute($method);

            if($route == false)
            {
                log_debug("Skipping method \"$methodName\" in \"$className\", no Route attribute set");
                continue;
            }

            $published = $method->getAttributes(Published::class);

            if(count($published) > 0)
            {
                $published = $published[0]->newInstance();
                
                if(!$published->isPublished())
                {
                    log_debug("Skipping method \"$methodName\" in \"$className\", Route not Published");
                    continue;
                }
            }

            log_info("Add method (".$route->getRequestMethod().") \"$methodName\" in \"$className\" with path: " . $route->getPath());
            $this->buildTrix($route, $prefixTree, "$className::$methodName");
        }
    }

    /**
     * createPHPArray
     */
    private function createPHPArray(array $prefixTree, int $level = 1)
    {
        $base = str_repeat("    ", $level - 1);
        $indents = str_repeat("    ", $level);
        $array = "[\n";
        foreach($prefixTree as $key => $value)
        {
            $key = is_numeric($key) ? $key : "\"$key\"";

            if(is_array($value))
            {
                $array .= "$indents$key => " . $this->createPHPArray($value, $level + 1) . ",\n";
            }
            else
            {
                $array .= "$indents$key => \"$value\",\n";
            }
        }
        return "$array$base]";
    }

    /**
     * createTrie
     */
    private function createTrie(array $prefixTree)
    {
        $phpArray = $this->createPHPArray($prefixTree);

        return "<?php\n\nreturn $phpArray;";
    }

    /**
     * parseControllerFiles
     */
    public function parseControllerFiles()
    {
        $prefixTree = [];

        $classes = self::getRouteControllerClasses();

        log_info("Found \"".count($classes)."\" controller(s)");

        foreach($classes as $class)
        {
            $className = $class->getName();

            $methods = self::getRouteControllerClassMethods($class);

            $this->parseMethods($className, $methods, $prefixTree);
        }

        $trie = $this->createTrie($prefixTree);

        if(!is_dir($targetDir = dirname($this->routesFile)))
            mkdir($targetDir, 0777, true);

        file_put_contents($this->routesFile, $trie);
    }

    /**
     * getReflectionMethodAttributeOfClass
     */
    public static function getReflectionMethodAttributeOfClass(ReflectionMethod $method, string $className)
    {
        return array_values(array_filter($method->getAttributes(), fn($a) => $a->getName() == $className));
    }

    /**
     * getReflectionMethodAttributeOfSubclass
     */
    public static function getReflectionMethodAttributeOfSubclass(ReflectionMethod $method, string $subclass, null|string $name = null)
    {
        $result = array_values(array_filter($method->getAttributes(), fn($a) => is_subclass_of($a->getName(), $subclass) && ($name == null || $name == $a->getName())));

        if($name !== null)
        {
            return reset($result);
        }

        return $result;
    }

    /**
     * run
     */
    public static function run(null|Command $command = null)
    {
        (new RouteParser($command?->getOption("routes-file") ?? Server::$DEFAULT_ROUTES_FILE))
        ->setVerbose($command instanceof Command ? ($command->hasFlag("v") ? true : null) : null)
        ->setDebug($command instanceof Command ? ($command->hasFlag("d") ? true : null) : null)
        ->parseControllerFiles();
    }
}