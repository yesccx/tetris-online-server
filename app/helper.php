<?php
/*
|--------------------------------------------------------------------------
| Common function method
|--------------------------------------------------------------------------
 */

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Redis\Redis;
use Hyperf\Utils\ApplicationContext;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * 容器实例
 *
 * @return ContainerInterface
 */
function di(): ContainerInterface
{
    return ApplicationContext::getContainer();
}

/**
 * Redis 客户端实例
 *
 * @return Redis|mixed
 */
function redis()
{
    return di()->get(Redis::class);
}

/**
 * 缓存实例 简单的缓存
 *
 * @return mixed|\Psr\SimpleCache\CacheInterface
 */
function cache()
{
    return di()->get(Psr\SimpleCache\CacheInterface::class);
}

/**
 * Dispatch an event and call the listeners.
 *
 * @return mixed|\Psr\EventDispatcher\EventDispatcherInterface
 */
function event()
{
    return di()->get(Psr\EventDispatcher\EventDispatcherInterface::class);
}

/**
 * 控制台日志
 *
 * @return StdoutLoggerInterface|mixed
 */
function stdout_log()
{
    return di()->get(StdoutLoggerInterface::class);
}

/**
 * 文件日志
 *
 * @param string $name
 * @return LoggerInterface
 */
function logger(string $name = 'APP'): LoggerInterface
{
    return di()->get(LoggerFactory::class)->get($name);
}

/**
 * Http 请求实例
 *
 * @return mixed|ServerRequestInterface
 */
function request()
{
    return di()->get(ServerRequestInterface::class);
}

/**
 * 请求响应
 *
 * @return ResponseInterface|mixed
 */
function response()
{
    return di()->get(ResponseInterface::class);
}

/**
 * 判断0或正整数
 *
 * @param string|int $value  验证字符串
 * @param bool       $isZero 判断是否可为0
 * @return bool
 */
function check_int($value, $isZero = false): bool
{
    $reg = $isZero ? '/^[+]{0,1}(\d+)$/' : '/^[1-9]\d*$/';
    return is_numeric($value) && preg_match($reg, $value);
}

/**
 * 解析英文逗号',' 拼接的 ID 字符串
 *
 * @param string|int $ids 字符串(例如; "1,2,3,4,5,6")
 * @return array
 */
function parse_ids($ids): array
{
    return array_unique(explode(',', trim($ids)));
}

/**
 * 数据压缩
 *
 * @param mixed $rawData
 * @param int $level 压缩级别
 * @return string|mixed
 */
function dataCompress($rawData, int $level = 9)
{
    try {
        return base64_encode(gzcompress(json_encode($rawData), $level));
    } catch (Throwable $e) {
        return $rawData;
    }
}

/**
 * 数据解压缩
 *
 * @param string $rawData
 * @return mixed
 */
function dataUncompress(string $data)
{
    try {
        return json_decode(gzuncompress(base64_decode($data)), true);
    } catch (Throwable $e) {
        return $data;
    }
}
