<?php declare(strict_types=1);
include __DIR__ . '/../vendor/autoload.php';

use Ripple\RPC\Json\Client;

$result = Client::call('http://127.0.0.1:8000/jsonrpc', 'sum', [1, 2]);
echo $result, \PHP_EOL; // 3


$json = Client::request('http://127.0.0.1:8000/jsonrpc', 'sum', [1, 2], 'id');
echo $json, \PHP_EOL; // {"jsonrpc":"2.0","result":3,"id":"id"}
