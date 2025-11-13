<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionClass;
use ReflectionMethod;

use gijsbos\ApiServer\Classes\ReturnFilter;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Classes\RouteAttribute;
use gijsbos\ApiServer\RouteController;
use gijsbos\ClassParser\Classes\ClassComponent;
use gijsbos\ClassParser\Classes\ClassObject;
use gijsbos\ClassParser\ClassParser;
use gijsbos\Logging\Classes\LogEnabledClass;

/**
 * RouteTestGenerator
 */
class RouteTestGenerator extends LogEnabledClass
{
    const DEFAULT_CONFIG_FILE = "generator-config.json";

    private string $baseUrl;

    /**
     * __construct
     */
    public function __construct(array $opts = [])
    {
        parent::__construct();

        $config = $this->loadConfig();

        $this->baseUrl = @$opts["baseUrl"] ?? @$config["baseUrl"] ?? "http://localhost";
    }

    /**
     * loadConfig
     */
    private function loadConfig()
    {
        if(is_file(self::DEFAULT_CONFIG_FILE))
            return json_decode(file_get_contents(self::DEFAULT_CONFIG_FILE), true);

        return [];
    }

    /**
     * getRouteControllerClasses
     */
    private function getRouteControllerClasses()
    {
        return array_values(array_filter(get_declared_classes(), fn($c) => is_subclass_of($c, RouteController::class)));
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

        // Create content
        $namespaceContent = is_string($namespace) && strlen($namespace) > 0 ? "\n\nnamespace $namespace;" : "";

        // Return content
        $fileContent =  <<< PHP
                        <?php
                        declare(strict_types=1);$namespaceContent

                        use PHPUnit\Framework\TestCase;

                        use \gijsbos\Http\Http\HTTPRequest;

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
     * createHTTPRequest
     */
    private function createHTTPRequest(string $uri, array $data = [], array $headers = [])
    {
        $dataContent = "";
        foreach($data as $key => $value)
        {
            if(strlen($dataContent) == 0)
                $dataContent = ",\n            \"data\" => [\n";

            $dataContent.="                \"$key\" => $value,";
        }

        if(strlen($dataContent))
            $dataContent = "$dataContent\n            ]";

        $headerContent = "";
        foreach($headers as $key => $value)
        {
            if(strlen($headerContent) == 0)
                $headerContent = ",\n            \"headers\" => [\n";

            $headerContent.="                \"$key\" => $value,";
        }

        if(strlen($headerContent))
            $headerContent = "$headerContent\n            ]";

        return <<<PHP
HTTPRequest::get([
            "uri" => "$uri"$dataContent$headerContent
        ]);
PHP;
    }

    /**
     * createGetMethod
     */
    private function createGetMethod(ReflectionMethod $method, Route $route, ClassObject &$classObject)
    {
        $testFunctionName = "test" . ucfirst($method->getName());

        // Create method
        if($classObject->hasMethod($testFunctionName))
            return true;

        // Get returnFilter
        $returnFilter = RouteParser::getReflectionMethodAttributeOfSubclass($method, RouteAttribute::class, ReturnFilter::class);

        // Set return filter
        if($returnFilter !== false)
            $returnFilter = $returnFilter->newInstance();

        // Get path
        $uri = $this->baseUrl . str_must_start_with(str_replace("{", "{\$", $route->getPath()), "/");

        // Get path vars
        $pathVariables = implode("\n", array_map(function($name) {
            return "        \$$name = null;";
        }, $route->getPathVariableNames()));

        // Add newline
        if(strlen($pathVariables) > 0)
            $pathVariables = "$pathVariables\n";

        // Create component
        $testMethod = $this->createUnitTestMethod($testFunctionName);

        // Create content
        $httpRequestContent = $this->createHTTPRequest($uri);

        // Add body
        $testMethod->body = <<< EOD
$pathVariables
        \$response = $httpRequestContent\n
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
                $route = RouteParser::getReflectionMethodAttributeOfSubclass($method, Route::class);

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

                switch($route->getRequestMethod())
                {
                    case "GET":
                        $this->createGetMethod($method, $route, $classObject);
                }
            } 
            
            if($classObject !== null)
            {
                var_dump($classObject->toString());exit();
                file_put_contents($classObject->getFileName(), $classObject->toString());
            }
        }
    }
}