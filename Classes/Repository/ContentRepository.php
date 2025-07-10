<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility; // For checking if gridelements is loaded

/**
 * ContentRepository for tt_content and other tables
 */
class ContentRepository
{
    // Default table, can be overridden by generateQueryBuilder
    protected const DEFAULT_TABLE = 'tt_content';

    /**
     * @param array<string> $fields
     * @return array<mixed>
     */
    public function getRowForUid(int $uid, string $table, array $fields, bool $checkHidden = false): array
    {
        $queryBuilder = $this->generateQueryBuilder($table);
        if ($checkHidden) {
            $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        }

        // Ensure essential fields like 'uid' and 'pid' are always selected if not already present
        if (!in_array('uid', $fields, true)) {
            $fields[] = 'uid';
        }
        if (!in_array('pid', $fields, true)) {
            $fields[] = 'pid';
        }

        // Alias tstamp and label fields if they exist in TCA
        // This part of original code aliased them to 'timestamp' and 'header'
        // but it's safer to use their actual names or ensure aliases are consistently used.
        // For simplicity, we'll select them by their TCA defined names.
        if (isset($GLOBALS['TCA'][$table]['ctrl']['tstamp']) && !in_array($GLOBALS['TCA'][$table]['ctrl']['tstamp'], $fields, true)) {
            $fields[] = $GLOBALS['TCA'][$table]['ctrl']['tstamp'];
        }
        if (isset($GLOBALS['TCA'][$table]['ctrl']['label']) && !in_array($GLOBALS['TCA'][$table]['ctrl']['label'], $fields, true)) {
            $fields[] = $GLOBALS['TCA'][$table]['ctrl']['label'];
        }
        // Add language field if defined
        if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField']) && !in_array($GLOBALS['TCA'][$table]['ctrl']['languageField'], $fields, true)) {
             $fields[] = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
        }
        // Add type field if defined
        if (isset($GLOBALS['TCA'][$table]['ctrl']['type']) && !in_array($GLOBALS['TCA'][$table]['ctrl']['type'], $fields, true)) {
             $fields[] = $GLOBALS['TCA'][$table]['ctrl']['type'];
        }


        $result = $queryBuilder->select(...array_unique($fields)) // Ensure fields are unique
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $result ?: []; // Ensure an array is always returned
    }

    public function isGridElementParentHidden(int $uid): bool
    {
        if (!ExtensionManagementUtility::isLoaded('gridelements')) {
            return false; // GridElements not loaded, so this check is irrelevant
        }

        $queryBuilder = $this->generateQueryBuilder('tt_content'); // Grid elements are tt_content
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $parentId = $queryBuilder
            ->select('tx_gridelements_container') // Field specific to gridelements
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        if ($parentId === false || (int)$parentId === 0) {
            return false; // Not a child of a grid element or parent ID not found
        }

        // New QueryBuilder instance for the parent check
        $parentQueryBuilder = $this->generateQueryBuilder('tt_content');
        $parentQueryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $parentHidden = $parentQueryBuilder
            ->select('hidden')
            ->from('tt_content')
            ->where($parentQueryBuilder->expr()->eq('uid', $parentQueryBuilder->createNamedParameter((int)$parentId, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return (bool)$parentHidden;
    }

    protected function generateQueryBuilder(string $table = ''): QueryBuilder
    {
        if ($table === '') {
            $table = static::DEFAULT_TABLE;
        }
        return GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
    }
}
