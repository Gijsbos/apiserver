<?php
declare(strict_types=1);

namespace gijsbos\ApiServer;

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
        public readonly array $allowedHeaders = ["Authorization", "Content-Type"],
        public readonly bool $allowCredentials = false,
        public readonly int $maxAgeSeconds = 86400,
    )
    { }

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
        $origin = RequestHeader::getHeader("origin");

        if($origin === null || !$this->isOriginAllowed($origin))
            return false;

        // Credentialed responses must echo the specific origin, "*" is not allowed by the spec
        $allowOrigin = $this->allowCredentials || !in_array("*", $this->allowedOrigins, true) ? $origin : "*";

        header("Access-Control-Allow-Origin: $allowOrigin");
        header("Vary: Origin");

        if($this->allowCredentials)
            header("Access-Control-Allow-Credentials: true");

        if($server->getRequestMethod() !== "OPTIONS")
            return false;

        header("Access-Control-Allow-Methods: " . implode(", ", $this->allowedMethods));
        header("Access-Control-Allow-Headers: " . implode(", ", $this->allowedHeaders));
        header("Access-Control-Max-Age: " . $this->maxAgeSeconds);

        http_response_code(204);

        return true;
    }
}
