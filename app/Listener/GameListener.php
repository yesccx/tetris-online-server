<?php

declare (strict_types = 1);

namespace App\Listener;

use App\Event\UserRegistered;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeServerStart;

/**
 * @Listener
 */
class GameListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            BeforeServerStart::class,
        ];
    }

    /**
     * @param UserRegistered $event
     */
    public function process(object $event)
    {
    }
}
