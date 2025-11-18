<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Interfaces\RouteInterface;
use gijsbos\ApiServer\RouteController;
use gijsbos\ApiServer\Server;
use gijsbos\CLIParser\CLIParser\Command;
use gijsbos\Logging\Classes\LogEnabledClass;

/**
 * RouteParser
 */
class RouteParser extends LogEnabledClass
{
    private array $cacheFiles;

    /**
     * __construct
     */
    public function __construct(private string $cacheFolder)
    {
        parent::__construct();

        $this->cacheFiles = [];
    }

    /**
     * getCacheFolder
     */
    public function getCacheFolder() : string
    {
        return $this->cacheFolder;
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
     * getControllerClasses
     */
    private function getControllerClasses()
    {
        $classes = [];
        foreach(get_declared_classes() as $className) 
        {
            $ref = new ReflectionClass($className);

            if ($ref->isSubclassOf(RouteController::class))
            {
                $classes[] = $ref;
            }
        }
        return $classes;
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
     * addToCache
     */
    private function addToCache(string $className, string $methodName, RouteInterface $route)
    {
        $method = $route->getRequestMethod();
        $path = str_must_start_with($route->getPath(), "/");
        $pathPattern = $route->getPathPattern();
        $index = substr_count($path, "/");
        $cacheFolder = $this->cacheFolder;

        if(!is_dir($cacheFolder))
            mkdir($cacheFolder, 0777, true);

        if(!is_dir($cacheFolder."/$method"))
            mkdir($cacheFolder."/$method");

        $cacheFileName = $cacheFolder."/$method/$index";

        if(!in_array($cacheFileName, $this->cacheFiles))
        {
            file_put_contents($cacheFileName, ""); // Clear file

            $this->cacheFiles[] = $cacheFileName;
        }

        file_put_contents($cacheFileName, "$pathPattern $className::$methodName\n", FILE_APPEND);
    }

    /**
     * parseMethods
     */
    private function parseMethods(string $className, array $methods)
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

            log_info("Add method (".$route->getRequestMethod().") \"$methodName\" in \"$className\" with path: " . $route->getPath());

            $this->addToCache($className, $methodName, $route);
        }
    }

    /**
     * parseControllerFiles
     */
    public function parseControllerFiles()
    {
        $classes = $this->getControllerClasses();

        log_info("Found \"".count($classes)."\" controller(s)");

        foreach($classes as $reflection)
        {
            $className = $reflection->getName();

            $methods = array_filter($reflection->getMethods(), fn($r) => $r->isPublic());
            
            $this->parseMethods($className, $methods);
        }
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
        (new RouteParser($command?->getOption("cache-folder") ?? Server::$DEFAULT_CACHE_FOLDER))
        ->setVerbose($command instanceof Command ? $command->hasFlag("v") : null)
        ->setDebug($command instanceof Command ? $command->hasFlag("d") : null)
        ->parseControllerFiles();
    }
}