<?php declare(strict_types=1);
include __DIR__ . '/../vendor/autoload.php';

use Ripple\Coroutine\Context;
use Ripple\RPC\Json\Server;

use function Co\wait;

$server = new Server();

// 绑定服务地址
$server->bind('http://0.0.0.0:8000/jsonrpc');

// 或者绑定WebSocket服务地址
$server->bind('ws://0.0.0.0:9000');

// 注册路由
$server->route('sum', static function (int $a, int $b) {
    return $a + $b;
});

// 支持callable数组
$server->route('createFromFormat', [DateTime::class, 'createFromFormat']);
$server->run();

// 添加中间件
$server->middleware(static function ($next) {
    // 通过上下文获取请求信息(仅在HTTP协议下有效)
    $request = Context::get('request');

    // 通过上下文获取连接信息(仅在WebSocket协议下有效)
    $connection = Context::get('connection');

    // 通过上下文获取请求JSON
    $requestJson = Context::get('requestJson');

    if (\rand(0, 1) === 1) {
        // 自定义错误处理
        //        throw new JsonException([
        //            'code'    => -32603,
        //            'message' => '服务器内部错误',
        //        ]);
    }

    return $next();
});

// 运行服务器
$server->run();

// 等待协程结束
wait();
