<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Configuration;

use Symfony\Component\Mime\Address;
use Gaumondp\PguBrofixExtras\FormEngine\FieldShouldBeChecked; // Will need to adapt/create this
use Gaumondp\PguBrofixExtras\FormEngine\FieldShouldBeCheckedWithFlexform; // Will need to adapt/create this
use Gaumondp\PguBrofixExtras\Linktype\LinktypeInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration; // Alias to avoid conflict
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;

class Configuration
{
    public const SEND_EMAIL_NEVER = 'never';
    public const SEND_EMAIL_ALWAYS = 'always';
    public const SEND_EMAIL_ANY = 'any';
    public const SEND_EMAIL_NEW = 'new';
    public const SEND_EMAIL_AUTO = 'auto';
    public const SEND_EMAIL_DEFAULT_VALUE = self::SEND_EMAIL_AUTO;
    public const SEND_EMAIL_AVAILABLE_VALUES = [
        self::SEND_EMAIL_NEVER, self::SEND_EMAIL_ALWAYS, self::SEND_EMAIL_ANY,
        self::SEND_EMAIL_NEW, self::SEND_EMAIL_AUTO
    ];

    public const SHOW_EDIT_BUTTONS_EDIT_FIELD = 'field';
    public const SHOW_EDIT_BUTTONS_EDIT_FULL = 'full';
    public const SHOW_EDIT_BUTTONS_BOTH = 'both';
    public const SHOW_EDIT_BUTTONS_DEFAULT_VALUE = self::SHOW_EDIT_BUTTONS_BOTH;

    public const TRAVERSE_MAX_NUMBER_OF_PAGES_IN_BACKEND_DEFAULT = 1000;
    public const DEFAULT_TSCONFIG = [
        'searchFields.' => [
            'pages' => 'media,url',
            'tt_content' => 'bodytext,header_link,records',
        ],
        'excludeCtype' => 'html',
        'linktypes' => 'db,file,external,applewebdata',
        'check.' => [
            'doNotCheckContentOnPagesDoktypes' => '3,4',
            'doNotCheckPagesDoktypes' => '6,7,199,255',
            'doNotTraversePagesDoktypes' => '6,199,255',
            'doNotCheckLinksOnWorkspace' => false, // Should be 0 or 1 for TYPO3 context
        ],
        'checkhidden' => false, // Should be 0 or 1
        'depth' => 999,
        'reportHiddenRecords' => true, // Should be 0 or 1
        'linktypesConfig.' => [
            'external.' => [
                'headers.' => [
                    'User-Agent' => '', // Will be replaced by default User-Agent if empty
                    'Accept' => '*/*'
                ],
                'timeout' => 10,
                'connect_timeout' => 5, // Added default
                'ssl_verify_peer' => true, // Added default
                'redirects' => 5,
            ]
        ],
        'excludeLinkTarget.' => [
            'storagePid' => 0,
            'allowed' => 'external',
        ],
        'linkTargetCache.' => [
            'expiresLow' => 604800,  // 7 days
            'expiresHigh' => 691200, // 8 days
        ],
        'crawlDelay.' => [
            'seconds' => 5,
            'nodelay' => '',
        ],
        'report.' => [
            'docsurl' => '', // URL to documentation
            'recheckButton' => -1, // Depth at which recheck button is shown (-1 means always for admin, or up to this depth)
        ],
        'mail.' => [
            'sendOnCheckLinks' => self::SEND_EMAIL_AUTO, // Use constant
            'recipients' => '',
            'fromname' => '',
            'fromemail' => '',
            'replytoname' => '',
            'replytoemail' => '',
            'subject' => '',
            'template' => 'CheckLinksResults', // Fluid template name
            'language' => 'en',
        ],
        'custom.' => []
    ];

    public const TCA_PROCESSING_NONE = 'none';
    public const TCA_PROCESSING_DEFAULT = 'default';
    public const TCA_PROCESSING_FULL = 'full';
    protected const TCA_PROCESSING_DEFAULT_VALUE = self::TCA_PROCESSING_DEFAULT;


    /** @var array<mixed> */
    protected array $tsConfig = self::DEFAULT_TSCONFIG;
    protected int $traverseMaxNumberOfPagesInBackend = 0;
    protected bool $showAllLinks = true;
    protected string $combinedErrorNonCheckableMatch = 'regex:/^httpStatusCode:(401|403):/';
    /** @var string[] */
    protected array $excludeSoftrefs = [];
    /** @var string[] */
    protected array $excludeSoftrefsInFields = [];
    /** @var array<string,LinktypeInterface> */
    protected array $hookObjectsArr = [];
    protected string $tcaProcessing = self::TCA_PROCESSING_DEFAULT_VALUE;
    protected string $overrideFormDataGroup = '';
    protected string $showEditButtons = self::SHOW_EDIT_BUTTONS_DEFAULT_VALUE;
    protected bool $showPageLayoutButton = true;
    protected bool $recheckLinksOnEditing = false;

    public function __construct()
    {
        $extConfArray = GeneralUtility::makeInstance(CoreExtensionConfiguration::class)->get('pgu_brofix_extras') ?? [];

        $this->recheckLinksOnEditing = (bool)($extConfArray['recheckLinksOnEditing'] ?? false);
        $this->showAllLinks = (bool)($extConfArray['showalllinks'] ?? true);
        $this->showEditButtons = (string)($extConfArray['showEditButtons'] ?? self::SHOW_EDIT_BUTTONS_DEFAULT_VALUE);
        $this->showPageLayoutButton = (bool)($extConfArray['showPageLayoutButton'] ?? true);
        $this->combinedErrorNonCheckableMatch = (string)($extConfArray['combinedErrorNonCheckableMatch'] ?? 'regex:/^httpStatusCode:(401|403):/');
        $this->excludeSoftrefs = GeneralUtility::trimExplode(',', (string)($extConfArray['excludeSoftrefs'] ?? ''), true);
        $this->excludeSoftrefsInFields = GeneralUtility::trimExplode(',', (string)($extConfArray['excludeSoftrefsInFields'] ?? ''), true);
        $this->setTraverseMaxNumberOfPagesInBackend(
            (int)($extConfArray['traverseMaxNumberOfPagesInBackend'] ?? self::TRAVERSE_MAX_NUMBER_OF_PAGES_IN_BACKEND_DEFAULT)
        );
        $this->tcaProcessing = (string)($extConfArray['tcaProcessing'] ?? self::TCA_PROCESSING_DEFAULT_VALUE);
        $this->overrideFormDataGroup = (string)($extConfArray['overrideFormDataGroup'] ?? '');

        // Initialize hook objects (linktypes)
        // This part depends on how linktypes are registered in Brofix.
        // Assuming they are registered via $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['pgu_brofix_extras']['checkLinks']
        // For a self-contained pgu_brofix_extras, these would be defined within this extension.
        $checkLinksConf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['pgu_brofix_extras']['checkLinks'] ?? [];
        if (empty($checkLinksConf)) { // Default linktypes if none are configured via global conf
            $checkLinksConf = [
                'external' => \Gaumondp\PguBrofixExtras\Linktype\ExternalLinktype::class,
                // Add other default linktypes like 'db', 'file' if they are part of pgu_brofix_extras
                // 'db' => \Gaumondp\PguBrofixExtras\Linktype\InternalLinktype::class,
                // 'file' => \Gaumondp\PguBrofixExtras\Linktype\FileLinktype::class,
            ];
        }

        foreach ($checkLinksConf as $key => $className) {
            if (class_exists($className)) {
                $linktype = GeneralUtility::makeInstance($className);
                if ($linktype instanceof LinktypeInterface) {
                    $this->hookObjectsArr[$key] = $linktype;
                    $linktype->setConfiguration($this);
                }
            }
        }
    }

    /** @param array<mixed> $tsConfig */
    public function setTsConfig(array $tsConfig): void
    {
        $this->tsConfig = $tsConfig;
    }

    public function loadPageTsConfig(int $pageUid): void
    {
        if ($pageUid > 0) {
            $pageTsConfig = BackendUtility::getPagesTSconfig($pageUid);
            $this->setTsConfig($pageTsConfig['mod.']['tx_pgubrofuxextras.'] ?? $pageTsConfig['mod.']['brofix.'] ?? []);
        } else {
             $this->setTsConfig([]); // No page, no TSconfig
        }
    }

    /** @param array<mixed> $override */
    public function overrideTsConfigByArray(array $override): void
    {
        ArrayUtility::mergeRecursiveWithOverrule($this->tsConfig, $override);
    }

    /** @return array<mixed> */
    public function getTsConfig(): array
    {
        return $this->tsConfig;
    }

    /** @param array<string,array<string>> $searchFields */
    public function setSearchFields(array $searchFields): void
    {
        unset($this->tsConfig['searchFields.']);
        foreach ($searchFields as $table => $fields) {
            $this->tsConfig['searchFields.'][$table] = implode(',', $fields);
        }
    }

    /** @return array<string,array<string>> */
    public function getSearchFields(): array
    {
        $searchFields = [];
        foreach ($this->tsConfig['searchFields.'] ?? self::DEFAULT_TSCONFIG['searchFields.'] as $table => $fieldList) {
            $fields = GeneralUtility::trimExplode(',', (string)$fieldList, true);
            foreach ($fields as $field) {
                $searchFields[$table][] = $field;
            }
        }
        return $searchFields;
    }

    /** @return array<string> */
    public function getExcludedCtypes(): array
    {
        return GeneralUtility::trimExplode(',', (string)($this->tsConfig['excludeCtype'] ?? self::DEFAULT_TSCONFIG['excludeCtype']), true);
    }

    /** @param array<int|string,string> $linkTypes */
    public function setLinkTypes(array $linkTypes): void
    {
        $this->tsConfig['linktypes'] = implode(',', $linkTypes);
    }

    /** @return array<string> */
    public function getLinkTypes(): array
    {
        return GeneralUtility::trimExplode(',', (string)($this->tsConfig['linktypes'] ?? self::DEFAULT_TSCONFIG['linktypes']), true);
    }

    /** @return array<int,int> */
    public function getDoNotCheckContentOnPagesDoktypes(): array
    {
        $doktypesStr = (string)($this->tsConfig['check.']['doNotCheckContentOnPagesDoktypes'] ?? self::DEFAULT_TSCONFIG['check.']['doNotCheckContentOnPagesDoktypes']);
        $doktypes = GeneralUtility::intExplode(',', $doktypesStr);
        return array_combine($doktypes, $doktypes) ?: [];
    }

    /** @return array<int,int> */
    public function getDoNotCheckPagesDoktypes(): array
    {
        $doktypesStr = (string)($this->tsConfig['check.']['doNotCheckPagesDoktypes'] ?? self::DEFAULT_TSCONFIG['check.']['doNotCheckPagesDoktypes']);
        $doktypes = GeneralUtility::intExplode(',', $doktypesStr);
        return array_combine($doktypes, $doktypes) ?: [];
    }

    /** @return array<int,int> */
    public function getDoNotTraversePagesDoktypes(): array
    {
        $doktypesStr = (string)($this->tsConfig['check.']['doNotTraversePagesDoktypes'] ?? self::DEFAULT_TSCONFIG['check.']['doNotTraversePagesDoktypes']);
        $doktypes = GeneralUtility::intExplode(',', $doktypesStr);
        return array_combine($doktypes, $doktypes) ?: [];
    }

    public function getDoNotCheckLinksOnWorkspace(): bool
    {
        return (bool)($this->tsConfig['check.']['doNotCheckLinksOnWorkspace'] ?? self::DEFAULT_TSCONFIG['check.']['doNotCheckLinksOnWorkspace']);
    }

    public function isCheckHidden(): bool
    {
        return (bool)($this->tsConfig['checkhidden'] ?? self::DEFAULT_TSCONFIG['checkhidden']);
    }

    public function isReportHiddenRecords(): bool
    {
        return (bool)($this->tsConfig['reportHiddenRecords'] ?? self::DEFAULT_TSCONFIG['reportHiddenRecords']);
    }

    /** @return array<mixed> */
    public function getLinktypesConfig(string $linktype): array
    {
        return $this->tsConfig['linktypesConfig.'][$linktype . '.'] ?? self::DEFAULT_TSCONFIG['linktypesConfig.'][$linktype . '.'] ?? [];
    }

    /** @return array<mixed> */
    public function getLinktypesConfigExternalHeaders(): array
    {
        $defaultHeaders = self::DEFAULT_TSCONFIG['linktypesConfig.']['external.']['headers.'] ?? [];
        $headers = $this->tsConfig['linktypesConfig.']['external.']['headers.'] ?? $defaultHeaders;
        if (empty($headers['User-Agent'])) {
            $headers['User-Agent'] = $this->getUserAgent();
        }
        return $headers;
    }

    public function getUserAgent(): string
    {
        $configuredUA = $this->tsConfig['linktypesConfig.']['external.']['headers.']['User-Agent'] ?? '';
        if (!empty($configuredUA)) {
            return $configuredUA;
        }
        $systemFrom = MailUtility::getSystemFromAddress();
        return 'Mozilla/5.0 (compatible; TYPO3 PGU Brofix Extras link checker/1.0; +' . ($systemFrom ?: 'https://typo3.org') . ')';
    }

    public function getLinktypesConfigExternalTimeout(): int
    {
        return (int)($this->tsConfig['linktypesConfig.']['external.']['timeout'] ?? self::DEFAULT_TSCONFIG['linktypesConfig.']['external.']['timeout']);
    }
    public function getLinktypesConfigExternalConnectTimeout(): int
    {
        return (int)($this->tsConfig['linktypesConfig.']['external.']['connect_timeout'] ?? self::DEFAULT_TSCONFIG['linktypesConfig.']['external.']['connect_timeout']);
    }
    public function isLinktypesConfigExternalSslVerifyPeer(): bool
    {
        return (bool)($this->tsConfig['linktypesConfig.']['external.']['ssl_verify_peer'] ?? self::DEFAULT_TSCONFIG['linktypesConfig.']['external.']['ssl_verify_peer']);
    }


    public function getLinktypesConfigExternalRedirects(): int
    {
        return (int)($this->tsConfig['linktypesConfig.']['external.']['redirects'] ?? self::DEFAULT_TSCONFIG['linktypesConfig.']['external.']['redirects']);
    }

    public function getExcludeLinkTargetStoragePid(): int
    {
        return (int)($this->tsConfig['excludeLinkTarget.']['storagePid'] ?? self::DEFAULT_TSCONFIG['excludeLinkTarget.']['storagePid']);
    }

    /** @return array<string> */
    public function getExcludeLinkTargetAllowedTypes(): array
    {
        return GeneralUtility::trimExplode(',', (string)($this->tsConfig['excludeLinkTarget.']['allowed'] ?? self::DEFAULT_TSCONFIG['excludeLinkTarget.']['allowed']), true);
    }

    public function getLinkTargetCacheExpires(int $flags = 0): int
    {
        $defaultLow = self::DEFAULT_TSCONFIG['linkTargetCache.']['expiresLow'];
        $defaultHigh = self::DEFAULT_TSCONFIG['linkTargetCache.']['expiresHigh'];
        if ($flags & \Gaumondp\PguBrofixExtras\Linktype\AbstractLinktype::CHECK_LINK_FLAG_SYNCHRONOUS) {
            return (int)($this->tsConfig['linkTargetCache.']['expiresHigh'] ?? $defaultHigh);
        }
        return (int)($this->tsConfig['linkTargetCache.']['expiresLow'] ?? $defaultLow);
    }

    public function getCrawlDelaySeconds(): int
    {
        return (int)($this->tsConfig['crawlDelay.']['seconds'] ?? self::DEFAULT_TSCONFIG['crawlDelay.']['seconds']);
    }

    /** @return array<string> */
    public function getCrawlDelayNodelayDomains(): array
    {
        $noDelayString = $this->getCrawlDelayNodelayString();
        if (str_starts_with($noDelayString, 'regex:')) {
            return [];
        }
        return GeneralUtility::trimExplode(',', $noDelayString, true);
    }

    public function isCrawlDelayNoDelayRegex(): bool
    {
        return str_starts_with($this->getCrawlDelayNodelayString(), 'regex:');
    }

    public function getCrawlDelayNoDelayRegex(): string
    {
        if ($this->isCrawlDelayNoDelayRegex()) {
            return trim(substr($this->getCrawlDelayNodelayString(), strlen('regex:')));
        }
        return '';
    }

    public function getCrawlDelayNodelayString(): string
    {
        return trim((string)($this->tsConfig['crawlDelay.']['nodelay'] ?? self::DEFAULT_TSCONFIG['crawlDelay.']['nodelay']));
    }

    public function getDocsUrl(): string
    {
        return (string)($this->tsConfig['report.']['docsurl'] ?? self::DEFAULT_TSCONFIG['report.']['docsurl']);
    }

    public function getRecheckButton(): int
    {
        return (int)($this->tsConfig['report.']['recheckButton'] ?? self::DEFAULT_TSCONFIG['report.']['recheckButton']);
    }

    public function getMailSendOnCheckLinks(): string
    {
        $value = (string)($this->tsConfig['mail.']['sendOnCheckLinks'] ?? self::DEFAULT_TSCONFIG['mail.']['sendOnCheckLinks']);
        switch ($value) {
            case self::SEND_EMAIL_AUTO:
            case '-1': // Legacy value
                return self::SEND_EMAIL_DEFAULT_VALUE;
            case '0': // Legacy value
                return self::SEND_EMAIL_NEVER;
            case '1': // Legacy value
                return self::SEND_EMAIL_ALWAYS;
        }
        return in_array($value, self::SEND_EMAIL_AVAILABLE_VALUES, true) ? $value : self::SEND_EMAIL_DEFAULT_VALUE;
    }

    public function setMailSendOnCheckLinks(string $value): void
    {
        if (in_array($value, self::SEND_EMAIL_AVAILABLE_VALUES, true) && $value !== self::SEND_EMAIL_AUTO) {
            $this->tsConfig['mail.']['sendOnCheckLinks'] = $value;
        }
    }

    public function getDepth(): int
    {
        return (int)($this->tsConfig['depth'] ?? self::DEFAULT_TSCONFIG['depth']);
    }

    public function setDepth(int $depth): void
    {
        $this->tsConfig['depth'] = $depth;
    }

    public function setMailRecipientsAsString(string $recipients): void
    {
        $this->tsConfig['mail.']['recipients'] = $recipients;
    }

    /** @return Address[] */
    public function getMailRecipients(): array
    {
        $result = [];
        $recipients = trim((string)($this->tsConfig['mail.']['recipients'] ?? ''));
        if ($recipients === '') {
            $fromAddress = (string)($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] ?? '');
            $fromName = (string)($GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromName'] ?? '');
            if (GeneralUtility::validEmail($fromAddress)) {
                $result[] = new Address($fromAddress, $fromName);
            }
        } else {
            foreach (GeneralUtility::trimExplode(',', $recipients, true) as $recipient) {
                try {
                    $result[] = Address::create($recipient);
                } catch (\Symfony\Component\Mime\Exception\RfcComplianceException $e) {
                    // Ignore invalid address
                }
            }
        }
        return $result;
    }

    public function getMailTemplate(): string
    {
        return (string)($this->tsConfig['mail.']['template'] ?? self::DEFAULT_TSCONFIG['mail.']['template']);
    }

    public function getMailFromEmail(): string
    {
        $email = (string)($this->tsConfig['mail.']['fromemail'] ?? '');
        if (GeneralUtility::validEmail($email)) {
            return $email;
        }
        $email = (string)($this->tsConfig['mail.']['from'] ?? ''); // Legacy
        if (GeneralUtility::validEmail($email)) {
            return $email;
        }
        return MailUtility::getSystemFromAddress() ?: '';
    }

    public function getMailFromName(): string
    {
        return (string)($this->tsConfig['mail.']['fromname'] ?? MailUtility::getSystemFromName() ?: '');
    }

    public function getMailReplyToEmail(): string
    {
        $email = (string)($this->tsConfig['mail.']['replytoemail'] ?? '');
        if (GeneralUtility::validEmail($email)) {
            return $email;
        }
        $email = (string)($this->tsConfig['mail.']['replyto'] ?? ''); // Legacy
        if (GeneralUtility::validEmail($email)) {
            return $email;
        }
        return ''; // No system default for reply-to
    }

    public function getMailReplyToName(): string
    {
        return (string)($this->tsConfig['mail.']['replytoname'] ?? '');
    }

    public function getMailSubject(): string
    {
        return (string)($this->tsConfig['mail.']['subject'] ?? 'Broken Link Report');
    }

    public function getMailLanguage(): string
    {
        return (string)($this->tsConfig['mail.']['language'] ?? self::DEFAULT_TSCONFIG['mail.']['language']);
    }

    public function getTraverseMaxNumberOfPagesInBackend(): int
    {
        return $this->traverseMaxNumberOfPagesInBackend;
    }

    public function setTraverseMaxNumberOfPagesInBackend(int $traverseMaxNumberOfPagesInBackend): void
    {
        $this->traverseMaxNumberOfPagesInBackend = $traverseMaxNumberOfPagesInBackend;
    }

    /** @return string[] */
    public function getExcludeSoftrefs(): array
    {
        return $this->excludeSoftrefs;
    }

    /** @return string[] */
    public function getExcludeSoftrefsInFields(): array
    {
        return $this->excludeSoftrefsInFields;
    }

    public function getShowEditButtons(): string
    {
        return $this->showEditButtons;
    }

    public function setShowEditButtons(string $showEditButtons): void
    {
        $this->showEditButtons = $showEditButtons;
    }

    public function isShowPageLayoutButton(): bool
    {
        if (!ExtensionManagementUtility::isLoaded('page_callouts')) { // Assuming 'page_callouts' is a typo for something like 'cms_layout' or similar
            // return false; // Or check for a more relevant extension if 'page_callouts' is incorrect.
        }
        return $this->showPageLayoutButton;
    }

    public function setShowPageLayoutButton(bool $showPageLayoutButton): void
    {
        $this->showPageLayoutButton = $showPageLayoutButton;
    }

    public function isShowAllLinks(): bool
    {
        return $this->showAllLinks;
    }

    public function setShowAllLinks(bool $showAllLinks): void
    {
        $this->showAllLinks = $showAllLinks;
    }

    public function getCombinedErrorNonCheckableMatch(): string
    {
        return $this->combinedErrorNonCheckableMatch;
    }

    public function getTcaProcessing(): string
    {
        return $this->tcaProcessing;
    }

    public function getOverrideFormDataGroup(): string
    {
        return $this->overrideFormDataGroup;
    }

    public function getFormDataGroup(): string
    {
        if ($this->overrideFormDataGroup && class_exists($this->overrideFormDataGroup)) {
            return $this->overrideFormDataGroup;
        }
        switch ($this->getTcaProcessing()) {
            case self::TCA_PROCESSING_NONE:
                return '';
            case self::TCA_PROCESSING_DEFAULT:
                return FieldShouldBeChecked::class; // Ensure this class exists or is adapted
            case self::TCA_PROCESSING_FULL:
                return FieldShouldBeCheckedWithFlexform::class; // Ensure this class exists or is adapted
        }
        return '';
    }

    public function getLinktypeObject(string $linktype): ?LinktypeInterface
    {
        return $this->hookObjectsArr[$linktype] ?? null;
    }

    /** @return array<string,LinktypeInterface> */
    public function getLinktypeObjects(): array
    {
        return $this->hookObjectsArr;
    }

    /** @return mixed[] */
    public function getCustom(): array
    {
        return $this->tsConfig['custom.'] ?? self::DEFAULT_TSCONFIG['custom.'];
    }

    public function isRecheckLinksOnEditing(): bool
    {
        return $this->recheckLinksOnEditing;
    }
}
