<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Parser;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use Gaumondp\PguBrofixExtras\Linktype\AbstractLinktype;
use Gaumondp\PguBrofixExtras\Parser\SoftReference\TypolinkRecordTagSoftReferenceParser; // Will need to adapt/create this
use Gaumondp\PguBrofixExtras\Repository\ContentRepository; // Will need to adapt/create this
use Gaumondp\PguBrofixExtras\Util\TcaUtil; // Will need to adapt/create this
use TYPO3\CMS\Backend\Form\Exception\DatabaseDefaultLanguageException;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroupInterface;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserInterface;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserResult;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Parse content for links.
 * @internal
 */
class LinkParser implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const MASK_CONTENT_CHECK_IF_EDITABLE_FIELD = 1;
    public const MASK_CONTENT_CHECK_IF_RECORD_SHOULD_BE_CHECKED = 2;
    public const MASK_CONTENT_CHECK_IF_RECORDS_ON_PAGE_SHOULD_BE_CHECKED = 4; // Corrected typo from RECORDs to RECORDS
    public const MASK_CONTENT_CHECK_ALL = 0xff;

    protected ?ServerRequestInterface $request = null;
    protected ?FormDataCompiler $formDataCompiler = null;
    protected ?FormDataGroupInterface $formDataGroup = null;
    protected ?Configuration $configuration = null;
    protected SoftReferenceParserFactory $softReferenceParserFactory;
    protected ContentRepository $contentRepository;

    /** @var array<mixed> */
    protected array $processedFormData = [];

    public function __construct(
        ?SoftReferenceParserFactory $softReferenceParserFactory = null,
        ?ContentRepository $contentRepository = null,
        ?Configuration $configuration = null // Allow injecting Configuration
    ) {
        $this->softReferenceParserFactory = $softReferenceParserFactory ?: GeneralUtility::makeInstance(SoftReferenceParserFactory::class);
        $this->contentRepository = $contentRepository ?: GeneralUtility::makeInstance(ContentRepository::class);
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));

        if ($configuration) {
            $this->setConfiguration($configuration);
        }
    }

    // Removed static instance and initialize method to prefer dependency injection or direct instantiation.
    // If used as a singleton, it should be managed by a DI container.

    public function setConfiguration(Configuration $configuration): void
    {
        $this->configuration = $configuration;
        $formDataGroupClass = $this->configuration->getFormDataGroup();
        if ($formDataGroupClass && class_exists($formDataGroupClass)) {
            $this->formDataGroup = GeneralUtility::makeInstance($formDataGroupClass);
            if ($this->formDataGroup instanceof FormDataGroupInterface) {
                $this->formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class);
            } else {
                $this->formDataGroup = null; // Ensure it's null if not the correct type
            }
        } else {
            $this->formDataGroup = null;
            $this->formDataCompiler = null;
        }
    }

    /**
     * @param array<mixed> $results
     * @param array<string> $fields
     * @param array<mixed> $record
     * @return array<string>
     * @throws \Throwable
     */
    public function findLinksForRecord(
        array &$results,
        string $table,
        array $fields,
        array $record,
        ServerRequestInterface $request,
        int $checks = self::MASK_CONTENT_CHECK_ALL
    ): array {
        $this->request = $request;
        $idRecord = (int)($record['uid'] ?? 0);

        if (!$this->configuration) {
            $this->logger->error("LinkParser configuration not initialized for table {$table}, uid {$idRecord}.");
            return [];
        }

        try {
            $htmlParser = GeneralUtility::makeInstance(HtmlParser::class);

            if (($checks & self::MASK_CONTENT_CHECK_IF_RECORD_SHOULD_BE_CHECKED) &&
                !$this->isRecordShouldBeChecked($table, $record)) {
                return [];
            }

            $processedFormData = [];
            if ($this->formDataCompiler && $this->formDataGroup) { // Check if compiler and group are available
                 $processedFormData = $this->getProcessedFormData($idRecord, $table, $request);
            }


            if (($checks & self::MASK_CONTENT_CHECK_IF_EDITABLE_FIELD) && !empty($processedFormData)) {
                $fields = $this->getEditableFields($idRecord, $table, $fields, $processedFormData);
            }

            if (empty($fields)) {
                return [];
            }

            $tableTca = !empty($processedFormData['processedTca']) ? $processedFormData['processedTca'] : ($GLOBALS['TCA'][$table] ?? []);

            foreach ($fields as $field) {
                if (!isset($tableTca['columns'][$field]['config'])) continue;

                $fieldConfig = $tableTca['columns'][$field]['config'];
                $valueField = htmlspecialchars_decode((string)($record[$field] ?? ''));
                if ($valueField === '') {
                    continue;
                }

                $type = $fieldConfig['type'] ?? '';
                if ($type === 'flex') {
                    $flexformFields = TcaUtil::getFlexformFieldsWithConfig($table, $field, $record, $fieldConfig); // Assumes TcaUtil is adapted
                    foreach ($flexformFields as $flexformFieldKey => $flexformData) {
                        $flexValue = htmlspecialchars_decode((string)($flexformData['value'] ?? ''));
                        $flexConfig = $flexformData['config'] ?? [];
                        if (empty($flexValue) || empty($flexConfig) || !is_array($flexConfig)) {
                            continue;
                        }
                        $softrefParserList = $this->getSoftrefParserListByField($table, $field . '.' . $flexformFieldKey, $flexConfig);
                        foreach ($softrefParserList as $softReferenceParser) {
                            $parserResult = $softReferenceParser->parse($table, $field, $idRecord, $flexValue); // Pass $flexValue
                            if (!$parserResult->hasMatched()) continue;
                            $this->processParserResult($parserResult, $results, $htmlParser, $record, $field, $table, $flexformFieldKey, (string)($flexformData['label'] ?? ''));
                        }
                    }
                } else {
                    $softrefParserList = $this->getSoftrefParserListByField($table, $field, $fieldConfig);
                    foreach ($softrefParserList as $softReferenceParser) {
                        $parserResult = $softReferenceParser->parse($table, $field, $idRecord, $valueField);
                        if (!$parserResult->hasMatched()) continue;
                        $this->processParserResult($parserResult, $results, $htmlParser, $record, $field, $table);
                    }
                }
            }
        } catch (DatabaseDefaultLanguageException $e) {
            $this->logger->error("analyzeRecord: table=$table, uid=$idRecord, DatabaseDefaultLanguageException: {$e->getMessage()}");
        } catch (\Throwable $e) {
            $this->logger->error("analyzeRecord: table=$table, uid=$idRecord, exception={$e->getMessage()}, trace: {$e->getTraceAsString()}");
            throw $e;
        }
        return $fields;
    }

    protected function processParserResult(
        SoftReferenceParserResult $parserResult,
        array &$results,
        HtmlParser $htmlParser,
        array $record,
        string $field,
        string $table,
        string $flexformFieldKey = '',
        string $flexformFieldLabel = ''
    ): void {
        $parserKey = $parserResult->getParser()->getParserKey(); // Assumes getParser() method exists on result or parser is accessible
        if ($parserKey === 'rtehtmlarea_images') { // Example: skip image softrefs if not needed
            return;
        }
        // The original code had specific handling for 'typolink_tag'.
        // We assume TypolinkRecordTagSoftReferenceParser might handle 'typolink_tag_record'
        if (in_array($parserKey, ['typolink_tag', 'typolink_tag_record'], true)) {
            $this->analyzeTypoLinks($parserResult, $results, $htmlParser, $record, $field, $table, $flexformFieldKey, $flexformFieldLabel);
        } else {
            $this->analyzeLinks($parserResult, $results, $record, $field, $table, $flexformFieldKey, $flexformFieldLabel);
        }
    }


    /**
     * @return iterable<SoftReferenceParserInterface>
     */
    public function getSoftrefParserListByField(string $table, string $fieldName, array $fieldConfig): iterable
    {
        if(!$this->configuration){
            return [];
        }
        $softrefParserKeys = [];
        $softref = GeneralUtility::trimExplode(',', (string)($fieldConfig['softref'] ?? ''), true);

        if (!empty($softref)) {
            $softrefParserKeys = $softref;
            $excludeSoftrefsInFieldsSetting = $this->configuration->getExcludeSoftrefsInFields();
            $fullFieldName = $table . '.' . $fieldName;
            if (in_array($fullFieldName, $excludeSoftrefsInFieldsSetting, true)) {
                $softrefParserKeys = array_diff($softrefParserKeys, $this->configuration->getExcludeSoftrefs());
            }
        } elseif (!empty($fieldConfig['enableRichtext'])) {
            $softrefParserKeys = ['typolink_tag'];
        } else {
            $type = $fieldConfig['type'] ?? '';
            switch ($type) {
                case 'link':
                    $softrefParserKeys = ['typolink'];
                    break;
                case 'input':
                    if (($fieldConfig['renderType'] ?? '') === 'inputLink') {
                        $softrefParserKeys = ['typolink'];
                    } else {
                        return [];
                    }
                    break;
                default:
                    return [];
            }
        }

        if (empty($softrefParserKeys)) {
            return [];
        }

        // Ensure 'typolink_tag_record' parser is available if 'typolink_tag' is used
        if (in_array('typolink_tag', $softrefParserKeys, true) && !in_array('typolink_tag_record', $softrefParserKeys, true)) {
            if ($this->softReferenceParserFactory->hasParser('typolink_tag_record') || class_exists(TypolinkRecordTagSoftReferenceParser::class)) {
                 $this->softReferenceParserFactory->addParser(
                     GeneralUtility::makeInstance(TypolinkRecordTagSoftReferenceParser::class), // Ensure this class is adapted
                     'typolink_tag_record'
                 );
                 $softrefParserKeys[] = 'typolink_tag_record';
            }
        }
        return $this->softReferenceParserFactory->getParsersBySoftRefParserList(implode(',', $softrefParserKeys), ['subst']);
    }

    /**
     * @param array<mixed> $results
     * @param array<mixed> $record
     */
    protected function analyzeLinks(
        SoftReferenceParserResult $parserResult,
        array &$results,
        array $record,
        string $field,
        string $table,
        string $flexformField = '',
        string $flexformFieldLabel = ''
    ): void {
        $idRecord = (int)($record['uid'] ?? 0);

        foreach ($parserResult->getMatchedElements() as $element) {
            $currentR = $element['subst'] ?? [];
            if (empty($currentR) || !is_array($currentR)) {
                continue;
            }

            $type = ''; // Determine type based on $currentR
            /** @var AbstractLinktype $linktypeObject */
            foreach ($this->configuration->getLinktypeObjects() as $key => $linktypeObject) {
                $type = $linktypeObject->fetchType($currentR, $type, $key);
                if (!empty($type)) {
                    $currentR['type'] = $type; // Store determined type
                    break;
                }
            }
            if (empty($type)) continue; // Skip if no type could be determined

            $pageAndAnchor = '';
            if (isset($currentR['recordRef']) && strpos((string)$currentR['recordRef'], 'pages') !== false) {
                 $pageAndAnchor = (string)($currentR['tokenValue'] ?? '');
                 // Check for subsequent content element anchor
                 // This part of logic was a bit complex in original, needs careful re-evaluation if tt_content anchors are linked to page links
            }


            $uniqueKey = $table . ':' . $field . ':' . $flexformField . ':' . $idRecord . ':' . ($currentR['tokenID'] ?? crc32(serialize($currentR)));
            $results[$type][$uniqueKey] = [
                'substr' => $currentR,
                'row' => $record,
                'table' => $table,
                'field' => $field,
                'flexformField' => $flexformField,
                'flexformFieldLabel' => $flexformFieldLabel,
                'uid' => $idRecord,
                'pageAndAnchor' => $pageAndAnchor,
                'link_title' => '', // analyzeTypoLinks handles title extraction differently
            ];
        }
    }

    /**
     * @param array<mixed> $results
     * @param array<mixed> $record
     */
    protected function analyzeTypoLinks(
        SoftReferenceParserResult $parserResult,
        array &$results,
        HtmlParser $htmlParser,
        array $record,
        string $field,
        string $table,
        string $flexformField = '',
        string $flexformFieldLabel = ''
    ): void {
        $idRecord = (int)($record['uid'] ?? 0);
        $linkTags = $htmlParser->splitIntoBlock('a,link', $parserResult->getContent()); // Assuming getContent() exists
        $countLinkTags = count($linkTags);

        for ($i = 1; $i < $countLinkTags; $i += 2) {
            $tagContent = $linkTags[$i-1]; // Content before the tag (potential tokenID context)
            $anchorTag = $linkTags[$i];   // The <a ...> or <link ...> tag itself
            $linkText = strip_tags($anchorTag); // Text content of the link

            $currentR = null; // To store the matched soft reference for this tag
            $pageAndAnchor = '';

            // Find which soft reference corresponds to this tag
            foreach ($parserResult->getMatchedElements() as $element) {
                $_currentR = $element['subst'] ?? [];
                if (empty($_currentR['tokenID']) || strpos($tagContent, (string)$_currentR['tokenID']) === false && strpos($anchorTag, (string)$_currentR['tokenID']) === false) {
                    // Check if tokenID is in the anchor tag itself (e.g. <a href="t3://page?uid=1&token=TOKEN_ID">)
                    // or in the content immediately preceding it if parser places it there.
                    continue;
                }
                $currentR = $_currentR; // Found the softref for this tag

                if (isset($currentR['recordRef']) && strpos((string)$currentR['recordRef'], 'pages') !== false) {
                    $pageAndAnchor = (string)($currentR['tokenValue'] ?? '');
                    // Potentially look for #c<UID> in href for content element anchors
                    if (preg_match('/#c(\d+)/', $anchorTag, $matchesAnchor)) {
                        $pageAndAnchor .= '#c' . $matchesAnchor[1];
                    }
                }
                break;
            }

            if (empty($currentR) || !is_array($currentR)) {
                continue;
            }

            $type = '';
            /** @var AbstractLinktype $linktypeObject */
            foreach ($this->configuration->getLinktypeObjects() as $key => $linktypeObject) {
                $type = $linktypeObject->fetchType($currentR, $type, $key);
                if (!empty($type)) {
                    $currentR['type'] = $type;
                    break;
                }
            }
             if (empty($type)) continue;


            $uniqueKey = $table . ':' . $field . ':' . $flexformField . ':' . $idRecord . ':' . ($currentR['tokenID'] ?? crc32(serialize($currentR)));
            $results[$type][$uniqueKey] = [
                'substr' => $currentR,
                'row' => $record,
                'table' => $table,
                'field' => $field,
                'flexformField' => $flexformField,
                'flexformFieldLabel' => $flexformFieldLabel,
                'uid' => $idRecord,
                'link_title' => $linkText,
                'pageAndAnchor' => $pageAndAnchor,
            ];
        }
    }


    public function isRecordShouldBeChecked(string $tableName, array $row): bool
    {
        if (!$this->configuration) return false;

        if (!$this->isVisibleFrontendRecord($tableName, $row)) {
            return false;
        }

        if ($tableName === 'tt_content') {
            $excludedCtypes = $this->configuration->getExcludedCtypes();
            if (!empty($excludedCtypes) && isset($row['CType']) && in_array($row['CType'], $excludedCtypes, true)) {
                return false;
            }
        }

        if ($this->configuration->getDoNotCheckLinksOnWorkspace()) {
            if ((int)($row['t3ver_wsid'] ?? 0) !== 0) {
                return false;
            }
        }
        return true;
    }

    /** @return array<mixed> */
    public function getProcessedFormData(int $uid, string $tableName, ServerRequestInterface $request): array
    {
        if (!$this->formDataCompiler || !$this->formDataGroup) {
            return [];
        }

        $originalIsAdmin = $GLOBALS['BE_USER']->isAdmin();
        if (!$originalIsAdmin) {
            $GLOBALS['BE_USER']->user['admin'] = 1; // Temporarily elevate to admin
        }

        $formDataCompilerInput = [
            'tableName' => $tableName,
            'vanillaUid' => $uid, // In TYPO3 v12, this might need to be 'recordUid' or similar
            'command' => 'edit', // Or another relevant command
            'request' => $request, // Pass the current request
        ];
        $this->processedFormData = $this->formDataCompiler->compile($formDataCompilerInput, $this->formDataGroup);

        if (!$originalIsAdmin) {
            $GLOBALS['BE_USER']->user['admin'] = 0; // Restore original admin status
        }
        return $this->processedFormData;
    }

    /**
     * @param string[] $fields
     * @param array<mixed> $processedFormData
     * @return string[]
     */
    public function getEditableFields(int $uid, string $tableName, array $fields, array $processedFormData): array
    {
        if (empty($fields)) return [];
        $columns = $processedFormData['processedTca']['columns'] ?? [];
        if (empty($columns)) return $fields; // No TCA info, assume all passed fields are editable or return original

        $editableFields = [];
        foreach ($fields as $field) {
            if (isset($columns[$field])) { // Field exists in TCA
                $editableFields[] = $field;
            }
        }
        return $editableFields;
    }

    /** @param array<mixed> $row */
    public function isVisibleFrontendRecord(string $tableName, array $row): bool
    {
        if (!empty($row['hidden'])) {
            return false;
        }
        // Simplified gridelements check. A real check might need to query parent visibility.
        if ($tableName === 'tt_content' && ((int)($row['colPos'] ?? 0)) === -1 && ExtensionManagementUtility::isLoaded('gridelements')) {
            // This check was: $this->contentRepository->isGridElementParentHidden($uid)
            // For now, assume if it's in colPos -1 and gridelements is active, it might be complex.
            // A proper check would involve querying the parent gridelement record.
            // For this adaptation, we might assume it's visible if not explicitly hidden.
        }
        return true;
    }
}
