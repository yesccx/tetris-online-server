<?php

declare (strict_types = 1);

namespace App\Cache;

use App\Cache\Repository\StringRedis;

/**
 * 游戏房间号
 */
class GameRoomNumber extends StringRedis
{
    /**
     * 获取下一个房间号
     *
     * @return string
     */
    public function nextRoomNumber()
    {
        $this->incr('room');
        return (string) $this->get('room', 1);
    }

    /**
     * 初始化清理
     *
     * @return void
     */
    public function initClear()
    {
        $this->delete('room');
    }
}
