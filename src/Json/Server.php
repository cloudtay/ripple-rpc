<?php declare(strict_types=1);

namespace Ripple\RPC\Json;

use ArgumentCountError;
use Closure;
use InvalidArgumentException;
use Ripple\Coroutine\Context;
use Ripple\Http\Server\Request;
use Ripple\RPC\Json\Exception\JsonException;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\WebSocket\Server\Connection;
use Throwable;
use TypeError;

use function array_reverse;
use function call_user_func;
use function Co\async;
use function count;
use function is_array;
use function json_decode;
use function json_encode;
use function method_exists;
use function parse_url;
use function strtoupper;

/**
 *
 */
class Server
{
    public const VERSION = '2.0';

    /*** @var bool */
    public bool $debug = false;

    /*** @var string */
    protected string $httpPath = '/';

    /*** @var array */
    protected array $routes = [];

    /*** @var array */
    protected array $middlewares = [];

    /*** @var \Closure[]] */
    protected array $runServer = [];

    /**
     * @param string        $method
     * @param array|Closure $callback
     *
     * @return void
     */
    public function route(string $method, array|Closure $callback): void
    {
        if (is_array($callback)) {
            if (count($callback) !== 2 || !method_exists($callback[0], $callback[1])) {
                throw new InvalidArgumentException('Invalid callback');
            }
        }

        $this->routes[$method] = $callback;
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function middleware(Closure $closure): void
    {
        $this->middlewares[] = $closure;
    }

    /**
     * @param string     $address
     * @param mixed|null $context
     *
     * @return bool
     */
    public function bind(string $address, mixed $context = null): bool
    {
        $addressInfo = parse_url($address);
        if (!$scheme = $addressInfo['scheme'] ?? null) {
            return false;
        }

        try {
            $server = match ($scheme) {
                'http', 'https' => new \Ripple\Http\Server($address, $context),
                'ws', 'wss'     => new \Ripple\WebSocket\Server($address, $context),
            };
        } catch (ConnectionException) {
            return false;
        }

        if ($server instanceof \Ripple\WebSocket\Server) {
            $server->onConnect = function (Connection $connection) {
                $connection->onMessage = function (string $message, Connection $connection) {
                    async(function () use ($connection, $message) {
                        $this->onWebSocketRequest($connection, $message);
                    });
                };
            };
            $this->runServer[] = fn () => $server->listen();
        } elseif ($server instanceof \Ripple\Http\Server) {
            $this->httpPath    = $addressInfo['path'] ?? '/';
            $server->onRequest = function (Request $request) {
                async(function () use ($request) {
                    $this->onRequest($request);
                });
            };
            $this->runServer[] = fn () => $server->listen();
        } else {
            return false;
        }
        return true;
    }

    /**
     * @param \Ripple\WebSocket\Server\Connection $connection
     * @param string                              $message
     *
     * @return void
     */
    protected function onWebSocketRequest(Connection $connection, string $message): void
    {
        if (!$requestJson = json_decode($message, true)) {
            $connection->send(json_encode($this->json(-32700, 'Parse error')));
            return;
        }

        Context::define('connection', $connection);
        Context::define('requestJson', $requestJson);
        $connection->send(json_encode($this->distributeRequestJson($requestJson)));
    }

    /**
     * @param int|null    $errorCode
     * @param string|null $message
     * @param array       $data
     * @param mixed|null  $id
     *
     * @return array
     */
    protected function json(
        ?int    $errorCode,
        ?string $message,
        mixed   $data = [],
        mixed   $id = null
    ): array {
        $response = ['jsonrpc' => Server::VERSION, 'id' => $id];
        if ($errorCode !== null) {
            $response['error'] = ['code' => $errorCode, 'message' => $message] + $data;
        } else {
            $response['result'] = $data;
        }
        return $response;
    }

    /**
     * @param array $requestJson
     *
     * @return array
     */
    protected function distributeRequestJson(array $requestJson): array
    {
        if (($requestJson['jsonrpc'] ?? null) !== Server::VERSION || !isset($requestJson['method'])) {
            return ($this->json(-32600, 'Invalid Request'));
        }

        $requestJsonParams = $requestJson['params'] ?? [];
        $requestJsonID     = $requestJson['id'] ?? null;

        try {
            $result = $this->distributeRoute(
                $requestJson['method'],
                $requestJsonParams
            );

            return ($this->json(
                null,
                null,
                $result,
                $requestJsonID
            ));
        } catch (JsonException $jsonException) {
            return ($jsonException->getData());
        } catch (ArgumentCountError $argumentCountError) {
            return ($this->json(
                -32602,
                'Invalid params',
                $this->debug ? [
                    'debug' => $argumentCountError->getMessage(),
                ] : [],
                $requestJsonID
            ));
        } catch (TypeError $typeError) {
            return ($this->json(
                -32602,
                'Invalid params',
                $this->debug ? [
                    'debug' => $typeError->getMessage(),
                ] : [],
                $requestJsonID
            ));
        } catch (Throwable $exception) {
            return ($this->json(
                -32603,
                'Internal error',
                $this->debug ? [
                    'debug' => $exception->getMessage(),
                ] : [],
                $requestJsonID
            ));
        }
    }

    /**
     * @param string $method
     * @param array  $params
     *
     * @return mixed
     * @throws \Ripple\RPC\Json\Exception\JsonException
     */
    protected function distributeRoute(string $method, array $params): mixed
    {
        $callback = $this->routes[$method] ?? null;
        if (!$callback) {
            throw new JsonException([
                'code'    => -32601,
                'message' => 'Method not found'
            ]);
        }

        $next = $callback instanceof Closure
            ? static fn () => call_user_func($callback, ...$params)
            : static fn () => call_user_func([$callback[0], $callback[1]], ...$params);

        return $this->handleMiddleware($next);
    }

    /**
     * @param Closure $next
     *
     * @return mixed
     */
    protected function handleMiddleware(Closure $next): mixed
    {
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = static fn () => $middleware($next);
        }
        return $next();
    }

    /**
     * @param \Ripple\Http\Server\Request $request
     *
     * @return void
     */
    protected function onRequest(Request $request): void
    {
        $uri        = $request->SERVER['REQUEST_URI'] ?? '/';
        $httpMethod = $request->SERVER['REQUEST_METHOD'] ?? 'GET';
        if (strtoupper($httpMethod) !== 'POST' || $uri !== $this->httpPath) {
            $request->respondJson(
                $this->json(-32601, 'Method not found'),
                [],
                404
            );
            return;
        }

        if (!$requestJson = json_decode($request->CONTENT, true)) {
            $request->respondJson($this->json(-32700, 'Parse error'));
            return;
        }

        Context::define('request', $request);
        Context::define('requestJson', $requestJson);
        $request->respondJson($this->distributeRequestJson($requestJson));
    }

    /**
     * @return bool
     */
    public function run(): bool
    {
        foreach ($this->runServer as $runServer) {
            call_user_func($runServer);
        }
        return true;
    }
}
