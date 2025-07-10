<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Repository;

use Gaumondp\PguBrofixExtras\Cache\CacheManager; // Will need to adapt/create this
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Repository for pages table.
 * @internal
 */
class PagesRepository
{
    protected const TABLE = 'pages';
    protected ?CacheManager $cacheManager;

    public function __construct(?CacheManager $cacheManager = null)
    {
        // If CacheManager is specific to Brofix and not a generic TYPO3 cache,
        // it needs to be adapted into this extension.
        // For now, assume it's either adapted or can be null if caching is optional/disabled.
        $this->cacheManager = $cacheManager ?: (class_exists(CacheManager::class) ? GeneralUtility::makeInstance(CacheManager::class) : null);
    }

    /**
     * @param array<int,int> $pageList
     * @param array<int,int> $startPages
     * @param array<int,int> $excludedPages
     * @param array<int,int> $doNotCheckPageTypes
     * @param array<int,int> $doNotTraversePageTypes
     * @return array<int,int>
     */
    protected function getAllSubpagesForPage(
        array &$pageList,
        array $startPages,
        bool $useStartPage,
        int $depth,
        string $permsClause,
        bool $considerHidden = false,
        array $excludedPages = [],
        array $doNotCheckPageTypes = [],
        array $doNotTraversePageTypes = [],
        int $traverseMaxNumberOfPages = 0
    ): array {
        if (empty($startPages)) {
            return $pageList;
        }
        if (!$useStartPage) {
            if ($depth === 0) {
                return $pageList;
            }
            $depth--;
        }

        if ($traverseMaxNumberOfPages > 0 && count($pageList) > $traverseMaxNumberOfPages) {
            return $pageList;
        }

        $queryBuilder = $this->generateQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        // HiddenRestriction is applied based on $considerHidden later

        $queryBuilder
            ->select('uid', 'hidden', 'extendToSubpages', 'doktype')
            ->from(self::TABLE)
            ->where(QueryHelper::stripLogicalOperatorPrefix($permsClause));

        if (!$considerHidden) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }


        if ($useStartPage) {
            $queryBuilder->andWhere($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($startPages, Connection::PARAM_INT_ARRAY)));
        } else {
            $queryBuilder->andWhere($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($startPages, Connection::PARAM_INT_ARRAY)));
        }

        $result = $queryBuilder->executeQuery();
        $subpagesToRecurse = [];

        while ($row = $result->fetchAssociative()) {
            $id = (int)$row['uid'];
            $isHidden = (bool)$row['hidden'];
            $extendToSubpages = (bool)($row['extendToSubpages'] ?? false); // Ensure it's a boolean
            $doktype = (int)($row['doktype'] ?? 1);

            if (!in_array($id, $excludedPages, true)) {
                if (!$isHidden || $considerHidden) { // Page itself is considered if not hidden or if we consider hidden
                    if (!in_array($doktype, $doNotCheckPageTypes, true)) {
                        $pageList[$id] = $id;
                    }
                }
                // Determine if we should traverse into subpages
                if ($depth > 0 && !in_array($doktype, $doNotTraversePageTypes, true)) {
                    if ($isHidden && $extendToSubpages && !$considerHidden) {
                        // Is hidden, extends to subpages, but we are NOT considering hidden pages for traversal
                        // This case means we stop here for this branch.
                    } else {
                         $subpagesToRecurse[] = $id;
                    }
                }
            }
            if ($traverseMaxNumberOfPages > 0 && count($pageList) > $traverseMaxNumberOfPages) {
                return $pageList; // Early exit if limit reached
            }
        }

        if (!empty($subpagesToRecurse) && $depth > 0) { // Check depth again before recursion
            $this->getAllSubpagesForPage(
                $pageList,
                $subpagesToRecurse,
                false, // For subpages, $useStartPage is false (we are looking for children of $subpagesToRecurse)
                $depth, // Depth is already decremented if $useStartPage was false initially, or remains for next level
                $permsClause,
                $considerHidden,
                $excludedPages,
                $doNotCheckPageTypes,
                $doNotTraversePageTypes,
                $traverseMaxNumberOfPages
            );
        }
        return $pageList;
    }

    /**
     * @param array<int,int> $pageList
     * @param array<int,int> $startPages
     * @param array<int,int> $excludedPages
     * @param array<int,int> $doNotCheckPageTypes
     * @param array<int,int> $doNotTraversePageTypes
     * @return array<int,int>
     */
    public function getPageList(
        array &$pageList,
        array $startPages,
        int $depth,
        string $permsClause,
        bool $considerHidden = false,
        array $excludedPages = [],
        array $doNotCheckPageTypes = [],
        array $doNotTraversePageTypes = [],
        int $traverseMaxNumberOfPages = 0,
        bool $useCache = true
    ): array {
        $effectiveStartPages = array_diff($startPages, $excludedPages);
        if (empty($effectiveStartPages)) {
            return $pageList;
        }

        $hash = null;
        if ($this->cacheManager && $useCache && $depth > 3) { // Caching logic
            $beUser = $this->getBackendUser();
            $username = $beUser ? ($beUser->isAdmin() ? 'admin' : ($beUser->user['username'] ?? 'nouser')) : 'cli';
            $code = sprintf(
                'pgubrofuxextras_pages_%s_%d_%d_%s', // Use unique prefix
                implode(',', $effectiveStartPages),
                $depth,
                (int)$considerHidden,
                $username // Consider permsClause as well for more specific caching if needed
            );
            $hash = md5($code);
            $cachedPids = $this->cacheManager->getObject($hash);
            if (is_array($cachedPids)) { // Check if it's an array (valid cache)
                $pageList = array_merge($pageList, $cachedPids);
                return $pageList;
            }
        }

        $this->getAllSubpagesForPage(
            $pageList,
            $effectiveStartPages,
            true, // Start with the pages in $effectiveStartPages themselves
            $depth,
            $permsClause,
            $considerHidden,
            $excludedPages,
            $doNotCheckPageTypes,
            $doNotTraversePageTypes,
            $traverseMaxNumberOfPages
        );

        // Add translations for all collected pages
        if (!empty($pageList)) { // Only fetch translations if we have pages
            $this->fetchAndAddTranslations($pageList, $permsClause, $considerHidden);
        }


        if ($this->cacheManager && $hash !== null) {
            $this->cacheManager->setObject($hash, $pageList, 7200); // Cache for 2 hours
        }
        return array_unique($pageList); // Ensure uniqueness
    }

    /**
     * @param array<int,int> $pageList (passed by reference to add translations to it)
     * @param array<int,int> $limitToLanguageIds
     */
    protected function fetchAndAddTranslations(
        array &$pageList,
        string $permsClause,
        bool $considerHiddenPages,
        array $limitToLanguageIds = []
    ): void {
        if (empty($pageList)) {
            return;
        }
        $queryBuilder = $this->generateQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!$considerHiddenPages) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $constraints = [
            $queryBuilder->expr()->in('l10n_parent', $queryBuilder->createNamedParameter(array_values($pageList), Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->gt('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)) // Only translated pages
        ];

        if (!empty($limitToLanguageIds)) {
            $constraints[] = $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter($limitToLanguageIds, Connection::PARAM_INT_ARRAY));
        }
        if ($permsClause) {
            $constraints[] = QueryHelper::stripLogicalOperatorPrefix($permsClause);
        }

        $result = $queryBuilder
            ->select('uid') // Only need uid
            ->from(self::TABLE)
            ->where(...$constraints)
            ->executeQuery();

        while ($row = $result->fetchAssociative()) {
            $id = (int)$row['uid'];
            $pageList[$id] = $id; // Add translated page UID to the list
        }
    }


    /** @param array<mixed> $pageInfo */
    public function getRootLineIsHidden(array $pageInfo): bool
    {
        if (empty($pageInfo['pid']) || (int)$pageInfo['pid'] === 0) { // Root page
            return (bool)($pageInfo['hidden'] ?? false); // Only hidden status of the page itself matters
        }

        if (!empty($pageInfo['hidden']) && !empty($pageInfo['extendToSubpages'])) {
            return true;
        }
        // If current page is hidden but doesn't extend, its children might still be visible if accessed directly.
        // But for traversal, if it's hidden and doesn't extend, its own content might be skipped.
        // This method is about the *rootline* being hidden, implying if any parent is hidden and extends.

        $rootline = BackendUtility::BEgetRootLine((int)$pageInfo['uid']);
        foreach ($rootline as $parentPage) {
            if (!empty($parentPage['hidden']) && !empty($parentPage['extendToSubpages'])) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0: string, 1: string} Page title and path */
    public function getPagePath(int $uid, int $titleLimit = 0): array
    {
        $title = '';
        $path = '';
        $rootline = BackendUtility::BEgetRootLine($uid, '', true); // Get full rootline

        foreach ($rootline as $record) {
            if ((int)$record['uid'] === 0) continue; // Skip root page of TYPO3 instance

            $pageTitlePart = strip_tags((string)($record['title'] ?? ''));
            if ($title === '') { // First element is the page itself
                $title = $titleLimit > 0 ? GeneralUtility::fixed_lgd_cs($pageTitlePart, $titleLimit) : $pageTitlePart;
                $title = $title ?: '[' . $record['uid'] . ']';
            }
            $path = '/' . $pageTitlePart . $path;
        }
        return [$title, $path ?: '/'];
    }

    protected function generateQueryBuilder(string $table = ''): QueryBuilder
    {
        if ($table === '') {
            $table = static::TABLE;
        }
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
