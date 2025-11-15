<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Utils;

use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionUnionType;
use InvalidArgumentException;

use gijsbos\ApiServer\Classes\OptRequestParam;
use gijsbos\ApiServer\Classes\RequestHeader;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\ApiServer\Classes\RequiresAuthorization;
use gijsbos\ApiServer\Classes\ReturnFilter;
use gijsbos\ApiServer\Classes\Route;
use gijsbos\ApiServer\Classes\RouteAttribute;
use gijsbos\ApiServer\RouteController;
use gijsbos\ClassParser\Classes\ClassComponent;
use gijsbos\ClassParser\Classes\ClassObject;
use gijsbos\ClassParser\ClassParser;
use gijsbos\CLIParser\CLIParser\Command;
use gijsbos\Logging\Classes\LogEnabledClass;

/**
 * RouteTestGenerator
 */
class RouteTestGenerator extends LogEnabledClass
{
    /**
     * __construct
     */
    public function __construct(array $opts = [])
    {
        parent::__construct($opts);
    }

    /**
     * getRouteControllerClasses
     */
    private function getRouteControllerClasses()
    {
        return array_values(array_filter(get_declared_classes(), fn($c) => is_subclass_of($c, RouteController::class)));
    }

    /**
     * getRouteControllerClassMethods
     */
    private function getRouteControllerClassMethods(ReflectionClass $reflection)
    {
        return array_values(array_filter($reflection->getMethods(), fn($m) => $m->isPublic()));
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
                        use gijsbos\ApiServer\Utils\RouteParser;
                        use gijsbos\Http\Http\HTTPRequest;

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
     * getTypesString
     */
    public static function getTypesString(ReflectionParameter $parameter)
    {
        $type = $parameter->getType();

        if($type instanceof ReflectionUnionType)
        {
            return array_map(fn($t) => $t->getName(), $type->getTypes());
        }

        return [$type->getName()];
    }

    /**
     * createGetFullPathInlineVariables
     */
    private function createGetFullPathInlineVariables(Route $route)
    {
        $fullPathInlineVariables = implode(", ", array_map(function($name) {
            return "\$$name";
        }, $route->getPathVariableNames()));

        // Add newline
        if(strlen($fullPathInlineVariables) > 0)
            $fullPathInlineVariables = ", $fullPathInlineVariables";

        return $fullPathInlineVariables;
    }

    /**
     * createMethodParameterIndex
     */
    private function createMethodParameterIndex(ReflectionMethod $method)
    {
        $requestHeaders = [];
        $requestParams = [];

        foreach($method->getParameters() as $parameter)
        {
            $paramName = $parameter->getName();
            $types = self::getTypesString($parameter);

            if(in_array(RequestHeader::class, $types))
            {
                $requestHeaders[$paramName] = [
                    "name" => $parameter->getName(),
                    "parameter" => $parameter,
                    "types" => $types,
                ];
            }

            else if(in_array(RequestParam::class, $types) || in_array(OptRequestParam::class, $types))
            {
                $requestParams[$paramName] = [
                    "name" => $parameter->getName(),
                    "parameter" => $parameter,
                    "types" => $types,
                ];
            }
        }

        return [
            "requestHeaders" => $requestHeaders,
            "requestParams" => $requestParams,
        ];
    }

    /**
     * createPathVariableDefinitions
     */
    private function createPathVariableDefinitions(Route $route)
    {
        $pathVariableDefinitions = implode("\n", array_map(function($name) {
            return "        \$$name = null;";
        }, $route->getPathVariableNames()));

        if(strlen($pathVariableDefinitions) > 0)
            $pathVariableDefinitions = "        # Path Variables\n$pathVariableDefinitions\n\n";

        return $pathVariableDefinitions;
    }

    /**
     * createRequestParamDefinitions
     */
    private function createRequestParamDefinitions(array $requestParams)
    {
        $requestParamsDefinitions = [];
        
        foreach($requestParams as $paramName => $parameterData)
        {
            $parameter = $parameterData["parameter"];
            $types = $parameterData["types"];

            switch(true)
            {
                case in_array("string", $types):
                    $requestParamsDefinitions[] = "        \$$paramName = \"\";";
                break;
                case in_array("int", $types):
                case in_array("float", $types):
                case in_array("double", $types):
                case in_array("bool", $types):
                    $requestParamsDefinitions[] = "        \$$paramName = null;";
                break;
                case in_array("array", $types):
                    $requestParamsDefinitions[] = "        \$$paramName = [];";
                break;
            }
        }

        $requestParamsDefinitions = implode("\n", $requestParamsDefinitions);

        if(strlen($requestParamsDefinitions) > 0)
            $requestParamsDefinitions = "        # Request Params\n$requestParamsDefinitions\n\n";

        return $requestParamsDefinitions;
    }

    /**
     * createRequestHeaderDefinitions
     */
    private function createRequestHeaderDefinitions(array $requestHeaders)
    {
        $requestParamsDefinitions = [];
        
        foreach($requestHeaders as $paramName => $parameterData)
        {
            $parameter = $parameterData["parameter"];
            $types = $parameterData["types"];

            switch(true)
            {
                case ($paramName == "authorization") || ($paramName == "token"):
                    $requestParamsDefinitions[] = "        \$token = \"\";";
                break;
                case in_array("string", $types):
                    $requestParamsDefinitions[] = "        \$$paramName = \"\";";
                break;
                case in_array("int", $types):
                case in_array("float", $types):
                case in_array("double", $types):
                case in_array("bool", $types):
                    $requestParamsDefinitions[] = "        \$$paramName = null;";
                break;
                case in_array("array", $types):
                    $requestParamsDefinitions[] = "        \$$paramName = [];";
                break;
            }
        }

        // Set request params
        $requestParamsDefinitions = implode("\n", $requestParamsDefinitions);

        if(strlen($requestParamsDefinitions) > 0)
            $requestParamsDefinitions = "        # Header Params\n$requestParamsDefinitions\n\n";

        return $requestParamsDefinitions;
    }

    /**
     * createArrayContent
     */
    private function createArrayContent(string $fieldName, array $keyValueArray)
    {
        $dataContent = "";
        
        foreach($keyValueArray as $key => $value)
        {
            if(strlen($dataContent) == 0)
                $dataContent = ",\n            \"$fieldName\" => [";

            $dataContent.="\n                \"$key\" => $value,";
        }

        if(strlen($dataContent))
            $dataContent = "$dataContent\n            ]";

        return $dataContent;
    }

    /**
     * getRequestDataArrayDefinition
     */
    private function getRequestDataArrayDefinition(array $requestParams)
    {
        $keyValueArray = [];

        foreach($requestParams as $paramName => $paramData)
        {
            $keyValueArray[$paramName] = "\$$paramName";
        }

        return $this->createArrayContent("data", $keyValueArray);
    }

    /**
     * getRequestHeaderArrayDefinition
     */
    private function getRequestHeaderArrayDefinition(array $requestHeaders)
    {
        $keyValueArray = [];

        foreach($requestHeaders as $paramName => $paramData)
        {
            switch(true)
            {
                case ($paramName == "authorization") || ($paramName == "token"):
                    $keyValueArray["Authorization"] = "\"Bearer \$token\"";
                break;
                default:
                    $keyValueArray[$paramName] = "\$$paramName";
            }   
        }
        
        return $this->createArrayContent("headers", $keyValueArray);
    }

    /**
     * addAuthorizationHeaderIfRequiresAuthorizationIsSet
     */
    private function addAuthorizationHeaderIfRequiresAuthorizationIsSet(ReflectionMethod $method, &$requestHeaders)
    {
        // Check for auth attribute, if set, we include an auth token
        $authorizationAttributes = RouteParser::getReflectionMethodAttributeOfClass($method, RequiresAuthorization::class);

        // Merge with props that inherit from RequiresAuthorization::class
        $authorizationAttributesInherited = RouteParser::getReflectionMethodAttributeOfSubclass($method, RequiresAuthorization::class);

        // Merge
        $merged = array_merge($authorizationAttributes, $authorizationAttributesInherited);

        // Found authorization attributes
        if(count($merged))
        {
            if(!array_key_exists("authorization", $requestHeaders) && !array_key_exists("token", $requestHeaders))
            {
                $requestHeaders["token"] = [
                    "name" => "token",
                    "types" => ["string"]
                ];
            }
        }
    }

    /**
     * createHTTPRequest
     */
    private function createHTTPRequest(ReflectionMethod $method, Route $route, array $headers = [])
    {
        $methodParameterIndex = $this->createMethodParameterIndex($method);
        $requestHeaders = $methodParameterIndex["requestHeaders"];
        $requestParams = $methodParameterIndex["requestParams"];

        // Check for requires auth
        $this->addAuthorizationHeaderIfRequiresAuthorizationIsSet($method, $requestHeaders);

        // Create variable definitions
        $pathVariableDefinitions = $this->createPathVariableDefinitions($route);
        $requestParamsDefinitions = $this->createRequestParamDefinitions($requestParams);
        $requestHeadersDefinitions = $this->createRequestHeaderDefinitions($requestHeaders);

        // For request
        $requestDataArrayDefinition = $this->getRequestDataArrayDefinition($requestParams);
        $requestHeaderArrayDefinition = $this->getRequestHeaderArrayDefinition($requestHeaders);
        $requestMethod = strtolower($route->getRequestMethod());
        $methodName = $method->getName();
        $className = $method->getDeclaringClass()->getName();
        $getFullPathInlineVariables = $this->createGetFullPathInlineVariables($route);
        $uri = "RouteParser::getRoute(['$className', '$methodName'])->getFullPath(false$getFullPathInlineVariables)";

        // Return body
        return <<<PHP
$pathVariableDefinitions$requestParamsDefinitions$requestHeadersDefinitions        # Send Request\n        \$response = HTTPRequest::$requestMethod([
            "uri" => $uri$requestDataArrayDefinition$requestHeaderArrayDefinition
        ]);
PHP;
    }

    /**
     * createTestMethod
     */
    private function createTestMethod(ReflectionMethod $method, Route $route, ClassObject &$classObject)
    {
        $testFunctionName = "test" . ucfirst($method->getName());

        // Create method
        if($classObject->hasMethod($testFunctionName))
        {
            log_debug("Skipping, test method '$testFunctionName' already exists");
            return true;
        }

        // Parsing
        log_info("Creating test method: " . $testFunctionName);

        // Get returnFilter
        $returnFilter = RouteParser::getReflectionMethodAttributeOfSubclass($method, RouteAttribute::class, ReturnFilter::class);

        // Set return filter
        if($returnFilter !== false)
            $returnFilter = $returnFilter->newInstance();

        // Create component
        $testMethod = $this->createUnitTestMethod($testFunctionName);

        // Create content
        $httpRequestContent = $this->createHTTPRequest($method, $route);

        // Add body
        $testMethod->body = <<< EOD
$httpRequestContent\n
        # Test Result\n        \$this->assertTrue(\$response->isSuccessful(), !\$response->isSuccessful() ? (\$response?->getParameter("errorDescription") ?? \$response?->getParameter("response")) : "");
EOD;

        // Add
        $classObject->component->methods[$testMethod->name] = $testMethod;
    }

    /**
     * generateTests
     */
    public function generateTests(string $outputFolder)
    {
        if(strlen($outputFolder) == 0)
            throw new InvalidArgumentException("Argument 1 'outputFolder' is not set");

        foreach($this->getRouteControllerClasses() as $class)
        {
            log_info("Reading class: " . $class);

            $classObject = null; 

            $reflection = new ReflectionClass($class);

            foreach($this->getRouteControllerClassMethods($reflection) as $method)
            {
                $route = RouteParser::getReflectionMethodAttributeOfSubclass($method, Route::class);

                if(count($route) == 0)
                {
                    log_debugf("Route not defined in '%s', skipping", $method->getName());
                    continue;
                }

                // Create new route instance
                $route = reset($route);
                $route = $route->newInstance();

                // We create the object after we are sure there are routes in it.
                if($classObject == null)
                    $classObject = $this->getTestController($reflection, $outputFolder);

                // Parsing
                log_info("Reading method: " . $method->getName());
                
                // SetupBeforeClass
                if(!$classObject->hasMethod('setUpBeforeClass'))
                    $this->createSetupBeforeClassMethod($classObject);

                // Add SetUP
                if(!$classObject->hasMethod('setUp'))
                    $this->createSetUpMethod($classObject);

                // Create test method (checks if added inside function)
                $this->createTestMethod($method, $route, $classObject);
            } 
            
            // Output content in output folder
            if($classObject !== null)
            {
                file_put_contents($classObject->getFileName(), $classObject->toString());
            }
        }
    }

    /**
     * run
     */
    public static function run(null|Command $command = null)
    {
        $outputFolder = $command->getNextArg();

        if(!is_string($outputFolder))
            exit("usage: api create tests <outputFolder>");

        (new self())
        ->setVerbose($command instanceof Command ? $command->hasFlag("v") : null)
        ->setVerbose($command instanceof Command ? $command->hasFlag("d") : null)
        ->generateTests($outputFolder);
    }
}