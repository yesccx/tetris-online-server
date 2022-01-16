<?php

declare (strict_types = 1);

namespace App\Event;

/**
 * 在线人数变更
 */
class OnlineMemberUpdateEvent
{
    /**
     * 在线人数
     *
     * @var int
     */
    public $count = 0;

    public function __construct(int $count)
    {
        $this->count = $count;
    }
}
