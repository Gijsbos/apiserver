# API Server

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Build Status](https://img.shields.io/github/actions/workflow/status/gijsbos/apiserver/ci.yml?branch=main)](https://github.com/gijsbos/apiserver/actions)
[![Issues](https://img.shields.io/github/issues/gijsbos/apiserver)](https://github.com/gijsbos/apiserver/issues)
[![Last Commit](https://img.shields.io/github/last-commit/gijsbos/apiserver)](https://github.com/gijsbos/apiserver/commits/main)

---

## Introduction

Lightweight, attribute-driven PHP API Server that simplifies route definition, request validation, and response filtering. 
It aims to make building structured REST APIs **clean, fast, and type-safe**, leveraging PHP 8+ attributes.

---

## Requirements

Before running the application, make sure you have the following installed:

- **Web Server**: Apache or Nginx  
- **PHP**: `>= 8.2`  
- **Composer**: for dependency management  

## Installation

```
composer require gijsbos/apiserver
```

## Setup

### Controllers
Extend your controllers with the `RouteController` class and create a new route by defining attributes:  
- Route(`method`, `path`) or short GetRoute, PostRoute, PutRoute, DeleteRoute, PatchRoute, OptionsRoute.  
- ReturnFilter(`array`) - assoc array containing keys to filter out.  
- RequiresAuthorization() - extends the ExecuteBeforeRoute, provides simple token checking and extraction.  

### Route Parameters
Route parameters allow you to inject parameters into your controller method.  
- `PathVariable` - Extracts parameters from path that have been defined using curly brackets.  
- `RequestParam` - Extracts parameters from global variables.  
- `RequestHeader` - Extracts headers from server headers.  

Parameter behaviour can be controlled by both defining union types and optional parameters.  

- `RequestParam|string $id` - Expects a string value, when not defined defaults to `empty string`.  
- `RequestParam|int $id` - Expects an `int` value, cast to `int` or defaults to `empty string`.  
- `RequestParam|float $id` - Expects an `float` value, cast to `float` or defaults to `empty string`.  
- `RequestParam|double $id` - Expects an `int` value, cast to `double` or defaults to `empty string`.  
- `RequestParam|bool $id` - Expects an `int` value, cast to `bool` or defaults to `empty string`.  

Allowing `null` values requires you to add the null union type:  
- `RequestParam|string|null $id` - Expects a string value, when not defined defaults to `null`.  

Route parameter options can be defined by defining the object inside the parameter definition:

```
PathVariable|string $id = new PathVariable(["min" => 0, "max" => 999, "required" => true, "pattern" => "/\d+/", "default" => 1])
```

The following options can be used:  
- min *int* - minimum value for `int` values, minimum length for `string` values.  
- max *int* - maximum value for `int` values, maximum length for `string` values.  
- required *bool* - when true, throws missing error when parameter is not defined (has no effect on PathVariable).  
- pattern *string* - regexp pattern uses to check the value.  
- default *mixed* - fallback value when value is empty.  
- values *array* - permitted values or throws error.  

### Example

```
class UserController extends RouteController
{
    #[GetRoute('/user/{id}/')]
    #[ReturnFilter(['name','id'])]
    public function getUser(
        PathVariable|string $id = new PathVariable(["min" => 0, "max" => 999, "required" => false]),
        RequestParam|string $name = new RequestParam(["min" => 0, "max" => 10, "pattern" => "/^[a-z]+$/", "required" => false, "default" => "john"]),
    )
    {
        return [
            "id" => "<$id>",
            "name" => $name,
        ];
    }
}
```

### Server Definition
```
try
{
    $server = new Server([
        "pathPrefix" => "apiserver/",   // For nested paths
        "addServerTime" => true,        // Adds server time
        "addRequestTime" => true,       // Adds request time
    ]);

    $server->listen();
}
catch(RuntimeException | Exception | TypeError | Throwable $ex)
{
    print($ex->getMessage());
}
```