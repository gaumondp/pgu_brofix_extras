<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetCache;

use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * This class implements a persistent link target cache using
 * a database table.
 * @internal
 */
class LinkTargetPersistentCache extends AbstractLinkTargetCache
{
    protected const TABLE = 'tx_pgubrofuxextras_link_target_cache'; // Table name updated

    // CHECK_STATUS constants are not directly used by this class logic,
    // but might be used by consumers or for raw DB values if status was simpler.
    // LinkTargetResponse->getStatus() is used which is more granular.
    // const CHECK_STATUS_NONE = 0;
    // const CHECK_STATUS_OK = 1;
    // const CHECK_STATUS_ERROR = 2;

    public function hasEntryForUrl(string $linkTarget, string $linkType, bool $useExpire = true, int $expire = 0): bool
    {
        if (!$this->isTableExists()) {
            return false;
        }
        $queryBuilder = $this->generateQueryBuilder();

        $constraints = [
            $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($linkTarget)),
            $queryBuilder->expr()->eq('link_type', $queryBuilder->createNamedParameter($linkType)),
        ];

        if ($useExpire) {
            $_expire = $expire ?: $this->expire;
            if ($_expire > 0) { // Only apply time-based expiration if expire is positive
                $constraints[] = $queryBuilder->expr()->neq('last_check', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
                $constraints[] = $queryBuilder->expr()->gt('last_check', $queryBuilder->createNamedParameter(time() - $_expire, Connection::PARAM_INT));
            }
        }

        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne() > 0;
    }

    public function getUrlResponseForUrl(string $linkTarget, string $linkType, int $expire = 0): ?LinkTargetResponse
    {
        if (!$this->isTableExists()) {
            return null;
        }
        $_expire = $expire ?: $this->expire;
        $queryBuilder = $this->generateQueryBuilder();
        $constraints = [
            $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($linkTarget)),
            $queryBuilder->expr()->eq('link_type', $queryBuilder->createNamedParameter($linkType))
        ];

        if ($_expire > 0) { // Only apply time-based expiration if expire is positive
            $constraints[] = $queryBuilder->expr()->neq('last_check', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT));
            $constraints[] = $queryBuilder->expr()->gt('last_check', $queryBuilder->createNamedParameter(time() - $_expire, Connection::PARAM_INT));
        }

        $queryBuilder
            ->select('url_response', 'last_check')
            ->from(self::TABLE)
            ->where(...$constraints);

        $row = $queryBuilder->executeQuery()->fetchAssociative();

        if (!$row || empty($row['url_response'])) {
            return null;
        }
        try {
            return LinkTargetResponse::createInstanceFromJson((string)$row['url_response']);
        } catch (\JsonException $e) {
            // Log error, invalid JSON in cache
            return null;
        }
    }

    public function setResult(string $linkTarget, string $linkType, LinkTargetResponse $linkTargetResponse): void
    {
        if (!$this->isTableExists()) {
            // Optionally log that table doesn't exist
            return;
        }
        // hasEntryForUrl(..., false) to check existence regardless of expiration
        if ($this->hasEntryForUrl($linkTarget, $linkType, false)) {
            $this->update($linkTarget, $linkType, $linkTargetResponse);
        } else {
            $this->insert($linkTarget, $linkType, $linkTargetResponse);
        }
    }

    protected function insert(string $linkTarget, string $linkType, LinkTargetResponse $linkTargetResponse): void
    {
        try {
            $queryBuilder = $this->generateQueryBuilder();
            $queryBuilder
                ->insert(self::TABLE)
                ->values([
                    'url' => $linkTarget,
                    'link_type' => $linkType,
                    'url_response' => $linkTargetResponse->toJson(),
                    'check_status' => $linkTargetResponse->getStatus(), // Store the granular status
                    'last_check' => time(),
                    'crdate' => time(), // Add crdate
                    'tstamp' => time(), // Add tstamp for consistency with TYPO3 tables
                ])
                ->executeStatement();
        } catch (\JsonException $e) {
            // Log error: failed to serialize LinkTargetResponse to JSON
        }
    }

    protected function update(string $linkTarget, string $linkType, LinkTargetResponse $linkTargetResponse): void
    {
        try {
            $queryBuilder = $this->generateQueryBuilder();
            $queryBuilder
                ->update(self::TABLE)
                ->where(
                    $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($linkTarget)),
                    $queryBuilder->expr()->eq('link_type', $queryBuilder->createNamedParameter($linkType))
                )
                ->set('url_response', $linkTargetResponse->toJson())
                ->set('check_status', $linkTargetResponse->getStatus()) // Store the granular status
                ->set('last_check', time())
                ->set('tstamp', time()) // Update tstamp
                ->executeStatement();
        } catch (\JsonException $e) {
            // Log error: failed to serialize LinkTargetResponse to JSON
        }
    }

    public function remove(string $linkTarget, string $linkType): void
    {
        if (!$this->isTableExists()) {
            return;
        }
        $queryBuilder = $this->generateQueryBuilder();
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($linkTarget)),
                $queryBuilder->expr()->eq('link_type', $queryBuilder->createNamedParameter($linkType))
            )
            ->executeStatement();
    }

    protected function generateQueryBuilder(string $table = ''): QueryBuilder
    {
        if ($table === '') {
            $table = self::TABLE;
        }
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }

    protected function isTableExists(): bool
    {
        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
            return $connection->createSchemaManager()->tablesExist([self::TABLE]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
