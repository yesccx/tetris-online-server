<?php
declare (strict_types = 1);

namespace App\Cache\Repository;

use App\Cache\Contracts\StringRedisInterface;

/**
 * String Cache
 *
 * @package App\Cache\Repository
 */
class StringRedis extends AbstractRedis implements StringRedisInterface
{
    protected $prefix = 'rds-string';

    protected $name = '';

    /**
     * 设置缓存
     *
     * @param string $key     缓存标识
     * @param string $value   缓存数据
     * @param null   $expires 过期时间
     * @return bool
     */
    public function set(string $key, string $value, $expires = null): bool
    {
        return $this->redis()->set($this->getCacheKey($key), $value, $expires);
    }

    /**
     * 获取缓存数据
     *
     * @param string $key 缓存标识
     * @param mixed $default 默认值
     * @return bool|mixed|string
     */
    public function get(string $key, $default = null)
    {
        $value = $this->redis()->get($this->getCacheKey($key));
        return $value === false ? $default : $value;
    }

    /**
     * incr
     *
     * @param string $key 缓存标识
     * @return bool
     */
    public function incr(string $key)
    {
        return $this->redis()->incr($this->getCacheKey($key));
    }

    /**
     * decr
     *
     * @param string $key 缓存标识
     * @return bool
     */
    public function decr(string $key)
    {
        return $this->redis()->decr($this->getCacheKey($key));
    }

    /**
     * 删除 String 缓存
     *
     * @param string $key 缓存标识
     * @return bool
     */
    public function delete(string $key): bool
    {
        return (bool) $this->redis()->del($this->getCacheKey($key));
    }

    /**
     * 判断缓存是否存在
     *
     * @param string $key 缓存标识
     * @return bool
     */
    public function isExist(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * 获取缓存过期时间
     *
     * @param string $key 缓存标识
     * @return bool|int
     */
    public function ttl(string $key)
    {
        return $this->redis()->ttl($this->getCacheKey($key));
    }
}
