<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Ripple\RPC\Json\Client;
use Ripple\RPC\Json\Server;
use Ripple\Utils\Output;

use function Co\async;
use function Co\cancelAll;
use function Co\wait;

/**
 *
 */
class ServerTest extends TestCase
{
    /**
     * This method is called before each test.
     *
     * @codeCoverageIgnore
     */
    public function testMain(): void
    {
        $server = new Server();
        $server->route('test', function ($params) {
            return $params;
        });
        $server->bind('http://127.0.0.1:8000/jsonrpc');
        $server->run();
        async(function () {
            $this->_testSuccessfulRequest();
            $this->_testParseError();
            $this->_testInvalidParams();
        })->except(static function (Throwable $exception) {
            Output::exception($exception);
        })->finally(static function () {
            cancelAll();
        });

        wait();
    }

    /**
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Throwable
     */
    public function _testSuccessfulRequest(): void
    {
        $response = Client::call('http://127.0.0.1:8000/jsonrpc', 'test', ['test']);
        $this->assertEquals('test', $response);
    }

    /**
     * @return void
     */
    public function _testParseError(): void
    {
        try {
            Client::call('http://127.0.0.1:8000/jsonrpc', 'parseError');
        } catch (Throwable) {
            $this->assertTrue(true);
        }
    }

    /**
     * @return void
     */
    public function _testInvalidParams(): void
    {
        try {
            Client::call('http://127.0.0.1:8000/jsonrpc', 'invalidParams');
        } catch (Throwable) {
            $this->assertTrue(true);
        }
    }
}
