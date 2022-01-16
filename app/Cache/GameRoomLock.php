<?php

namespace App\Cache;

use App\Cache\Repository\StringRedis;

class GameRoomLock extends StringRedis
{
    /**
     * 判断是否在间隔内重复操作创建
     *
     * @param string $username
     * @param string $s
     * @return void
     */
    public function limitCreate(string $username, int $s = 3)
    {
        if ($this->get('create:' . $username)) {
            return true;
        }

        $this->set('create:' . $username, 1, $s);
        return false;
    }

    /**
     * 判断是否在间隔内重复操作加入
     *
     * @param string $username
     * @param string $s
     * @return void
     */
    public function limitJoin(string $username, int $s = 3)
    {
        if ($this->get('join:' . $username)) {
            return true;
        }

        $this->set('join:' . $username, 1, $s);
        return false;
    }
}
