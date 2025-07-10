<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Repository;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Gaumondp\PguBrofixExtras\CheckLinks\ExcludeLinkTarget;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Gaumondp\PguBrofixExtras\Controller\Filter\BrokenLinkListFilter; // Will need to adapt/create this
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Platform\PlatformInformation;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction; // For general usage, though not explicitly in original here
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;


/**
 * Handle database queries for table of broken links
 * @internal
 */
class BrokenLinkRepository implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const TABLE = 'tx_pgubrofuxextras_broken_links'; // Table name updated
    protected int $maxBindParameters;

    public function __construct()
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        // It's good practice to ensure the table exists before getting a connection for it,
        // or handle potential exceptions if it might not (e.g. during extension setup).
        // However, repositories usually assume their tables exist.
        $connection = $connectionPool->getConnectionForTable(self::TABLE);
        $this->maxBindParameters = PlatformInformation::getMaxBindParameters($connection->getDatabasePlatformName()); // Use platform name
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    public function getMaxBindParameters(): int
    {
        return $this->maxBindParameters > 0 ? $this->maxBindParameters : 2000; // Provide a fallback if detection fails
    }

    /**
     * @param int[]|null $pageList
     * @param string[] $linkTypes
     * @param array<string,array<string>> $searchFields
     * @param array<array<string>> $orderBy
     * @return array<mixed>
     */
    public function getBrokenLinks(
        ?array $pageList,
        array $linkTypes,
        array $searchFields, // $searchFields currently unused in this adapted version's query restrictions directly
        BrokenLinkListFilter $filter,
        array $orderBy = []
    ): array {
        $results = [];
        if ($pageList === []) { // Explicitly no pages to check
            return [];
        }

        $max = (int)($this->getMaxBindParameters() / 2 - 4);
        if ($max <= 0) $max = 50; // Sensible fallback

        // If $pageList is null, it means check all relevant pages (admin context usually)
        // We need a way to handle this, either by fetching all PIDs or by removing page-based constraints.
        // For now, if $pageList is null, we proceed without chunking based on it, implying no page constraints or admin access.
        $chunks = $pageList === null ? [null] : array_chunk($pageList, $max);


        foreach ($chunks as $pageIdsChunk) {
            $queryBuilder = $this->generateQueryBuilder(self::TABLE);

            // Apply EditableRestriction if not admin. This class needs to be adapted or available.
            // For now, we'll assume it's adapted or this logic is simplified.
            if (!$this->getBackendUser()->isAdmin()) {
                // $editableRestriction = GeneralUtility::makeInstance(EditableRestriction::class, $searchFields, $queryBuilder);
                // $queryBuilder->getRestrictions()->add($editableRestriction);
                // Placeholder: if EditableRestriction is not yet adapted, this part would be skipped or simplified.
                // A simple restriction could be to check if the user has read access to the page.
            }

            $queryBuilder
                ->select(self::TABLE . '.*')
                ->from(self::TABLE)
                ->join(
                    self::TABLE,
                    'pages',
                    'p', // Alias for pages table
                    $queryBuilder->expr()->eq(self::TABLE . '.record_pid', $queryBuilder->quoteIdentifier('p.uid'))
                );

            if ($pageIdsChunk !== null) { // Only apply page constraints if a chunk is provided
                $queryBuilder->where(
                    $queryBuilder->expr()->orX(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->in(self::TABLE . '.record_uid', $queryBuilder->createNamedParameter($pageIdsChunk, Connection::PARAM_INT_ARRAY)),
                            $queryBuilder->expr()->eq('table_name', $queryBuilder->createNamedParameter('pages'))
                        ),
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->in(self::TABLE . '.record_pid', $queryBuilder->createNamedParameter($pageIdsChunk, Connection::PARAM_INT_ARRAY)),
                            $queryBuilder->expr()->neq('table_name', $queryBuilder->createNamedParameter('pages'))
                        )
                    )
                );
            }

            // Apply filters from BrokenLinkListFilter
            if ($filter->getUidFilter() !== '') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq(self::TABLE . '.record_uid', $queryBuilder->createNamedParameter($filter->getUidFilter(), Connection::PARAM_INT)));
            }

            $urlFilterValue = $filter->getUrlFilter();
            if ($urlFilterValue !== '') {
                $urlFilters = GeneralUtility::trimExplode('|', $urlFilterValue, true);
                $urlFilterConstraints = [];
                foreach ($urlFilters as $singleUrlFilter) {
                    switch ($filter->getUrlFilterMatch()) {
                        case 'partial':
                            $urlFilterConstraints[] = $queryBuilder->expr()->like(self::TABLE . '.url', $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($singleUrlFilter) . '%'));
                            break;
                        case 'exact':
                            $urlFilterConstraints[] = $queryBuilder->expr()->eq(self::TABLE . '.url', $queryBuilder->createNamedParameter($singleUrlFilter));
                            break;
                        case 'partialnot':
                            $urlFilterConstraints[] = $queryBuilder->expr()->notLike(self::TABLE . '.url', $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($singleUrlFilter) . '%'));
                            break;
                        case 'exactnot':
                            $urlFilterConstraints[] = $queryBuilder->expr()->neq(self::TABLE . '.url', $queryBuilder->createNamedParameter($singleUrlFilter));
                            break;
                    }
                }
                if (!empty($urlFilterConstraints)) {
                     if (in_array($filter->getUrlFilterMatch(), ['partialnot', 'exactnot'], true) ) {
                        $queryBuilder->andWhere($queryBuilder->expr()->andX(...$urlFilterConstraints));
                     } else {
                        $queryBuilder->andWhere($queryBuilder->expr()->orX(...$urlFilterConstraints));
                     }
                }
            }


            $linktypeFilterValue = $filter->getLinkTypeFilter() ?: 'all';
            if ($linktypeFilterValue !== 'all') {
                $queryBuilder->andWhere($queryBuilder->expr()->eq(self::TABLE . '.link_type', $queryBuilder->createNamedParameter($linktypeFilterValue)));
            }

            $checkStatusFilterValue = $filter->getCheckStatusFilter();
            if ($checkStatusFilterValue !== LinkTargetResponse::RESULT_ALL) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq(self::TABLE . '.check_status', $queryBuilder->createNamedParameter($checkStatusFilterValue, Connection::PARAM_INT)));
            }

            if (!empty($orderBy)) {
                $firstOrder = true;
                foreach($orderBy as $orderPair) {
                    if (is_array($orderPair) && count($orderPair) === 2) {
                        $field = self::TABLE . '.' . $orderPair[0]; // Ensure field is prefixed with table name
                        $direction = strtoupper($orderPair[1]);
                        if (in_array($direction, ['ASC', 'DESC'])) {
                           if ($firstOrder) {
                               $queryBuilder->orderBy($field, $direction);
                               $firstOrder = false;
                           } else {
                               $queryBuilder->addOrderBy($field, $direction);
                           }
                        }
                    }
                }
            }


            if (!empty($linkTypes)) {
                $queryBuilder->andWhere($queryBuilder->expr()->in(self::TABLE . '.link_type', $queryBuilder->createNamedParameter($linkTypes, Connection::PARAM_STR_ARRAY)));
            }
            try {
                $results = array_merge($results, $queryBuilder->executeQuery()->fetchAllAssociative());
            } catch (TableNotFoundException $e) {
                $this->logger->error("Table " . self::TABLE . " not found. Skipping getBrokenLinks query.", ['exception' => $e]);
                return []; // Return empty if table doesn't exist
            }
        }
        return $results;
    }


    public function hasPageBrokenLinks(int $pageId, bool $withEditableByUser = true): bool
    {
        // $withEditableByUser logic needs implementation if EditableRestriction is used
        $count = $this->getLinkCountForPage($pageId, $withEditableByUser, LinkTargetResponse::RESULT_BROKEN);
        return $count > 0;
    }

    public function getLinkCountForPage(int $pageId, bool $withEditableByUser = true, int $withStatus = LinkTargetResponse::RESULT_BROKEN): int
    {
        // $withEditableByUser would require joining with permissions or similar complex logic
        $queryBuilder = $this->generateQueryBuilder(self::TABLE);
        $expr = $queryBuilder->expr();

        $pageConditions = $expr->orX(
            $expr->andX(
                $expr->eq(self::TABLE . '.record_uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $expr->eq('table_name', $queryBuilder->createNamedParameter('pages'))
            ),
            $expr->andX(
                $expr->eq(self::TABLE . '.record_pid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $expr->neq('table_name', $queryBuilder->createNamedParameter('pages'))
            )
        );
        $queryBuilder->count('uid')->from(self::TABLE)->where($pageConditions);

        if ($withStatus !== LinkTargetResponse::RESULT_ALL) { // Use constant
            $queryBuilder->andWhere($expr->eq('check_status', $queryBuilder->createNamedParameter($withStatus, Connection::PARAM_INT)));
        }
        try {
            return (int)$queryBuilder->executeQuery()->fetchOne();
        } catch (TableNotFoundException $e) {
            $this->logger->error("Table " . self::TABLE . " not found. Returning 0 for getLinkCountForPage.", ['exception' => $e]);
            return 0;
        }
    }


    public function removeBrokenLinksForRecord(string $tableName, int $recordUid): int
    {
        $queryBuilder = $this->generateQueryBuilder(static::TABLE);
        $expr = $queryBuilder->expr();
        $constraints = [];

        if ($tableName === 'pages') {
            $constraints[] = $expr->orX(
                $expr->andX(
                    $expr->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $expr->eq('table_name', $queryBuilder->createNamedParameter('pages'))
                ),
                $expr->andX(
                    $expr->eq('record_pid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $expr->neq('table_name', $queryBuilder->createNamedParameter('pages'))
                )
            );
        } else {
            $constraints[] = $expr->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT));
            $constraints[] = $expr->eq('table_name', $queryBuilder->createNamedParameter($tableName));
        }
        try {
            return $queryBuilder->delete(static::TABLE)->where(...$constraints)->executeStatement();
        } catch (TableNotFoundException $e) {
             $this->logger->error("Table " . self::TABLE . " not found. Cannot remove broken links.", ['exception' => $e]);
            return 0;
        }
    }

    public function removeForRecordUrl(string $tableName, int $recordUid, string $url, string $linkType): int
    {
        $queryBuilder = $this->generateQueryBuilder(static::TABLE);
        $expr = $queryBuilder->expr();
        $constraints = [];

        if ($tableName === 'pages') {
             $constraints[] = $expr->orX(
                $expr->andX(
                    $expr->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $expr->eq('table_name', $queryBuilder->createNamedParameter('pages'))
                ),
                $expr->andX(
                    $expr->eq('record_pid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $expr->neq('table_name', $queryBuilder->createNamedParameter('pages'))
                )
            );
        } else {
            $constraints[] = $expr->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT));
            $constraints[] = $expr->eq('table_name', $queryBuilder->createNamedParameter($tableName));
        }
        $constraints[] = $expr->eq('url', $queryBuilder->createNamedParameter($url)); // Use eq for exact match
        $constraints[] = $expr->eq('link_type', $queryBuilder->createNamedParameter($linkType)); // Use eq for exact match

        try {
            return $queryBuilder->delete(static::TABLE)->where(...$constraints)->executeStatement();
        } catch (TableNotFoundException $e) {
            $this->logger->error("Table " . self::TABLE . " not found. Cannot remove record URL.", ['exception' => $e]);
            return 0;
        }
    }

    public function removeBrokenLinksForRecordBeforeTime(string $tableName, int $recordUid, int $time): void
    {
        $queryBuilder = $this->generateQueryBuilder(static::TABLE);
        $expr = $queryBuilder->expr();
        try {
            $queryBuilder->delete(static::TABLE)
                ->where(
                    $expr->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $expr->eq('table_name', $queryBuilder->createNamedParameter($tableName)),
                    $expr->lt('tstamp', $queryBuilder->createNamedParameter($time, Connection::PARAM_INT))
                )
                ->executeStatement();
        } catch (TableNotFoundException $e) {
            $this->logger->error("Table " . self::TABLE . " not found. Cannot remove old broken links.", ['exception' => $e]);
        }
    }

    /**
     * @param array<int|string> $pageIds
     * @param array<string> $linkTypes
     */
    public function removeAllBrokenLinksForPagesBeforeTime(array $pageIds, array $linkTypes, int $time): void
    {
        if (empty($pageIds) || empty($linkTypes)) {
            return;
        }
        $max = (int)($this->getMaxBindParameters() / 2 - 4);
        if ($max <= 0) $max = 50;

        foreach (array_chunk($pageIds, $max) as $pageIdsChunk) {
            $queryBuilder = $this->generateQueryBuilder(self::TABLE);
            $expr = $queryBuilder->expr();
            try {
                $queryBuilder->delete(self::TABLE)
                    ->where(
                        $expr->orX(
                            $expr->andX(
                                $expr->in('record_uid', $queryBuilder->createNamedParameter($pageIdsChunk, Connection::PARAM_INT_ARRAY)),
                                $expr->eq('table_name', $queryBuilder->createNamedParameter('pages'))
                            ),
                            $expr->andX(
                                $expr->in('record_pid', $queryBuilder->createNamedParameter($pageIdsChunk, Connection::PARAM_INT_ARRAY)),
                                $expr->neq('table_name', $queryBuilder->createNamedParameter('pages'))
                            )
                        ),
                        $expr->in('link_type', $queryBuilder->createNamedParameter($linkTypes, Connection::PARAM_STR_ARRAY)),
                        $expr->lt('tstamp', $queryBuilder->createNamedParameter($time, Connection::PARAM_INT))
                    )
                    ->executeStatement();
            } catch (TableNotFoundException $e) {
                $this->logger->error("Table " . self::TABLE . " not found. Cannot remove old broken links for pages.", ['exception' => $e]);
                return; // Stop if table not found
            }
        }
    }

    public function isLinkTargetBrokenLink(string $linkTarget, string $linkType): bool
    {
        try {
            $queryBuilder = $this->generateQueryBuilder(static::TABLE);
            $queryBuilder
                ->count('uid')
                ->from(static::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('url_hash', $queryBuilder->createNamedParameter(sha1($linkTarget))),
                    $queryBuilder->expr()->eq('link_type', $queryBuilder->createNamedParameter($linkType)),
                    $queryBuilder->expr()->eq('check_status', $queryBuilder->createNamedParameter(LinkTargetResponse::RESULT_BROKEN, Connection::PARAM_INT))
                );
            return (bool)$queryBuilder->executeQuery()->fetchOne();
        } catch (TableNotFoundException $e) {
            return false; // If table doesn't exist, it's not a broken link in our DB
        }
    }

    public function removeBrokenLinksForLinkTarget(
        string $linkTarget,
        string $linkType = 'external',
        string $matchBy = ExcludeLinkTarget::MATCH_BY_EXACT,
        int $excludeLinkTargetPid = -1 // This field name might need to match the one in the DB table
    ): int {
        $queryBuilder = $this->generateQueryBuilder(static::TABLE);
        $expr = $queryBuilder->expr();
        $constraints = [];

        if ($matchBy === ExcludeLinkTarget::MATCH_BY_EXACT) {
            $constraints[] = $expr->eq('url', $queryBuilder->createNamedParameter($linkTarget));
        } elseif ($matchBy === ExcludeLinkTarget::MATCH_BY_DOMAIN) {
            // Ensure $linkTarget is just the domain for this match type
            $domain = parse_url($linkTarget, PHP_URL_HOST) ?: $linkTarget;
            $constraints[] = $expr->orX(
                $expr->like('url', $queryBuilder->createNamedParameter('%://' . $queryBuilder->escapeLikeWildcards($domain) . '/%')),
                $expr->like('url', $queryBuilder->createNamedParameter('%://' . $queryBuilder->escapeLikeWildcards($domain)))
            );
        } else {
            return 0;
        }

        $constraints[] = $expr->eq('link_type', $queryBuilder->createNamedParameter($linkType));

        if ($excludeLinkTargetPid !== -1) {
            // Ensure 'exclude_link_targets_pid' is the correct column name in your tx_pgubrofuxextras_broken_links table
            $constraints[] = $expr->eq('exclude_link_targets_pid', $queryBuilder->createNamedParameter($excludeLinkTargetPid, Connection::PARAM_INT));
        }

        try {
            return $queryBuilder->delete(static::TABLE)->where(...$constraints)->executeStatement();
        } catch (TableNotFoundException $e) {
             $this->logger->error("Table " . self::TABLE . " not found. Cannot remove broken links by target.", ['exception' => $e]);
            return 0;
        }
    }

    /** @param array<string,mixed> $record */
    public function insertOrUpdateBrokenLink(array $record): bool
    {
        $queryBuilder = $this->generateQueryBuilder(self::TABLE);
        $expr = $queryBuilder->expr();
        $count = 0;
        try {
            $count = (int)$queryBuilder->count('uid')
                ->from(self::TABLE)
                ->where(
                    $expr->eq('record_uid', $queryBuilder->createNamedParameter((int)$record['record_uid'], Connection::PARAM_INT)),
                    $expr->eq('table_name', $queryBuilder->createNamedParameter((string)$record['table_name'])),
                    $expr->eq('field', $queryBuilder->createNamedParameter((string)$record['field'])),
                    $expr->eq('url', $queryBuilder->createNamedParameter((string)$record['url']))
                )
                ->executeQuery()
                ->fetchOne();
        } catch (TableNotFoundException $e) {
            $this->logger->error("Table " . self::TABLE . " not found. Cannot insert/update broken link.", ['exception' => $e]);
            return false; // Cannot proceed if table doesn't exist
        }

        if ($count > 0) {
            $identifier = [
                'record_uid' => (int)$record['record_uid'],
                'table_name' => (string)$record['table_name'],
                'field' => (string)$record['field'],
                'url' => (string)$record['url']
            ];
            $this->updateBrokenLink($record, $identifier);
            return false; // Was an update
        } else {
            $this->insertBrokenLink($record);
            return true; // Was an insert
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $identifier
     */
    public function updateBrokenLink(array $record, array $identifier): int
    {
        $record['tstamp'] = time();
        $record['url_hash'] = sha1((string)($record['url'] ?? ''));
        try {
            return GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLE)
                ->update(self::TABLE, $record, $identifier);
        } catch (\Exception $e) {
            $this->logger->error("Error updating broken link: " . $e->getMessage(), ['record' => $record, 'identifier' => $identifier]);
            return 0;
        }
    }

    /** @param array<string,mixed> $record */
    public function insertBrokenLink(array $record): void
    {
        $record['tstamp'] = time();
        $record['crdate'] = time();
        $record['url_hash'] = sha1((string)($record['url'] ?? ''));
        try {
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLE)
                ->insert(self::TABLE, $record);
        } catch (\Exception $e) {
            $this->logger->error("Error inserting broken link: " . $e->getMessage(), ['record' => $record]);
        }
    }

    protected function generateQueryBuilder(string $table = ''): QueryBuilder
    {
        if ($table === '') {
            $table = self::TABLE;
        }
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
