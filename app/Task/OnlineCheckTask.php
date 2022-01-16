<?php

declare (strict_types = 1);

namespace App\Task;

use Hyperf\Crontab\Annotation\Crontab;

/**
 * @Crontab(
 *      name="online-check",
 *      rule="*\/5 * * * * *",
 *      callback="execute",
 *      memo="在线人数检测"
 * )
 */
class OnlineCheckTask
{
    public function execute()
    {
    }
}
