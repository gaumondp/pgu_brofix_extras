<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetCache;

use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;

interface LinkTargetCacheInterface
{
    public function setExpire(int $expire): void;

    /**
     * Check if url exists in link cache (and is not expired)
     */
    public function hasEntryForUrl(string $linkTarget, string $linkType, bool $useExpire = true, int $expire = 0): bool;

    /**
     * Get result of link check
     *
     * @param string $linkTarget
     * @param string $linkType
     * @param int $expire (optional, default is 0, in that case uses $this->expire)
     * @return LinkTargetResponse|null
     */
    public function getUrlResponseForUrl(string $linkTarget, string $linkType, int $expire = 0): ?LinkTargetResponse;

    /**
     * @param string $linkTarget
     * @param string $linkType
     * @param LinkTargetResponse $linkTargetResponse
     */
    public function setResult(string $linkTarget, string $linkType, LinkTargetResponse $linkTargetResponse): void;

    public function remove(string $linkTarget, string $linkType): void;
}
