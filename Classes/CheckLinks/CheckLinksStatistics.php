<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks;

use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;

class CheckLinksStatistics
{
    protected string $pageTitle = '';
    protected int $checkStartTime = 0;
    protected int $checkEndTime = 0;
    protected int $countPages = 0;

    /** @var array<int,int> */
    protected array $countLinksByStatus = [];
    protected int $countLinksTotal = 0;
    protected int $countNewBrokenLinks = 0;

    public function __construct()
    {
        $this->initialize();
    }

    public function initialize(): void
    {
        $this->checkStartTime = time();
        $this->checkEndTime = 0; // Reset end time
        $this->countPages = 0;
        $this->countLinksTotal = 0;
        $this->countNewBrokenLinks = 0;
        $this->countLinksByStatus = [];
        $this->pageTitle = '';
    }

    public function calculateStats(): void
    {
        $this->checkEndTime = time();
    }

    public function incrementCountLinksByStatus(int $status): void
    {
        if (!isset($this->countLinksByStatus[$status])) {
            $this->countLinksByStatus[$status] = 0;
        }
        $this->countLinksByStatus[$status]++;
        $this->countLinksTotal++;
    }

    public function incrementNewBrokenLink(): void
    {
        $this->countNewBrokenLinks++;
    }

    public function getCountNewBrokenLinks(): int
    {
        return $this->countNewBrokenLinks;
    }

    public function incrementCountExcludedLinks(): void
    {
        $this->incrementCountLinksByStatus(LinkTargetResponse::RESULT_EXCLUDED);
    }

    public function incrementCountBrokenLinks(): void
    {
        $this->incrementCountLinksByStatus(LinkTargetResponse::RESULT_BROKEN);
    }

    public function addCountLinks(int $count): void
    {
        $this->countLinksTotal += $count;
    }

    public function setCountPages(int $count): void
    {
        $this->countPages = $count;
    }

    public function setPageTitle(string $pageTitle): void
    {
        $this->pageTitle = $pageTitle;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
    }

    public function getCountPages(): int
    {
        return $this->countPages;
    }

    public function getCountLinks(): int
    {
        return $this->countLinksTotal;
    }

    public function getCountLinksByStatus(int $status): int
    {
        return (int)($this->countLinksByStatus[$status] ?? 0);
    }

    public function getCountBrokenLinks(): int
    {
        return $this->getCountLinksByStatus(LinkTargetResponse::RESULT_BROKEN);
    }

    public function getCountExcludedLinks(): int
    {
        return $this->getCountLinksByStatus(LinkTargetResponse::RESULT_EXCLUDED);
    }

    public function getCheckStartTime(): int
    {
        return $this->checkStartTime;
    }

    public function getCheckEndTime(): int
    {
        return $this->checkEndTime > 0 ? $this->checkEndTime : time(); // Return current time if not ended
    }

    public function getCountLinksChecked(): int
    {
        return $this->countLinksTotal -
               $this->getCountLinksByStatus(LinkTargetResponse::RESULT_EXCLUDED) -
               $this->getCountLinksByStatus(LinkTargetResponse::RESULT_CANNOT_CHECK) -
               $this->getCountLinksByStatus(LinkTargetResponse::RESULT_UNKNOWN); // Also subtract UNKNOWN (Cloudflare) as not 'checked' in the traditional sense
    }

    public function getPercentLinksByStatus(int $status): float
    {
        $countChecked = $this->getCountLinksChecked();
        if ($countChecked === 0) { // Avoid division by zero
            return 0.0;
        }
        $countForStatus = $this->getCountLinksByStatus($status);
        return ($countForStatus / $countChecked) * 100;
    }

    public function getPercentExcludedLinks(): float
    {
        // Percentage of excluded links relative to total links, not just "checked" ones.
        if ($this->countLinksTotal === 0) return 0.0;
        return ($this->getCountLinksByStatus(LinkTargetResponse::RESULT_EXCLUDED) / $this->countLinksTotal) * 100;
    }

    public function getPercentBrokenLinks(): float
    {
        return $this->getPercentLinksByStatus(LinkTargetResponse::RESULT_BROKEN);
    }
}
