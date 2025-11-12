<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use gijsbos\ApiServer\Classes\RequiresAuthorization;
use gijsbos\ApiServer\Classes\ReturnFilter;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Classes\RouteAttribute;
use gijsbos\ApiServer\RouteController;
use gijsbos\ClassParser\Classes\ClassComponent;
use gijsbos\ClassParser\Classes\ClassObject;
use gijsbos\ClassParser\ClassParser;
use gijsbos\Logging\Classes\LogEnabledClass;
use ReflectionClass;
use ReflectionMethod;

use function gijsbos\Logging\Library\log_debug;
use function gijsbos\Logging\Library\log_info;

/**
 * RouteTestGenerator
 */
class RouteTestGenerator extends LogEnabledClass
{
    /**
     * getRouteControllerClasses
     */
    private function getRouteControllerClasses()
    {
        return array_values(array_filter(get_declared_classes(), fn($c) => is_subclass_of($c, RouteController::class)));
    }

    /**
     * getMethodAttributeOfSubclass
     */
    public function getMethodAttributeOfSubclass(ReflectionMethod $method, string $subclass, null|string $name = null)
    {
        $result = array_values(array_filter($method->getAttributes(), fn($a) => is_subclass_of($a->getName(), $subclass) && ($name == null || $name == $a->getName())));

        if($name !== null)
        {
            return reset($result);
        }

        return $result;
    }

    /**
     * getTestController
     */
    private function getTestController(ReflectionClass $reflection, string $outputFolder)
    {
        // Get controller name
        $testControllerName = $reflection->getShortName() . "Test";

        // Get path
        $filePath = "$outputFolder/$testControllerName.php";

        // Return existing file
        if(is_file($filePath))
            return ClassParser::parse($filePath);
        
        // Create class and add methods
        $classComponent = new ClassComponent(ClassComponent::NEW_CLASS, $testControllerName, null, "/**\n * $testControllerName\n */");
        $classComponent->curlyBracketOnNewline = true;
        $classComponent->extends = "TestCase";
        $classContent = $classComponent->toString();

        // Create file
        $namespace = $reflection->getNamespaceName();

        // Return content
        $fileContent =  <<< PHP
                        <?php
                        declare(strict_types=1);

                        namespace $namespace;

                        use PHPUnit\Framework\TestCase;

                        $classContent
                        PHP;

        // Create directory
        if(!is_dir(dirname($filePath)))
            mkdir(dirname($filePath), 0777, true);

        // Create file
        file_put_contents($filePath, $fileContent);

        // Return with file now existing
        return $this->getTestController($reflection, $outputFolder);
    }

    /**
     * createSetupBeforeClassMethod
     */
    private function createSetupBeforeClassMethod(ClassObject &$classObject) : void
    {
        // Create component
        $setupBeforeClass = new ClassComponent(ClassComponent::NEW_METHOD, "setupBeforeClass");
        $setupBeforeClass->isPublic = true;
        $setupBeforeClass->isStatic = true;
        $setupBeforeClass->curlyBracketOnNewline = true;
        $setupBeforeClass->trailingLineBreaks = 2;
        $setupBeforeClass->returnType = "void";

        // Add
        $classObject->component->methods[$setupBeforeClass->name] = $setupBeforeClass;
    }

    /**
     * createSetUpMethod
     */
    private function createSetUpMethod(ClassObject &$classObject) : void
    {
        $setup = new ClassComponent(ClassComponent::NEW_METHOD, "setUp");
        $setup->isProtected = true;
        $setup->curlyBracketOnNewline = true;
        $setup->trailingLineBreaks = 2;
        $setup->returnType = "void";

        // Add
        $classObject->component->methods[$setup->name] = $setup;
    }

    /**
     * createUnitTestMethod
     */
    private function createUnitTestMethod(string $testFunctionName) : ClassComponent
    {
        $classComponent = new ClassComponent(ClassComponent::NEW_METHOD, $testFunctionName, null);
        $classComponent->isPublic = true;
        $classComponent->curlyBracketOnNewline = true;
        $classComponent->trailingLineBreaks = 2;
        $classComponent->returnType = "void";
        return $classComponent;
    }

    /**
     * createGetMethod
     */
    private function createGetMethod(ReflectionMethod $method, Route $route, ClassObject &$classObject)
    {
        $testFunctionName = $method->getName() . "Test";

        $returnFilter = $this->getMethodAttributeOfSubclass($method, RouteAttribute::class, ReturnFilter::class);

        if($returnFilter !== false)
        {

        }

        // Get path
        $path = str_must_start_with(str_replace("{", "{\$", $route->getPath()), "/");

        // Get path vars
        $pathVariables = implode("\n", array_map(function($name)
        {
            return "        \$$name = null;";
        }, $route->getPathVariableNames()));

        // Create component
        $testMethod = $this->createUnitTestMethod($testFunctionName);

        // Add body
        $testMethod->body = <<< EOD
$pathVariables
        \$response = HTTPRequest::get(["uri" => "http://localhost$path"]);
        \$this->assertTrue(\$response->isSuccessful());
EOD;

        // Add
        $classObject->component->methods[$testMethod->name] = $testMethod;
    }

    /**
     * generate
     */
    public function generate(string $outputFolder = "tests/auto")
    {
        $classes = $this->getRouteControllerClasses();

        foreach($classes as $class)
        {
            log_info("Parsing class: " . $class);

            $reflection = new ReflectionClass($class);

            $classObject = null; 

            $publicMethods = array_values(array_filter($reflection->getMethods(), fn($m) => $m->isPublic()));

            foreach($publicMethods as $method)
            {
                $route = $this->getMethodAttributeOfSubclass($method, Route::class);

                // Skip method
                if(count($route) == 0)
                {
                    log_debug("Route not defined, skipping");
                    continue;
                }

                // Create instance
                $route = reset($route);

                // Create instance
                $route = $route->newInstance();

                // We create the object after we are sure there are routes in it.
                if($classObject == null)
                    $classObject = $this->getTestController($reflection, $outputFolder);

                // Parsing
                log_info("Parsing method: " . $method->getName());
                
                // SetupBeforeClass
                if(!$classObject->hasMethod('createSetupBeforeClassMethod'))
                {
                    $this->createSetupBeforeClassMethod($classObject);
                }

                // Add SetUP
                if(!$classObject->hasMethod('setUp'))
                {
                    $this->createSetUpMethod($classObject);
                }
                
                // Add route test
                $methodTestName = $method->getName() . "Test";

                // Create method
                if(!$classObject->hasMethod($methodTestName))
                {
                    switch($route->getRequestMethod())
                    {
                        case "GET":
                            $this->createGetMethod($method, $route, $classObject);
                    }
                }
            } 
            
            if($classObject !== null)
            {
                echo($classObject->toString());
                exit();
            }
        }
    }
}