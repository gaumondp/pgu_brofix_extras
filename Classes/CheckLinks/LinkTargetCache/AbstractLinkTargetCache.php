<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetCache;

abstract class AbstractLinkTargetCache implements LinkTargetCacheInterface
{
    protected int $expire = 0; // Default expire time in seconds, 0 means cache forever unless overridden

    public function setExpire(int $expire): void
    {
        $this->expire = $expire;
    }
}
