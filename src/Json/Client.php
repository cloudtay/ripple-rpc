<?php declare(strict_types=1);

namespace Ripple\RPC\Json;

use Exception;
use Ripple\Coroutine\Coroutine;
use Ripple\Http\Guzzle;
use Throwable;

use function Co\getSuspension;
use function in_array;
use function json_decode;
use function json_encode;
use function parse_url;

/**
 *
 */
class Client
{
    /**
     * @param string      $address
     * @param string      $method
     * @param array       $params
     * @param string|null $id
     *
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException|Exception|Throwable
     */
    public static function call(string $address, string $method, array $params = [], string $id = null): mixed
    {
        $response = Client::request($address, $method, $params, $id);
        if (!$responseJson = json_decode($response, true)) {
            throw new Exception('Invalid response');
        }

        if (isset($responseJson['error']) || !isset($responseJson['result'])) {
            throw new Exception($responseJson['error']['message'] ?? 'Unknown error');
        }

        return $responseJson['result'];
    }

    /**
     * @param string      $address
     * @param string      $method
     * @param array       $params
     * @param string|null $id
     *
     * @return string
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws Throwable
     */
    public static function request(string $address, string $method, array $params = [], string $id = null): string
    {
        $addressInfo = parse_url($address);
        if (!$scheme = $addressInfo['scheme'] ?? null) {
            throw new Exception('Address format error');
        }

        if (in_array($scheme, ['http', 'https'])) {
            $client   = Guzzle::newClient();
            $response = $client->post($address, [
                'json'    => [
                    'jsonrpc' => Server::VERSION,
                    'method'  => $method,
                    'params'  => $params,
                    'id'      => $id,
                ],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            return $response->getBody()->getContents();
        } elseif (in_array($scheme, ['ws', 'wss'])) {
            $client         = new \Ripple\WebSocket\Client\Client($address);
            $client->onOpen = function (\Ripple\WebSocket\Client\Client $client) use ($method, $params, $id) {
                $client->send(json_encode([
                    'jsonrpc' => Server::VERSION,
                    'method'  => $method,
                    'params'  => $params,
                    'id'      => $id,
                ]));
            };

            $suspension        = getSuspension();
            $client->onMessage = function (string $message, \Ripple\WebSocket\Client\Client $client) use ($suspension) {
                Coroutine::resume($suspension, $message);
                $client->close();
            };
            return Coroutine::suspend();
        }

        throw new Exception('Invalid scheme');
    }
}
