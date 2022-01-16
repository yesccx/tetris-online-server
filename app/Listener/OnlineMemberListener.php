<?php

declare (strict_types = 1);

namespace App\Listener;

use App\Event\OnlineMemberUpdateEvent;
use App\Event\UserRegistered;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;

/**
 * @Listener
 */
class OnlineMemberListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            OnlineMemberUpdateEvent::class,
        ];
    }

    /**
     * @param UserRegistered $event
     */
    public function process(object $event)
    {
        defer(function () use ($event) {
            di()->get(\Hyperf\SocketIOServer\SocketIO::class)->of('/game')->emit('online-update', $event->count);
        });
    }
}
