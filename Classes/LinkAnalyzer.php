<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Gaumondp\PguBrofixExtras\CheckLinks\CheckLinksStatistics;
use Gaumondp\PguBrofixExtras\CheckLinks\ExcludeLinkTarget;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use Gaumondp\PguBrofixExtras\Linktype\AbstractLinktype;
use Gaumondp\PguBrofixExtras\Parser\LinkParser;
use Gaumondp\PguBrofixExtras\Repository\BrokenLinkRepository;
use Gaumondp\PguBrofixExtras\Repository\ContentRepository;
use Gaumondp\PguBrofixExtras\Repository\PagesRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Log\LogManager; // Added for logger

/**
 * Handles link checking
 * @internal This class may be heavily refactored in the future!
 */
class LinkAnalyzer implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Array of tables and fields to search for broken links
     *
     * @var array<string,array<string>>
     */
    protected $searchFields = [];

    /**
     * List of page uids (rootline downwards)
     *
     * @var array<string|int>|null
     */
    protected ?array $pids = [];

    protected ?Configuration $configuration = null;
    protected BrokenLinkRepository $brokenLinkRepository;
    protected ContentRepository $contentRepository;
    protected PagesRepository $pagesRepository;

    /**
     * @var CheckLinksStatistics|null
     */
    protected ?CheckLinksStatistics $statistics = null;

    protected LinkParser $linkParser;

    public function __construct(
        BrokenLinkRepository $brokenLinkRepository,
        ContentRepository $contentRepository,
        PagesRepository $pagesRepository
        // Consider injecting LogManager or LoggerInterface if not relying solely on LoggerAwareTrait auto-injection
    ) {
        // Ensure LanguageService is available before calling methods on it.
        // In TYPO3 v12/v13, direct $GLOBALS access is discouraged for services.
        // LanguageService is typically injected or retrieved via GeneralUtility::makeInstance() if needed here.
        // However, includeLLFile is an older practice. Language files are usually loaded automatically.
        // $this->getLanguageService()->includeLLFile('EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf');
        // It's better if the module itself ensures its language files are loaded, e.g. via ext_localconf or module registration.

        $this->brokenLinkRepository = $brokenLinkRepository;
        $this->contentRepository = $contentRepository;
        $this->pagesRepository = $pagesRepository;
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__)); // Explicitly set logger
    }

    /**
     * @param array<string|int>|null $pidList
     * @param Configuration $configuration
     */
    public function init(?array $pidList, Configuration $configuration): void
    {
        $this->configuration = $configuration;

        $this->searchFields = $this->configuration->getSearchFields();
        $this->pids = $pidList;
        $this->statistics = GeneralUtility::makeInstance(CheckLinksStatistics::class); // Ensure DI or makeInstance
        $this->linkParser = GeneralUtility::makeInstance(LinkParser::class, $this->configuration); // Ensure DI or makeInstance
        // LinkParser::initialize might need to be LinkParserFactory::create or similar if it's complex
    }

    /**
     * Recheck the URL (without using link target cache). If valid, remove existing broken links records.
     * If still invalid, check if link still exists in record. If not, remove from list of broken links.
     *
     * @param string $message
     * @param mixed[] $record
     * @return int Number of broken link records removed
     */
    public function recheckUrl(string &$message, array $record, ServerRequestInterface $request): int
    {
        $message = '';
        $url = (string)($record['url'] ?? '');
        $linkType = (string)($record['linkType'] ?? '');
        $table = (string)($record['table'] ?? '');

        if (!$this->configuration) {
            // Configuration not initialized, cannot proceed
            $this->logger->error('LinkAnalyzer configuration not initialized in recheckUrl.');
            return 0;
        }

        $linktypeObject = $this->configuration->getLinktypeObject($linkType);
        if ($linktypeObject instanceof AbstractLinktype) {
            $mode = AbstractLinktype::CHECK_LINK_FLAG_NO_CRAWL_DELAY | AbstractLinktype::CHECK_LINK_FLAG_NO_CACHE
                | AbstractLinktype::CHECK_LINK_FLAG_SYNCHRONOUS;
            $linkTargetResponse = $linktypeObject->checkLink($url, [], $mode);

            if ($linkTargetResponse->isOk() && !$this->configuration->isShowAllLinks()) {
                $count = $this->brokenLinkRepository->removeBrokenLinksForLinkTarget(
                    $url,
                    $linkType,
                    ExcludeLinkTarget::MATCH_BY_EXACT, // Ensure ExcludeLinkTarget is available
                    -1
                );
                $message = sprintf(
                    $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.url.ok.removed'),
                    $url,
                    $count
                );
                return $count;
            }

            if ($record) {
                $uid = (int)($record['uid'] ?? 0);
                $results = [];

                $row = null;
                if ($this->configuration->getTcaProcessing() === Configuration::TCA_PROCESSING_FULL) {
                    $row = $this->contentRepository->getRowForUid($uid, $table, ['*']);
                } else {
                    $selectFields = $this->getSelectFields($table, [(string)($record['field'] ?? '')]);
                    $row = $this->contentRepository->getRowForUid($uid, $table, $selectFields);
                }

                if ($row) {
                    $this->linkParser->findLinksForRecord(
                        $results,
                        $table,
                        [(string)($record['field'] ?? '')],
                        $row,
                        $request,
                        LinkParser::MASK_CONTENT_CHECK_ALL // Ensure LinkParser is available
                    );
                }
                $urlsInRecord = [];
                foreach ($results[$linkType] ?? [] as $entryValue) {
                    $pageWithAnchor = $entryValue['pageAndAnchor'] ?? '';
                    if (!empty($pageWithAnchor)) {
                        $urlsInRecord[] = $pageWithAnchor;
                    } else {
                        $urlsInRecord[] = $entryValue['substr']['tokenValue'] ?? null;
                    }
                }
                $urlsInRecord = array_filter($urlsInRecord);


                if (!in_array($url, $urlsInRecord, true)) {
                    $count = $this->brokenLinkRepository->removeForRecordUrl(
                        $table,
                        $uid,
                        $url,
                        $linkType
                    );
                    $message = sprintf(
                        $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.url.notok.removed'),
                        $url,
                        $count
                    );
                    return $count;
                }
            }

            $brokenLinkRecordData = [];
            $brokenLinkRecordData['url'] = $url;
            $brokenLinkRecordData['check_status'] = $linkTargetResponse->getStatus();
            $brokenLinkRecordData['url_response'] = $linkTargetResponse->toJson();
            $brokenLinkRecordData['last_check_url'] = time();
            $brokenLinkRecordData['last_check'] = time();
            $identifier = [
                'url' => $url,
                'link_type' => $linkType
            ];
            $count = $this->brokenLinkRepository->updateBrokenLink($brokenLinkRecordData, $identifier);
            if ($linkTargetResponse->isError()) {
                $message = sprintf(
                    $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.url.notok.updated'),
                    $url,
                    $count
                );
            }
            return $count;
        }
        if ($message === '') {
            $message = sprintf(
                $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.url'),
                $url
            );
        }
        return 0;
    }

    public function recheckRecord(
        string &$message,
        array $linkTypes,
        int $recordUid,
        string $table,
        string $field,
        int $beforeEditedTimestamp,
        ServerRequestInterface $request,
        bool $checkHidden = false
    ): bool {
        if (!$this->configuration) {
            $this->logger->error('LinkAnalyzer configuration not initialized in recheckRecord.');
            return false;
        }
        if ($this->configuration->isRecheckLinksOnEditing()) {
            return $this->recheckLinks(
                $message,
                $linkTypes,
                $recordUid,
                $table,
                $field,
                $beforeEditedTimestamp,
                $request,
                $checkHidden
            );
        }
        return $this->checkLinksStillExistInRecord(
            $message,
            $linkTypes,
            $recordUid,
            $table,
            $field,
            $beforeEditedTimestamp,
            $request,
            $checkHidden
        );
    }

    public function recheckLinks(
        string &$message,
        array $linkTypes,
        int $recordUid,
        string $table,
        string $field,
        int $beforeEditedTimestamp,
        ServerRequestInterface $request,
        bool $checkHidden = false
    ): bool {
        if (!$this->configuration) {
            $this->logger->error('LinkAnalyzer configuration not initialized in recheckLinks.');
            return false;
        }
        $row = null;
        if ($this->configuration->getTcaProcessing() === Configuration::TCA_PROCESSING_FULL) {
            $row = $this->contentRepository->getRowForUid($recordUid, $table, ['*'], $checkHidden);
        } else {
            $selectFields = $this->getSelectFields($table, [$field]);
            $row = $this->contentRepository->getRowForUid($recordUid, $table, $selectFields, $checkHidden);
        }

        $startTime = time();

        if (!$row) {
            $message = sprintf($this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.message.removed'), $recordUid);
            $this->brokenLinkRepository->removeBrokenLinksForRecordBeforeTime($table, $recordUid, $startTime);
            return true;
        }
        $headerField = $GLOBALS['TCA'][$table]['ctrl']['label'] ?? '';
        $header = $row[$headerField] ?? (string)$recordUid;

        $timestampField = $GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? '';
        $timestampValue = 0;
        if ($timestampField) {
            $timestampValue = (int)($row[$timestampField] ?? 0);
        }

        if ($beforeEditedTimestamp && $timestampValue && $beforeEditedTimestamp >= $timestampValue) {
            $llKey = 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.message.notchanged';
            $_message = $this->getLanguageService()->sL($llKey);
            if ($_message && $_message !== $llKey) { // Check if translation was successful
                 $message = sprintf($_message, $header);
            } else {
                $message = "Record {$header} was not changed - no need to recheck"; // Fallback
            }
            return false;
        }
        $resultsLinks = [];
        $this->linkParser->findLinksForRecord(
            $resultsLinks,
            $table,
            [$field],
            $row,
            $request,
            LinkParser::MASK_CONTENT_CHECK_ALL - LinkParser::MASK_CONTENT_CHECK_IF_EDITABLE_FIELD
        );

        if ($resultsLinks) {
            $flags = AbstractLinktype::CHECK_LINK_FLAG_NO_CRAWL_DELAY | AbstractLinktype::CHECK_LINK_FLAG_SYNCHRONOUS;
            $this->checkLinks($resultsLinks, $linkTypes, $flags);
        }
        $this->brokenLinkRepository->removeBrokenLinksForRecordBeforeTime($table, $recordUid, $startTime);
        $message = sprintf($this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.message.checked'), $header);
        return true;
    }

    public function checkLinksStillExistInRecord(
        string &$message,
        array $linkTypes,
        int $recordUid,
        string $table,
        string $field,
        int $beforeEditedTimestamp,
        ServerRequestInterface $request,
        bool $checkHidden = false
    ): bool {
        if (!$this->configuration) {
            $this->logger->error('LinkAnalyzer configuration not initialized in checkLinksStillExistInRecord.');
            return false;
        }
        if (!isset($GLOBALS['TCA'][$table]) || !is_array($GLOBALS['TCA'][$table])) {
            return false;
        }

        $row = null;
        if ($this->configuration->getTcaProcessing() === Configuration::TCA_PROCESSING_FULL) {
            $row = $this->contentRepository->getRowForUid($recordUid, $table, ['*'], $checkHidden);
        } else {
            $selectFields = $this->getSelectFields($table, [$field]);
            $row = $this->contentRepository->getRowForUid($recordUid, $table, $selectFields, $checkHidden);
        }

        $startTime = time();

        if (!$row) {
            $message = sprintf($this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.message.removed'), $recordUid);
            $this->brokenLinkRepository->removeBrokenLinksForRecordBeforeTime($table, $recordUid, $startTime);
            return true;
        }
        $headerField = $GLOBALS['TCA'][$table]['ctrl']['label'] ?? '';
        $header = $row[$headerField] ?? (string)$recordUid;

        $timestampField = $GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? '';
        $timestampValue = 0;
        if ($timestampField) {
            $timestampValue = (int)($row[$timestampField] ?? 0);
        }

        if ($beforeEditedTimestamp && $timestampValue && $beforeEditedTimestamp >= $timestampValue) {
            $_message = $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.message.notchanged');
            if ($_message) {
                $message = sprintf($_message, $header);
            }
            return false;
        }
        $resultsLinks = [];
        $this->linkParser->findLinksForRecord(
            $resultsLinks,
            $table,
            [$field],
            $row,
            $request,
            LinkParser::MASK_CONTENT_CHECK_ALL - LinkParser::MASK_CONTENT_CHECK_IF_EDITABLE_FIELD
        );

        $queryBuilderLinkval = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_brofix_broken_links');
        $resultLinkval = $queryBuilderLinkval->select('uid', 'link_type', 'link_title', 'url')
            ->from('tx_brofix_broken_links')
            ->where(
                $queryBuilderLinkval->expr()->eq('record_uid', $queryBuilderLinkval->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilderLinkval->expr()->eq('table_name', $queryBuilderLinkval->createNamedParameter($table)),
                $queryBuilderLinkval->expr()->eq('field', $queryBuilderLinkval->createNamedParameter($field))
            )
            ->executeQuery();

        while ($brokenLinkRow = $resultLinkval->fetchAssociative()) {
            $link_type = (string)($brokenLinkRow['link_type'] ?? '');
            $link_title = (string)($brokenLinkRow['link_title'] ?? '');
            $url = (string)($brokenLinkRow['url'] ?? '');
            $found = false;
            foreach ($resultsLinks[$link_type] ?? [] as $hash => $values) {
                $link_title2 = (string)($values['link_title'] ?? '');
                $url2 = ($link_type === 'db') ? ($values['substr']['recordRef'] ?? '') : ($values['substr']['tokenValue'] ?? '');

                if ($url == $url2 && $link_title == $link_title2) {
                    $found = true;
                    unset($resultsLinks[$link_type][$hash]);
                    break;
                }
            }
            if (!$found) {
                GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable('tx_brofix_broken_links')
                    ->delete('tx_brofix_broken_links')
                    ->where($queryBuilderLinkval->expr()->eq('uid', $queryBuilderLinkval->createNamedParameter((int)$brokenLinkRow['uid'], Connection::PARAM_INT)))
                    ->executeStatement();
            }
        }

        $message = sprintf($this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.message.checked'), $header);
        return true;
    }

    protected function checkLinks(array $links, array $linkTypes, int $mode = 0): void
    {
        if (!$links || !$this->configuration || !$this->statistics) {
            return;
        }

        foreach ($this->configuration->getLinktypeObjects() as $key => $linktypeObject) {
            if (!($linktypeObject instanceof AbstractLinktype) || !is_array($links[$key] ?? false) || (!in_array($key, $linkTypes, true))) {
                continue;
            }

            foreach ($links[$key] as $entryKey => $entryValue) {
                $table = (string)($entryValue['table'] ?? '');
                $recordData = [];
                $rowFromEntry = $entryValue['row'] ?? [];

                $headline = BackendUtility::getProcessedValue(
                    $table,
                    $GLOBALS['TCA'][$table]['ctrl']['label'] ?? '',
                    $rowFromEntry[$GLOBALS['TCA'][$table]['ctrl']['label'] ?? ''] ?? '',
                    0, false, false, (int)($rowFromEntry['uid'] ?? 0), false
                );
                $recordData['headline'] = trim((string)$headline);

                $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? null;
                $recordData['language'] = ($languageField && isset($rowFromEntry[$languageField])) ? (int)$rowFromEntry[$languageField] : -1;

                $recordData['record_pid'] = (int)($rowFromEntry['pid'] ?? 0);
                $recordData['record_uid'] = (int)($entryValue['uid'] ?? 0); // Use 'uid' from entryValue directly
                $recordData['table_name'] = $table;
                $recordData['link_type'] = $key;
                $recordData['link_title'] = (string)($entryValue['link_title'] ?? '');
                $recordData['field'] = (string)($entryValue['field'] ?? '');
                $recordData['flexform_field'] = (string)($entryValue['flexformField'] ?? '');
                $recordData['flexform_field_label'] = (string)($entryValue['flexformFieldLabel'] ?? '');

                $typeField = $GLOBALS['TCA'][$table]['ctrl']['type'] ?? null;
                if ($typeField && isset($rowFromEntry[$typeField])) {
                    $recordData['element_type'] = (string)$rowFromEntry[$typeField];
                }
                $recordData['exclude_link_targets_pid'] = $this->configuration->getExcludeLinkTargetStoragePid();

                $pageWithAnchor = $entryValue['pageAndAnchor'] ?? '';
                $url = !empty($pageWithAnchor) ?
                    'pages:' . $pageWithAnchor :
                    (string)($entryValue['substr']['recordRef'] ?? ($entryValue['substr']['tokenValue'] ?? ''));
                $recordData['url'] = $url;

                $this->logger->debug("checkLinks: before checking $url");
                $linkTargetResponse = $linktypeObject->checkLink($url, $entryValue, $mode);

                if (!$linkTargetResponse instanceof LinkTargetResponse) {
                    $this->logger->debug("checkLinks: after checking $url: returned null or invalid response, no checking for this URL");
                    continue;
                }
                $this->logger->debug("checkLinks: after checking $url, status: " . $linkTargetResponse->getStatus());

                // This is the crucial part from the PR
                if ($linkTargetResponse->getReasonCannotCheck() === LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE) {
                    $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_UNKNOWN);
                    // Reason is already set by the linktype object (ExternalLinktype)
                }

                $this->statistics->incrementCountLinksByStatus($linkTargetResponse->getStatus());

                if ($linkTargetResponse->isError() || $linkTargetResponse->isCannotCheck() || $linkTargetResponse->getStatus() === LinkTargetResponse::RESULT_UNKNOWN) {
                    $recordData['url_response'] = $linkTargetResponse->toJson();
                    $recordData['check_status'] = $linkTargetResponse->getStatus();
                    $recordData['last_check_url'] = $linkTargetResponse->getLastChecked() ?: time();
                    $recordData['last_check'] = time();
                    if ($this->brokenLinkRepository->insertOrUpdateBrokenLink($recordData) && $linkTargetResponse->isError()) {
                        $this->statistics->incrementNewBrokenLink();
                    }
                } elseif ($this->configuration->isShowAllLinks()) {
                    $recordData['check_status'] = $linkTargetResponse->getStatus();
                    $recordData['url_response'] = $linkTargetResponse->toJson();
                    $recordData['last_check_url'] = $linkTargetResponse->getLastChecked() ?: time();
                    $recordData['last_check'] = time();
                    $this->brokenLinkRepository->insertOrUpdateBrokenLink($recordData);
                }
            }
        }
    }

    public function generateBrokenLinkRecords(ServerRequestInterface $request, array $linkTypes = [], bool $considerHidden = false): void
    {
        if (empty($linkTypes) || $this->pids === [] || !$this->configuration || !$this->statistics) {
            return;
        }

        $checkStart = time();
        $this->statistics->initialize();
        if ($this->pids) {
            $this->statistics->setCountPages(count($this->pids));
        }

        foreach ($this->searchFields as $table => $fields) {
            if (!isset($GLOBALS['TCA'][$table]) || !is_array($GLOBALS['TCA'][$table])) {
                continue;
            }

            $max = (int)($this->brokenLinkRepository->getMaxBindParameters() / 2 - 4);
            if ($max <=0) $max = 50; // Fallback if calculation is off

            foreach (array_chunk($this->pids ?? [], $max) as $pageIdsChunk) {
                if (empty($pageIdsChunk)) continue;

                $constraints = [];
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);

                if ($considerHidden) {
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                }

                $selectFields = $this->getSelectFields($table, $fields);

                if ($table === 'pages') {
                    $constraints[] = $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($pageIdsChunk, Connection::PARAM_INT_ARRAY));
                } else {
                    $constraints[] = $queryBuilder->expr()->in($table . '.pid', $queryBuilder->createNamedParameter($pageIdsChunk, Connection::PARAM_INT_ARRAY));
                    $queryBuilder->join($table, 'pages', 'p', $queryBuilder->expr()->eq('p.uid', $queryBuilder->quoteIdentifier($table . '.pid')));
                    foreach ($this->configuration->getDoNotCheckContentOnPagesDoktypes() as $doktype) {
                        $constraints[] = $queryBuilder->expr()->neq('p.doktype', $queryBuilder->createNamedParameter($doktype, Connection::PARAM_INT));
                    }

                    $tmpFields = [];
                    foreach ($selectFields as $field) {
                        $tmpFields[] = $table . '.' . $field . ' AS ' . $field; // Alias to avoid ambiguity if field name is 'pid' for example
                    }
                    $tmpFields[] = 'p.l18n_cfg';
                    $selectFieldsAliased = $tmpFields; // Use these for select
                }


                if ($this->configuration->getTcaProcessing() === Configuration::TCA_PROCESSING_FULL) {
                     $queryBuilder->select($table . '.*');
                     if ($table !== 'pages') $queryBuilder->addSelect('p.l18n_cfg'); // ensure l18n_cfg is selected
                } else {
                    $queryBuilder->select(...($selectFieldsAliased ?? $selectFields));
                }
                $queryBuilder->from($table)->where(...$constraints);

                $result = $queryBuilder->executeQuery();
                while ($row = $result->fetchAssociative()) {
                    $parsedLinks = [];
                    if ($this->isRecordsOnPageShouldBeChecked($table, $row) === false) {
                        continue;
                    }
                    $this->linkParser->findLinksForRecord(
                        $parsedLinks,
                        $table,
                        $fields, // Original field names, not aliased
                        $row,
                        $request,
                        LinkParser::MASK_CONTENT_CHECK_ALL - LinkParser::MASK_CONTENT_CHECK_IF_RECORDS_ON_PAGE_SHOULD_BE_CHECKED
                    );
                    $this->checkLinks($parsedLinks, $linkTypes);
                }
            }
        }

        if ($this->pids) {
            $this->brokenLinkRepository->removeAllBrokenLinksForPagesBeforeTime($this->pids, $linkTypes, $checkStart);
        }
        $this->statistics->calculateStats();
    }

    protected function getSelectFields(string $table, array $selectFields = []): array
    {
        $defaultFields = ['uid', 'pid'];
        if ($GLOBALS['TCA'][$table]['ctrl']['versioningWS'] ?? false) {
            $defaultFields[] = 't3ver_wsid';
        }
        if (isset($GLOBALS['TCA'][$table]['ctrl']['label'])) {
            $defaultFields[] = $GLOBALS['TCA'][$table]['ctrl']['label'];
        }
        if (isset($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
            $defaultFields[] = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
        }
        if (isset($GLOBALS['TCA'][$table]['ctrl']['type'])) {
            $defaultFields[] = $GLOBALS['TCA'][$table]['ctrl']['type'];
        }
        if ($table === 'tt_content') {
            $defaultFields[] = 'colPos';
            $defaultFields[] = 'list_type';
        }
        $cleanedSelectFields = [];
        foreach ($selectFields as $field) {
            if (isset($GLOBALS['TCA'][$table]['columns'][$field])) {
                 $cleanedSelectFields[] = $field;
            }
        }
        return array_unique(array_merge($defaultFields, $cleanedSelectFields));
    }

    public function isRecordsOnPageShouldBeChecked(string $table, array $record): bool
    {
        if ($table === 'pages') {
            return true;
        }
        $pageUid = (int)($record['pid'] ?? 0);
        if ($pageUid === 0) {
            return false;
        }

        // If l18n_cfg was joined, it should be in $record directly.
        // Otherwise, fetch the page row.
        $pageRow = $record; // Assume p.l18n_cfg might be in $record if joined.
        if (!isset($record['l18n_cfg'])) { // If not joined or table is 'pages' (though 'pages' returns true above)
            $_pageRow = BackendUtility::getRecord('pages', $pageUid, 'doktype, l18n_cfg, hidden, extendToSubpages');
            if (!$_pageRow) return false;
            $pageRow = array_merge($record, $_pageRow); // Merge to ensure all fields are available
        }


        $doktype = (int)($pageRow['doktype'] ?? 0);
        if (in_array($doktype, [3, 4])) { // Spacer, SysFolder often don't need content checks
            return false;
        }
        $l18nCfg = (int)($pageRow['l18n_cfg'] ?? 0);
        $languageField = $GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '';
        $lang = 0;
        if ($languageField) {
            $lang = (int)($record[$languageField] ?? 0);
        }
        if ((($l18nCfg & 1) === 1) && $lang === 0) { // Hide default language if l18n_cfg bit 0 is set
            return false;
        }

        // Check if page is hidden in rootline (simplified, actual PagesRepository might have more robust check)
        if (($pageRow['hidden'] ?? 0) || ($pageRow['extendToSubpages'] ?? 0)) {
             // This is a simplified check. A full rootline check is more complex.
             // The original PagesRepository::getRootLineIsHidden would be more accurate.
             // For now, if the page itself is hidden, we might skip.
        }
        // Delegate to PagesRepository for accurate hidden status if available and necessary
        if ($this->pagesRepository->getRootLineIsHidden($pageRow)) { // Assuming $pageRow has enough info for this
             return false;
        }

        return true;
    }

    protected function countLinks(array $links): int
    {
        $count = 0;
        foreach ($links as $key => $values) {
            if (is_array($values)) {
                $count += count($values);
            }
        }
        return $count;
    }

    public function getStatistics(): ?CheckLinksStatistics
    {
        return $this->statistics;
    }

    protected function getLanguageService(): LanguageService
    {
        if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        // Fallback for CLI or other contexts, though $GLOBALS['LANG'] is typical in BE.
        return GeneralUtility::makeInstance(LanguageService::class);
    }
}
