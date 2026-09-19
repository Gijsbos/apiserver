<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use gijsbos\ApiServer\Classes\RequestParam;
use gijsbos\Http\Exceptions\HTTPRequestException;

/**
 * IsolatesGlobalState
 *  Server, SecurityContext, Cors and RequestHeader all read or write process-wide
 *  state ($_SERVER and static properties on Server). Tests that touch any of it
 *  use this trait so one test can never leak state into the next.
 */
trait IsolatesGlobalState
{
    private array $serverBackup = [];

    #[Before]
    public function isolateGlobalStateBeforeTest() : void
    {
        $this->serverBackup = $_SERVER;

        // Drop request headers left behind by earlier simulated requests
        foreach(array_keys($_SERVER) as $key)
            if(str_starts_with((string) $key, "HTTP_") || str_starts_with((string) $key, "REDIRECT_"))
                unset($_SERVER[$key]);

        $this->resetServerStatics();
    }

    #[After]
    public function restoreIsolatedGlobalState() : void
    {
        $_SERVER = $this->serverBackup;

        $this->resetServerStatics();
    }

    private function resetServerStatics() : void
    {
        Server::$securityContext = null;
        Server::$securityContextAfterRouteResolve = null;
        Server::$cors = null;
        Server::$beforeRequestHandlers = [];
        Server::$onRouteNotFoundHandler = null;
        Server::$responseHandler = null;
        Server::$exceptionHandlers = [];

        RequestParam::$contentType = null;
        RequestParam::$requestData = null;
    }

    /**
     * assertHttpError
     *  Asserts $fn throws the given HTTPRequestException subclass carrying the given error code.
     */
    protected function assertHttpError(string $exceptionClass, string $error, callable $fn) : HTTPRequestException
    {
        try
        {
            $fn();
        }
        catch(HTTPRequestException $e)
        {
            $this->assertInstanceOf($exceptionClass, $e);
            $this->assertSame($error, $e->getError());

            return $e;
        }

        $this->fail("Expected $exceptionClass with error '$error' to be thrown, nothing was thrown");
    }

    /**
     * dispatch
     *  Runs a simulated request through Server::listen() and captures what it prints.
     *
     * @return array{result: mixed, body: string, json: mixed, status: int|false, server: Server}
     */
    protected function dispatch(string $method, string $uri, array $headers = [], array $serverOpts = []) : array
    {
        Server::simulateRequest($method, $uri, [], $headers);

        $server = new Server($serverOpts);

        // phpunit.xml sets LOG_OUTPUT=console, which would print log_error() output into the
        // captured response (right after the JSON, without a newline). Log to file as in production.
        $previousLogOutput = getenv("LOG_OUTPUT");
        putenv("LOG_OUTPUT=file");

        ob_start();

        try
        {
            http_response_code(200);

            $result = $server->listen(false);
        }
        finally
        {
            $body = (string) ob_get_clean();

            $previousLogOutput === false ? putenv("LOG_OUTPUT") : putenv("LOG_OUTPUT=$previousLogOutput");
        }

        return [
            "result" => $result,
            "body" => $body,
            "json" => json_decode($body, true),
            "status" => http_response_code(),
            "server" => $server,
        ];
    }
}
