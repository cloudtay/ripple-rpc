## 简介

本项目是一个基于`ripple`引擎实现的PHP服务器,支持`HTTP`和`WebSocket`通信方式

### 特性

* 支持`JSON-RPC 2.0`
* 同时支持 `HTTP` 与 `WebSocket` 协议
* 可自定义路由
* 支持中间件
* 错误处理与异常捕获

## 快速开始

### 安装

```bash
composer require cloudtay/ripple-rpc
```

### 创建服务器实例

```php
<?php declare(strict_types=1);
include 'vendor/autoload.php';

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
```

### 客户端调用

```php
<?php declare(strict_types=1);
include 'vendor/autoload.php';

use Ripple\RPC\Json\Client;

$result = Client::call('http://127.0.0.1:8000/jsonrpc', 'sum', [1, 2]);
echo $result, \PHP_EOL; // 3


$json = Client::request('http://127.0.0.1:8000/jsonrpc', 'sum', [1, 2], 'id');
echo $json, \PHP_EOL; // {"jsonrpc":"2.0","result":3,"id":"id"}
```

### 附 `JSON-RPC2.0API` 规范

#### 请求格式

```json
{
  "jsonrpc": "2.0",
  "method": "sum",
  "params": [
    2,
    3
  ],
  "id": 1
}
```

#### 响应格式

```json
{
  "jsonrpc": "2.0",
  "result": 5,
  "id": 1
}
```

#### 错误响应示例

```json
{
  "jsonrpc": "2.0",
  "error": {
    "code": -32601,
    "message": "Method not found"
  },
  "id": 1
}
```

### 错误代码表

| 错误码              | 错误信息             | 描述      |
|------------------|------------------|---------|
| -32700           | Parse error      | 无效的JSON |
| -32600           | Invalid Request  | 无效的请求   |
| -32601           | Method not found | 方法不存在   |
| -32602           | Invalid params   | 无效的参数   |
| -32603           | Internal error   | 内部错误    |
| -32000 to -32099 | Server error     | 服务器错误   |

## 许可证

本项目基于MIT许可证发布

