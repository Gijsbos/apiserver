<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use gijsbos\ApiServer\Classes\DeleteRoute;
use gijsbos\ApiServer\Classes\GetRoute;
use gijsbos\ApiServer\Classes\OptionsRoute;
use gijsbos\ApiServer\Classes\PostRoute;
use gijsbos\ApiServer\Classes\PutRoute;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\RouteController;
use gijsbos\ApiServer\Server;
use gijsbos\Logging\Classes\LogEnabledClass;

use function gijsbos\Logging\Library\log_debug;
use function gijsbos\Logging\Library\log_info;

/**
 * RouteParser
 */
class RouteParser extends LogEnabledClass
{
    private array $cacheFiles;

    /**
     * __construct
     */
    public function __construct()
    {
        parent::__construct();

        $this->cacheFiles = [];
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
    public static function getRoute(string|ReflectionMethod $method, null|string $className = null)
    {
        if(is_string($method))
        {
            if(!is_string($className))
                throw new InvalidArgumentException(__METHOD__ . " failed: Argument 2 'className' must not be null when argument 1 is a string");
            else
                $method = new ReflectionMethod($className, $method);
        }   

        $classNames = [Route::class, GetRoute::class, PostRoute::class, PutRoute::class, DeleteRoute::class, OptionsRoute::class];

        foreach($classNames as $className)
        {
            if(count($method->getAttributes($className)) > 0)
            {
                $routes = $method->getAttributes($className);
                $route = reset($routes);
                return $route->newInstance();
            }
        }

        return false;
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
                log_info("Added method (".$route->getRequestMethod().") \"$methodName\" in \"$className\" with path: " . $route->getPath());

                $method = $route->getRequestMethod();
                $path = str_must_start_with($route->getPath(), "/");
                $pathPattern = $route->getPathPattern();
                $index = substr_count($path, "/");

                if(!is_dir(Server::$CACHE_FOLDER))
                    mkdir(Server::$CACHE_FOLDER, 0777, true);

                if(!is_dir(Server::$CACHE_FOLDER."/$method"))
                    mkdir(Server::$CACHE_FOLDER."/$method");

                $cacheFileName = Server::$CACHE_FOLDER."/$method/$index";

                if(!in_array($cacheFileName, $this->cacheFiles))
                {
                    file_put_contents($cacheFileName, ""); // Clear file

                    $this->cacheFiles[] = $cacheFileName;
                }

                file_put_contents($cacheFileName, "$pathPattern $className::$methodName\n", FILE_APPEND);
            }
        }
    }
}