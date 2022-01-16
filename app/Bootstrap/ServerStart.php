<?php

declare (strict_types = 1);

namespace App\Bootstrap;

use App\Cache\GameRoom;
use App\Cache\GameRoomMember;
use App\Cache\GameRoomNumber;
use App\Cache\SocketMember;
use Hyperf\Framework\Bootstrap\ServerStartCallback;

/**
 * 自定义服务启动前回调事件
 *
 * @package App\Bootstrap
 */
class ServerStart extends ServerStartCallback
{
    public function onBeforeStart()
    {
        // 初始化清理历史fd关系
        SocketMember::make()->initClear();
        GameRoomNumber::make()->initClear();
        GameRoomMember::make()->initClear();
        GameRoom::make()->initClear();
        stdout_log()->info('清理FD成功.......');
    }
}
