<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;


/**
 * This class takes care of checking if a link target (URL)
 * should be excluded from checking. The URL is then always
 * handled as if it is valid.
 *
 * @internal
 */
class ExcludeLinkTarget
{
    public const MATCH_BY_EXACT = 'exact';
    public const MATCH_BY_DOMAIN = 'domain';
    public const TABLE = 'tx_pgubrofuxextras_exclude_link_target'; // Table name updated for the new extension

    public const REASON_NONE_GIVEN = 0;
    public const REASON_NO_BROKEN_LINK = 1;

    protected int $excludeLinkTargetsPid = 0;

    public function setExcludeLinkTargetsPid(int $pid): void
    {
        $this->excludeLinkTargetsPid = $pid;
    }

    public function isExcluded(string $url, string $linkType = 'external'): bool
    {
        if (!$this->isTableExists()) {
            return false;
        }

        $queryBuilder = $this->generateQueryBuilder();
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;

        $matchConstraints = [];
        // Match by: exact
        $matchConstraints[] = $queryBuilder->expr()->and(
            $queryBuilder->expr()->eq('linktarget', $queryBuilder->createNamedParameter($url)),
            $queryBuilder->expr()->eq('matchtype', $queryBuilder->createNamedParameter(self::MATCH_BY_EXACT)) // field name 'match' might be reserved, using 'matchtype'
        );

        if ($host) {
            // Match by: domain
            $matchConstraints[] = $queryBuilder->expr()->and(
                // Check if linktarget is like '%host%' OR if linktarget is just 'host'
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->like('linktarget', $queryBuilder->createNamedParameter('%' . $host . '%')),
                    $queryBuilder->expr()->eq('linktarget', $queryBuilder->createNamedParameter($host))
                ),
                $queryBuilder->expr()->eq('matchtype', $queryBuilder->createNamedParameter(self::MATCH_BY_DOMAIN))
            );
        }

        $constraints = [
            $queryBuilder->expr()->eq('link_type', $queryBuilder->createNamedParameter($linkType)),
            $queryBuilder->expr()->orX(...$matchConstraints)
        ];

        if ($this->excludeLinkTargetsPid > 0) { // Only add PID constraint if it's set
            $constraints[] = $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($this->excludeLinkTargetsPid, Connection::PARAM_INT));
        }

        $count = (int)($queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne());

        return $count > 0;
    }

    public function currentUserHasCreatePermissions(int $pageId): bool
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return false; // Should not happen in BE context
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if ($backendUser->check('tables_modify', self::TABLE)) {
            $queryBuilder = $this->generateQueryBuilder('pages');
            $pageRow = $queryBuilder
                ->select('*')
                ->from('pages')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchAssociative();

            return $pageRow ? $backendUser->doesUserHaveAccess($pageRow, 16) : false; // Check for page existence
        }
        return false;
    }

    protected function isTableExists(): bool
    {
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
            return $connection->createSchemaManager()->tablesExist([self::TABLE]);
        } catch (\Exception $e) {
            // Log error or handle - table might not exist during setup or if SQL not imported
            return false;
        }
    }

    protected function generateQueryBuilder(string $table = ''): QueryBuilder
    {
        if ($table === '') {
            $table = self::TABLE;
        }
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
