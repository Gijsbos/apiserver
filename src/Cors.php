<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

use InvalidArgumentException;
use gijsbos\ApiServer\Classes\RequestHeader;

/**
 * Cors
 *  Cross-Origin Resource Sharing.
 *
 *  Server::$cors = new Cors(
 *      allowedOrigins: ["https://app.example.com"],
 *      allowCredentials: true,
 *  );
 */
final class Cors
{
    public function __construct(
        public readonly array $allowedOrigins = [],
        public readonly array $allowedMethods = ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
        public readonly array $allowedHeaders = ["Authorization", "Content-Type", "traceparent", "X-Correlation-ID"],
        public readonly bool $allowCredentials = false,
        public readonly int $maxAgeSeconds = 86400,
        public readonly array $exposedHeaders = ["X-Request-Id", "X-Correlation-ID"],
    )
    {
        // Credentialed requests from any origin: every website could call the API with the cookies of the user
        if($allowCredentials && in_array("*", $allowedOrigins, true))
            throw new InvalidArgumentException("Cors: allowCredentials cannot be combined with allowed origin \"*\", list the origins");
    }

    private function isOriginAllowed(string $origin) : bool
    {
        return in_array("*", $this->allowedOrigins, true) || in_array($origin, $this->allowedOrigins, true);
    }

    /**
     * handle
     *  Called by Server before route resolution. Adds CORS headers for an
     *  allowed origin. Returns true on a handled preflight OPTIONS request
     *  (204, no body) - the caller must stop processing the request when it
     *  does, since this never calls exit() itself (worker/fiber safety: exit()
     *  would kill the whole process, not just this request).
     */
    public function handle(Server $server) : bool
    {
        // The response differs per origin (with or without CORS headers), caches must key on it: also for other origins
        header("Vary: Origin");

        $origin = RequestHeader::getHeader("origin");

        if($origin === null || !$this->isOriginAllowed($origin))
            return false;

        // Only listed origins are echoed, "*" without credentials is answered with "*"
        $allowOrigin = in_array("*", $this->allowedOrigins, true) ? "*" : $origin;

        header("Access-Control-Allow-Origin: $allowOrigin");

        if($this->allowCredentials)
            header("Access-Control-Allow-Credentials: true");

        if($server->getRequestMethod() !== "OPTIONS")
        {
            // Response headers JavaScript of the origin may read
            if(count($this->exposedHeaders) > 0)
                header("Access-Control-Expose-Headers: " . implode(", ", $this->exposedHeaders));

            return false;
        }

        header("Access-Control-Allow-Methods: " . implode(", ", $this->allowedMethods));
        header("Access-Control-Allow-Headers: " . implode(", ", $this->allowedHeaders));
        header("Access-Control-Max-Age: " . $this->maxAgeSeconds);

        http_response_code(204);

        return true;
    }
}
