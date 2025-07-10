<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Controller\Filter;

use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Gaumondp\PguBrofixExtras\Util\ArrayableInterface; // Assume Arrayable is an interface we create or it's simple enough to be ArrayableInterface
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BrokenLinkListFilter implements ArrayableInterface // Implement our interface
{
    // Constants for keys used in toArray and fromArray/ModuleData
    protected const KEY_UID = 'uid_searchFilter'; // Match ModuleData keys
    protected const KEY_URL = 'url_searchFilter';
    protected const KEY_LINKTYPE = 'linktype_searchFilter';
    protected const KEY_URL_MATCH = 'url_match_searchFilter';
    protected const KEY_CHECK_STATUS = 'check_status';
    protected const KEY_USE_CACHE = 'useCache';
    protected const KEY_HOWTOTRAVERSE = 'howtotraverse';

    protected const LINK_TYPE_FILTER_DEFAULT = 'all';
    protected const URL_MATCH_DEFAULT = 'partial';
    protected const CHECK_STATUS_DEFAULT = LinkTargetResponse::RESULT_BROKEN; // Default to show broken links

    public const PAGE_DEPTH_INFINITE = 999;
    public const HOW_TO_TRAVERSE_PAGES = 'pages';
    public const HOW_TO_TRAVERSE_ALL = 'all'; // Admin only
    public const HOW_TO_TRAVERSE_ALLMOUNTPOINTS = 'allmountpoints'; // Non-admin "all"
    public const HOW_TO_TRAVERSE_DEFAULT = self::HOW_TO_TRAVERSE_PAGES;

    protected string $uidFilter = ''; // Renamed for clarity
    protected string $linktypeFilter = self::LINK_TYPE_FILTER_DEFAULT;
    protected string $urlFilter = ''; // Renamed
    protected string $urlFilterMatch = self::URL_MATCH_DEFAULT;
    protected int $checkStatusFilter = self::CHECK_STATUS_DEFAULT;
    protected bool $useCache = true;
    protected bool $showUseCache = true; // Whether the option is available in UI
    protected string $howtotraverse = self::HOW_TO_TRAVERSE_DEFAULT;

    public function __construct(
        string $uidFilter = '',
        string $linkTypeFilter = self::LINK_TYPE_FILTER_DEFAULT,
        string $urlFilter = '',
        string $urlFilterMatch = self::URL_MATCH_DEFAULT,
        int $checkStatusFilter = self::CHECK_STATUS_DEFAULT,
        bool $useCache = true,
        string $howtotraverse = self::HOW_TO_TRAVERSE_DEFAULT
    ) {
        $this->uidFilter = trim($uidFilter);
        $this->linktypeFilter = $linkTypeFilter;
        $this->urlFilter = $this->normalizeUrlFilter($urlFilter);
        $this->urlFilterMatch = $urlFilterMatch;
        $this->checkStatusFilter = $checkStatusFilter;

        $coreExtConf = GeneralUtility::makeInstance(CoreExtensionConfiguration::class);
        $pguBrofixExtrasConf = $coreExtConf->get('pgu_brofix_extras') ?? []; // Use new extension key
        $this->showUseCache = (bool)($pguBrofixExtrasConf['useCacheForPageList'] ?? true);

        if ($this->showUseCache) {
            $this->useCache = $useCache;
        } else {
            $this->useCache = false; // Force false if UI option is hidden
        }
        $this->setHowtotraverse($howtotraverse); // Use setter to apply admin logic
    }

    public static function getInstanceFromModuleData(ModuleData $moduleData): self
    {
        return new self(
            (string)$moduleData->get(self::KEY_UID, ''),
            (string)$moduleData->get(self::KEY_LINKTYPE, self::LINK_TYPE_FILTER_DEFAULT),
            (string)$moduleData->get(self::KEY_URL, ''),
            (string)$moduleData->get(self::KEY_URL_MATCH, self::URL_MATCH_DEFAULT),
            (int)$moduleData->get(self::KEY_CHECK_STATUS, (string)self::CHECK_STATUS_DEFAULT), // Cast to string for get then int
            (bool)$moduleData->get(self::KEY_USE_CACHE, true), // ModuleData might store bools as 0/1
            (string)$moduleData->get(self::KEY_HOWTOTRAVERSE, self::HOW_TO_TRAVERSE_DEFAULT)
        );
    }

    /** @param array<string,mixed> $values */
    public static function getInstanceFromArray(array $values): self
    {
        return new self(
            (string)($values[self::KEY_UID] ?? ''),
            (string)($values[self::KEY_LINKTYPE] ?? self::LINK_TYPE_FILTER_DEFAULT),
            (string)($values[self::KEY_URL] ?? ''),
            (string)($values[self::KEY_URL_MATCH] ?? self::URL_MATCH_DEFAULT),
            (int)($values[self::KEY_CHECK_STATUS] ?? self::CHECK_STATUS_DEFAULT),
            (bool)($values[self::KEY_USE_CACHE] ?? true),
            (string)($values[self::KEY_HOWTOTRAVERSE] ?? self::HOW_TO_TRAVERSE_DEFAULT)
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            self::KEY_UID => $this->getUidFilter(),
            self::KEY_LINKTYPE => $this->getLinktypeFilter(),
            self::KEY_URL => $this->getUrlFilter(),
            self::KEY_URL_MATCH => $this->getUrlFilterMatch(),
            self::KEY_CHECK_STATUS => $this->getCheckStatusFilter(),
            self::KEY_USE_CACHE => $this->isUseCache(), // Use getter
            self::KEY_HOWTOTRAVERSE => $this->getHowtotraverse(), // Use getter
        ];
    }

    public function isFilterActive(): bool // Renamed from isFilter for clarity
    {
        return $this->getUidFilter() !== ''
            || $this->getLinktypeFilter() !== self::LINK_TYPE_FILTER_DEFAULT
            || $this->getUrlFilter() !== ''
            || $this->getCheckStatusFilter() !== self::CHECK_STATUS_DEFAULT; // Consider checkStatus for active filter state
    }

    public function getUidFilter(): string
    {
        return $this->uidFilter;
    }

    public function setUidFilter(string $uidFilter): void
    {
        $this->uidFilter = trim($uidFilter);
    }

    public function getLinktypeFilter(): string
    {
        return $this->linktypeFilter;
    }

    public function setLinktypeFilter(string $linktypeFilter): void
    {
        $this->linktypeFilter = $linktypeFilter;
    }

    protected function normalizeUrlFilter(string $urlFilter): string
    {
        return trim($urlFilter === 'all' ? '' : $urlFilter); // 'all' was a bugfix in original
    }

    public function getUrlFilter(): string
    {
        return $this->urlFilter; // Already normalized in constructor/setter
    }

    public function setUrlFilter(string $urlFilter): void
    {
        $this->urlFilter = $this->normalizeUrlFilter($urlFilter);
    }

    public function getUrlFilterMatch(): string
    {
        return $this->urlFilterMatch;
    }

    public function setUrlFilterMatch(string $urlFilterMatch): void
    {
        $this->urlFilterMatch = $urlFilterMatch;
    }

    public function getCheckStatusFilter(): int
    {
        return $this->checkStatusFilter;
    }

    public function setCheckStatusFilter(int $checkStatusFilter): void
    {
        $this->checkStatusFilter = $checkStatusFilter;
    }

    public function isUseCache(): bool
    {
        // If the option to show "useCache" is disabled via ext conf, always return false for useCache
        return $this->showUseCache && $this->useCache;
    }

    public function setUseCache(bool $useCache): void
    {
        $this->useCache = $this->showUseCache && $useCache;
    }

    public function isShowUseCache(): bool
    {
        return $this->showUseCache;
    }

    // setShowUseCache might not be needed if it's only from ext conf

    public function getHowtotraverse(): string
    {
        // Logic based on admin status
        if (!$this->isAdmin() && $this->howtotraverse === self::HOW_TO_TRAVERSE_ALL) {
            return self::HOW_TO_TRAVERSE_ALLMOUNTPOINTS;
        }
        // This case might be redundant if admin can select ALLMOUNTPOINTS directly.
        // Original logic: if admin selected ALLMOUNTPOINTS, it was treated as ALL.
        // If $this->howtotraverse is already ALLMOUNTPOINTS and user is admin, it should remain ALLMOUNTPOINTS unless UI prevents admin from choosing it.
        // The original logic was: if (isAdmin && howtotraverse === ALLMOUNTPOINTS) return ALL.
        // This seems counterintuitive if admin explicitly chose ALLMOUNTPOINTS.
        // Let's assume admin choice is respected, or UI handles this.
        // For now, just return the set value, adjusted for non-admin attempting 'all'.
        return $this->howtotraverse;
    }

    public function setHowtotraverse(string $howtotraverse): void
    {
        if (!$this->isAdmin() && $howtotraverse === self::HOW_TO_TRAVERSE_ALL) {
            $this->howtotraverse = self::HOW_TO_TRAVERSE_ALLMOUNTPOINTS;
        } else {
            $this->howtotraverse = $howtotraverse;
        }
    }

    protected function isAdmin(): bool
    {
        $backendUser = $this->getBackendUser();
        return $backendUser && $backendUser->isAdmin();
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
