<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Interfaces;

use gijsbos\ApiServer\Server;
use ReflectionMethod;

interface RouteInterface
{
    public function getRequestMethod(): string;
    public function getPath(): string;
    public function getStatusCode(): int;
    public function setStatusCode(int $statusCode);
    public function getPathPattern() : string;
    public function getPathVariableNames(): array;
    public function getPathVariables();
    public function setPathVariables(array $pathVariables): void;
    public function setAttributes(array $attributes) : void;
    public function getAttributes(?string $name = null);
    public function hasAttribute(string $name): bool;
    public function addRouteParam(RouteParamInterface $routeParam): void;
    public function getRouteParams(): array;
    public function setData(array $data);
    public function addData(string $key, $value);
    public function hasData(?string $key = null);
    public function getData(?string $key = null);
    public function getServer() : null | Server;
    public function setServer(Server $server) : void;
    public function getRequestURI() : null|string;
    public function setRequestURI(string $requestURI) : void;
    public function getClassName() : null|string;
    public function setClassName(string $className) : void;
    public function getMethodName() : null|string;
    public function setMethodName(string $methodName) : void;
    public function getReflectionClassMethod() : ReflectionMethod;
    public function executeBeforeRouteMethods() : array;
    public function parsePathData(array $params = []) : array;
}