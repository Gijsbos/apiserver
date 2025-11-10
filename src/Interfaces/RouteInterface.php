<?php
declare(strict_types=1);

namespace gijsbos\ApiServer\Interfaces;

interface RouteInterface
{
    public function getRequestMethod(): string;
    public function getRequestURI() : null|string;
    public function setRequestURI(string $requestURI) : void;
    public function getClassName() : null|string;
    public function getMethodName() : null|string;
    public function getPath(): string;
    public function getStatus(): int;
    public function getPathPattern() : string;
    public function getPathVariableNames(): array;
    public function getPathVariables();
    public function setPathVariables(array $pathVariables): void;
    public function setAttributes(array $attributes) : void;
    public function hasAttribute(string $name): bool;
    public function getAttributes(?string $name = null);
    public function addRouteParam(RouteParamInterface $routeParam): void;
    public function getRouteParams(): array;
}