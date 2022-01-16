<?php

declare (strict_types = 1);

namespace App\Cache;

use App\Cache\Repository\HashRedis;
use App\Event\OnlineMemberUpdateEvent;

/**
 * 在线用户
 */
class OnlineMember extends HashRedis
{
    /**
     * 用户登录
     *
     * @param string $fd fd
     * @param string $username 用户名
     * @return void
     */
    public function login(string $fd, string $username)
    {
        $this->add($username, $fd);
        event()->dispatch(new OnlineMemberUpdateEvent($this->getCount()));
    }

    /**
     * 用户退出登录
     *
     * @param string $username 用户名
     * @return void
     */
    public function logout(string $username)
    {
        $this->rem($username);
        event()->dispatch(new OnlineMemberUpdateEvent($this->getCount()));
    }

    /**
     * 判断用户名是否存在
     *
     * @param string $username
     * @return bool
     */
    public function has(string $username)
    {
        return !empty($this->isMember($username));
    }

    /**
     * 获取用户fd
     *
     * @param string $username
     * @return string
     */
    public function getFd(string $username)
    {
        return $this->get($username);
    }

    /**
     * 初始化清理
     *
     * @return void
     */
    public function initClear()
    {
        $this->delete();
    }

    /**
     * 在线人数
     *
     * @return int
     */
    public function getCount() {
        return count($this->all());
    }
}
