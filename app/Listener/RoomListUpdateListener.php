<?php

declare (strict_types = 1);

namespace App\Listener;

use App\Event\RoomListUpdateEvent;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Utils\Context;

/**
 * @Listener
 */
class RoomListUpdateListener implements ListenerInterface
{

    public function listen(): array
    {
        return [
            RoomListUpdateEvent::class,
        ];
    }

    public function process(object $event)
    {
        if (!Context::get('room-list-update')) {
            Context::set('room-list-update', true);
            defer(function () {
                di()->get(\Hyperf\SocketIOServer\SocketIO::class)->of('/game')->emit('room-list-update');
            });
        }
    }
}
