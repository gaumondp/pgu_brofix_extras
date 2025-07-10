<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Gaumondp\PguBrofixExtras\CheckLinks\ExcludeLinkTarget;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use Gaumondp\PguBrofixExtras\Controller\Filter\BrokenLinkListFilter; // Will need to adapt/create this
use Gaumondp\PguBrofixExtras\LinkAnalyzer;
use Gaumondp\PguBrofixExtras\Linktype\LinktypeInterface;
use Gaumondp\PguBrofixExtras\Repository\BrokenLinkRepository;
use Gaumondp\PguBrofixExtras\Repository\PagesRepository;
use Gaumondp\PguBrofixExtras\Util\StringUtil; // Will need to adapt/create this
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as CoreExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\HtmlResponse; // For returning HTML response
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Http\ServerRequest; // For type hinting if specific methods are used.
use TYPO3\CMS\Core\Routing\PageMatcher; // If needed for site matching
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Backend Module 'Check links' for PGU Brofix Extras
 * @internal
 */
class BrokenLinkListController extends AbstractBrofixController
{
    protected const MODULE_NAME = 'web_pgubrofuxextras_brokenlinks'; // Updated module name

    protected const DEFAULT_ORDER_BY = 'page';
    protected const DEFAULT_DEPTH = 0;
    public const VIEW_MODE_VALUE_MIN = 'view_table_min';
    public const VIEW_MODE_VALUE_COMPLEX = 'view_table_complex';
    public const DEFAULT_VIEW_MODE_VALUE = self::VIEW_MODE_VALUE_COMPLEX;

    // ORDER_BY_VALUES remains the same as original Brofix
    protected const ORDER_BY_VALUES = [
        'page' => [['record_pid', 'ASC'], ['language', 'ASC'], ['record_uid', 'ASC']],
        'page_reverse' => [['record_pid', 'DESC'], ['language', 'DESC'], ['record_uid', 'DESC']],
        'type' => [['table_name', 'ASC'], ['field', 'ASC']],
        'type_reverse' => [['table_name', 'DESC'], ['field', 'DESC']],
        'last_check_url' => [['last_check_url', 'ASC']],
        'last_check_url_reverse' => [['last_check_url', 'DESC']],
        'url' => [['link_type', 'ASC'], ['url', 'ASC']],
        'url_reverse' => [['link_type', 'DESC'], ['url', 'DESC']],
        'error' => [['check_status', 'ASC'], ['link_type', 'ASC'], ['url_response', 'ASC']],
        'error_reverse' => [['link_type', 'DESC'], ['url_response', 'DESC']],
    ];

    /** @var array<mixed> */
    protected array $pageRecord = [];
    protected ?LinkAnalyzer $linkAnalyzer = null; // Nullable until initialized
    protected ?BrokenLinkListFilter $filter = null; // Nullable
    /** @var string[] */
    protected array $linkTypes = [];
    protected int $depth = self::DEFAULT_DEPTH;
    protected string $viewMode = self::DEFAULT_VIEW_MODE_VALUE;
    /** @var array<string,mixed> */
    protected array $pageinfo = [];
    // route and token are part of TYPO3 v12 ModuleData or request query params
    protected string $action = '';
    /** @var array<string,mixed> */
    protected array $currentRecord = [
        'uid' => 0, 'table' => '', 'field' => '',
        'currentTime' => 0, 'url' => '', 'linkType' => ''
    ];
    /** @var LinktypeInterface[] */
    protected array $hookObjectsArr = []; // This might be simplified if linktypes are directly part of the extension
    /** @var array<string|int>|null */
    protected ?array $pageList = null; // Nullable
    protected BrokenLinkRepository $brokenLinkRepository;
    protected PagesRepository $pagesRepository;
    protected ?FlashMessageQueue $defaultFlashMessageQueue = null; // Nullable

    protected bool $backendUserHasPermissionsForBrokenLinklist = false;
    protected bool $backendUserHasPermissionsForExcludes = false;

    // Dependencies will be injected by TYPO3's DI container if services are configured.
    // For now, matching original Brofix constructor and calling parent.
    public function __construct(
        PagesRepository $pagesRepository,
        BrokenLinkRepository $brokenLinkRepository,
        ExcludeLinkTarget $excludeLinkTarget,
        FlashMessageService $flashMessageService,
        ModuleTemplateFactory $moduleTemplateFactory,
        IconFactory $iconFactory,
        CoreExtensionConfiguration $coreExtensionConfiguration, // Use aliased CoreExtensionConfiguration
        PageRenderer $pageRenderer,
        // Inject Configuration directly if it's a standalone service
        // Otherwise, it's created in the parent or here from CoreExtensionConfiguration
        ?Configuration $pguConfiguration = null
    ) {
        $this->pageRenderer = $pageRenderer; // Set it for the parent constructor
        $this->brokenLinkRepository = $brokenLinkRepository;
        $this->pagesRepository = $pagesRepository;
        $this->defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier('pgu_brofix_extras'); // Updated queue identifier
        $this->orderBy = self::DEFAULT_ORDER_BY;

        // If Configuration is not injected, create it.
        // This assumes Configuration constructor takes the ext conf array.
        // In a pure DI setup, Configuration would be its own service.
        $configuration = $pguConfiguration ?? GeneralUtility::makeInstance(
            Configuration::class // Our adapted Configuration class
            // Constructor of our Configuration now fetches ext conf itself.
        );

        parent::__construct(
            $configuration, // Pass our Configuration instance
            $iconFactory,
            $moduleTemplateFactory,
            $excludeLinkTarget
        );
        $this->setPageRenderer($pageRenderer); // Ensure parent has it too.
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->moduleData = $request->getAttribute('moduleData');
        if (!$this->moduleData) {
            // Fallback for TYPO3 versions or contexts where moduleData might not be pre-filled from request attribute
            // This part might need adjustment based on how TYPO3 v12/v13 handles module data injection
            // For now, assume it's available or create a new instance.
            $this->moduleData = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Module\ModuleData::class, $request, self::MODULE_NAME);
        }


        $this->initialize($request); // Pass request to initialize
        $this->initializeTemplate($request); // Pass request

        $this->initializePageRenderer();
        $this->initializeLinkAnalyzer($request); // Pass request

        $this->action = (string)($this->moduleData->get('action') ?? 'report'); // Default action to 'report'

        $message = ''; // Initialize message string

        switch ($this->action) {
            case 'checklinks':
                $considerHidden = $this->configuration->isCheckHidden();
                $this->linkAnalyzer->generateBrokenLinkRecords($request, $this->configuration->getLinkTypes(), $considerHidden);
                $this->createFlashMessage(
                    $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.status.check.done'),
                    '', ContextualFeedbackSeverity::OK
                );
                $this->resetModuleData();
                break;
            case 'recheckUrl':
                $count = $this->linkAnalyzer->recheckUrl($message, $this->currentRecord, $request);
                $this->createFlashMessage(
                    $message,
                    $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.url.title'),
                    ContextualFeedbackSeverity::OK // Severity might depend on $count or message content
                );
                $this->resetModuleData();
                break;
            case 'editField':
                $this->linkAnalyzer->recheckRecord(
                    $message, $this->linkTypes, (int)$this->currentRecord['uid'],
                    (string)$this->currentRecord['table'], (string)$this->currentRecord['field'],
                    (int)($this->currentRecord['currentTime'] ?? 0), $request,
                    $this->configuration->isCheckHidden()
                );
                if ($message) {
                    $this->createFlashMessage(
                        $message,
                        $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.recheck.links.title'),
                        ContextualFeedbackSeverity::OK
                    );
                }
                $this->resetModuleData(false);
                break;
            case 'report': // Fall through to default
            default:
                if ($this->action !== 'report') $this->resetModuleData(); // Reset if action was something else then fell to default
                break;
        }
        return $this->mainAction();
    }


    protected function initializeTemplate(ServerRequestInterface $request): void
    {
        if (!$this->moduleTemplateFactory) {
            $this->moduleTemplateFactory = GeneralUtility::makeInstance(ModuleTemplateFactory::class);
        }
        $this->moduleTemplate = $this->moduleTemplateFactory->create($request);

        // For TYPO3 v12, makeDocHeaderModuleMenu might be different or done via PSR-7 response headers/attributes
        // $this->moduleTemplate->makeDocHeaderModuleMenu(['id' => $this->id]); // This is an older way

        $this->moduleTemplate->assign('currentPage', $this->id);
        $this->moduleTemplate->assign('depth', $this->depth);
        $this->moduleTemplate->assign('docsurl', $this->configuration->getDocsUrl());
        $this->moduleTemplate->assign(
            'showRecheckButton',
            $this->getBackendUser()->isAdmin() || $this->depth <= $this->configuration->getRecheckButton()
        );
        $this->moduleTemplate->assign('isAdmin', $this->getBackendUser()->isAdmin());
    }

    protected function renderContent(): void
    {
        if (!$this->backendUserHasPermissionsForBrokenLinklist) {
            $this->createFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:no.access'),
                $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:no.access.title'),
                ContextualFeedbackSeverity::ERROR
            );
            return;
        }
        $this->initializeViewForBrokenLinks();
    }

    protected function initialize(ServerRequestInterface $request): void
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            // Handle case where BE_USER is not available (e.g. CLI context, though this is a BE controller)
            // For now, assume it's always available in a BE request.
            throw new \RuntimeException('Backend user not available.');
        }

        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $this->id = (int)($parsedBody['id'] ?? $queryParams['id'] ?? $this->moduleData->get('id') ?? 0);


        if ($this->id !== 0) {
            $this->resolveSiteLanguages($this->id); // Uses $this->getBackendUser()
            $this->pageinfo = BackendUtility::readPageAccess($this->id, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));
            if ($this->configuration) { // Ensure configuration is set
                $this->configuration->loadPageTsConfig($this->id); // TSconfig for current page
            }
            $this->pageRecord = $this->pageinfo; // pageinfo is essentially the page record
        }

        // Language file loading is usually handled by TYPO3 automatically based on LLL: paths.
        // $this->getLanguageService()->includeLLFile('EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf');

        $this->getSettingsFromQueryParameters($request); // Uses $this->moduleData
        $this->initializeLinkTypes(); // Uses $this->configuration

        // Initialize hookObjectsArr (linktype objects)
        $this->hookObjectsArr = $this->configuration->getLinktypeObjects();


        $this->backendUserHasPermissionsForBrokenLinklist = false;
        if (($this->id && !empty($this->pageRecord)) || (!$this->id && $backendUser->isAdmin())) {
            $this->backendUserHasPermissionsForBrokenLinklist = true;
        }
        if ($backendUser->workspace !== 0) { // Don't allow in workspace
            $this->backendUserHasPermissionsForBrokenLinklist = false;
        }
        $this->backendUserHasPermissionsForExcludes =
            $this->excludeLinkTarget->currentUserHasCreatePermissions(
                $this->configuration->getExcludeLinkTargetStoragePid()
            );
    }

    protected function initializeLinkTypes(): void
    {
        // This now primarily relies on Configuration to provide the list of active linktype keys
        $this->linkTypes = $this->configuration->getLinkTypes();
    }

    protected function initializePageRenderer(): void
    {
        // $this->pageRenderer is injected or created in constructor of AbstractBrofixController
        $this->pageRenderer->addCssFile('EXT:pgu_brofix_extras/Resources/Public/Css/brofix.css', 'stylesheet', 'screen');
        // For TYPO3 v12+, JS modules are preferred. loadJavaScriptModule might need adjustment if Brofix.js is not a module.
        // $this->pageRenderer->loadJavaScriptModule('@gaumondp/pgu-brofix-extras/Brofix.js');
        // $this->pageRenderer->addInlineLanguageLabelFile('EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf');
    }

    public function mainAction(): ResponseInterface
    {
        $this->renderContent();
        // The renderResponse method signature changed in TYPO3 v10+.
        // It now expects the template name as first argument.
        // The second argument (variables) is optional if assigned directly to moduleTemplate.
        return new HtmlResponse($this->moduleTemplate->render('BrokenLinkList/List'));
    }


    protected function getSettingsFromQueryParameters(ServerRequestInterface $request): void
    {
        $this->currentRecord = [
            'uid' => (int)($this->moduleData->get('current_record_uid') ?? 0),
            'table' => (string)($this->moduleData->get('current_record_table') ?? ''),
            'field' => (string)($this->moduleData->get('current_record_field') ?? ''),
            'currentTime' => (int)($this->moduleData->get('current_record_currentTime') ?? 0),
            'url' => urldecode((string)($this->moduleData->get('current_record_url') ?? '')),
            'linkType' => (string)($this->moduleData->get('current_record_linkType') ?? '')
        ];

        $this->depth = (int)($this->moduleData->get('depth', self::DEFAULT_DEPTH));
        $this->orderBy = (string)($this->moduleData->get('orderBy', self::DEFAULT_ORDER_BY));
        $this->viewMode = (string)($this->moduleData->get('viewMode', self::DEFAULT_VIEW_MODE_VALUE));

        $this->filter = GeneralUtility::makeInstance(BrokenLinkListFilter::class, $this->moduleData);

        $this->paginationCurrentPage = (int)($this->moduleData->get('paginationPage') ?? 1);
    }


    protected function initializeLinkAnalyzer(ServerRequestInterface $request): void // Pass request
    {
        if (!$this->configuration || !$this->filter) return; // Guard against uninitialized properties

        switch ($this->filter->getHowtotraverse()) {
            case BrokenLinkListFilter::HOW_TO_TRAVERSE_PAGES:
                $permsClause = $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW);
                if ($this->id !== 0) {
                    $this->pageList = []; // Reset before populating
                    $this->pagesRepository->getPageList(
                        $this->pageList, [$this->id], $this->depth, $permsClause,
                        $this->configuration->isCheckHidden(), [],
                        $this->configuration->getDoNotCheckPagesDoktypes(),
                        $this->configuration->getDoNotTraversePagesDoktypes(),
                        $this->configuration->getTraverseMaxNumberOfPagesInBackend(),
                        $this->filter->isUseCache()
                    );
                } else {
                    $this->pageList = [];
                }
                break;
            case BrokenLinkListFilter::HOW_TO_TRAVERSE_ALL:
                if ($this->isAdmin()) {
                    $this->pageList = null; // Null means all pages for admin
                } else {
                    // Non-admins fall through to allmountpoints if HOW_TO_TRAVERSE_ALL is selected but they are not admin
                    // This behavior might need review: should non-admins be prevented from selecting "ALL"?
                    // For now, replicating original logic by falling through.
                }
                break; // Added break, important if non-admin shouldn't fall through
            case BrokenLinkListFilter::HOW_TO_TRAVERSE_ALLMOUNTPOINTS:
                 if (!$this->isAdmin() || ($this->filter->getHowtotraverse() === BrokenLinkListFilter::HOW_TO_TRAVERSE_ALLMOUNTPOINTS)) {
                    $startPids = $this->getAllowedDbMounts();
                    if (!empty($startPids)) {
                        $permsClause = $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW);
                        $this->pageList = []; // Reset
                        $this->pagesRepository->getPageList(
                            $this->pageList, $startPids, $this->depth, $permsClause,
                            $this->configuration->isCheckHidden(), [],
                            $this->configuration->getDoNotCheckPagesDoktypes(),
                            $this->configuration->getDoNotTraversePagesDoktypes(),
                            $this->configuration->getTraverseMaxNumberOfPagesInBackend(),
                            $this->filter->isUseCache()
                        );
                    } else {
                        $this->pageList = []; // No mountpoints, no pages
                    }
                 }
                break;
        }
        // Ensure LinkAnalyzer is instantiated with all its dependencies
        $this->linkAnalyzer = GeneralUtility::makeInstance(
            LinkAnalyzer::class,
            $this->brokenLinkRepository, // Assuming LinkAnalyzer needs these, adjust as per LinkAnalyzer's constructor
            GeneralUtility::makeInstance(\Gaumondp\PguBrofixExtras\Repository\ContentRepository::class), // Example, ensure correct repo is passed
            $this->pagesRepository
        );
        $this->linkAnalyzer->init($this->pageList, $this->configuration);
    }

    protected function initializeViewForBrokenLinks(): void
    {
        if (!$this->moduleTemplate || !$this->configuration || !$this->pagesRepository || !$this->filter || !$this->brokenLinkRepository || !$this->linkAnalyzer) {
             $this->createFlashMessage('Controller not fully initialized.', '', ContextualFeedbackSeverity::ERROR);
             return;
        }

        $this->moduleTemplate->assign('depth', $this->depth);
        $items = [];
        $totalCount = 0;

        $rootLineHidden = ($this->id > 0 && !empty($this->pageinfo)) ? $this->pagesRepository->getRootLineIsHidden($this->pageinfo) : false;

        if ($this->id === 0 || ($this->id > 0 && (!$rootLineHidden || $this->configuration->isCheckHidden()))) {
            $orderByConfig = self::ORDER_BY_VALUES[$this->orderBy] ?? [];
            $brokenLinks = $this->brokenLinkRepository->getBrokenLinks(
                $this->pageList, $this->linkTypes,
                $this->configuration->getSearchFields(),
                $this->filter, $orderByConfig
            );

            if (!empty($brokenLinks)) {
                $totalCount = count($brokenLinks);
                $itemsPerPage = 100; // Make configurable?
                if (($this->paginationCurrentPage - 1) * $itemsPerPage >= $totalCount && $totalCount > 0) {
                    $this->resetPagination(); // Reset to page 1 if current page is out of bounds
                }
                $paginator = GeneralUtility::makeInstance(ArrayPaginator::class, $brokenLinks, $this->paginationCurrentPage, $itemsPerPage);
                $this->pagination = GeneralUtility::makeInstance(SimplePagination::class, $paginator);

                foreach ($paginator->getPaginatedItems() as $row) {
                    $items[] = $this->renderTableRow((string)$row['table_name'], $row);
                }
                $this->moduleTemplate->assign('listUri', $this->constructBackendUri([], self::MODULE_NAME));
            }

            if ($this->configuration->getTraverseMaxNumberOfPagesInBackend() > 0 &&
                is_array($this->pageList) && count($this->pageList) >= $this->configuration->getTraverseMaxNumberOfPagesInBackend()) {
                $this->createFlashMessage(
                    sprintf(
                        $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.warning.max_limit_pages_reached') ?: 'Limit of %s pages reached.',
                        $this->configuration->getTraverseMaxNumberOfPagesInBackend()
                    ),
                    $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.warning.max_limit_pages_reached.title') ?: 'Page Limit Reached',
                    ContextualFeedbackSeverity::WARNING
                );
            }
        } else {
            $this->pagination = null; // No items if page is hidden and not checking hidden
        }

        $this->moduleTemplate->assign('totalCount', $totalCount);
        $this->moduleTemplate->assign('filter', $this->filter);
        $this->moduleTemplate->assign('viewMode', $this->viewMode);

        if ($this->id === 0 && empty($items)) { // Check items too, as admin might have pageList = null but no broken links
            $this->createFlashMessagesForRootPage();
        } elseif (empty($items)) {
            $this->createFlashMessagesForNoBrokenLinks();
        }

        $this->moduleTemplate->assign('brokenLinks', $items);
        $linktypeOptions = array_merge(['all' => 'All'], $this->linkTypes); // Provide a translated 'All'
        if (count($linktypeOptions) > 2) { // Only show filter if more than 'All' and one type
            $this->moduleTemplate->assign('linktypes', $linktypeOptions);
        }

        $this->moduleTemplate->assign('pagination', $this->pagination);
        $this->moduleTemplate->assign('orderBy', $this->orderBy);
        $this->moduleTemplate->assign('paginationPage', $this->paginationCurrentPage ?: 1);
        $this->moduleTemplate->assign('showPageLayoutButton', $this->configuration->isShowPageLayoutButton());

        $sortActions = [];
        foreach (array_keys(self::ORDER_BY_VALUES) as $key) {
            $sortActions[$key] = $this->constructBackendUri(['orderBy' => $key], self::MODULE_NAME);
        }
        $this->moduleTemplate->assign('sortActions', $sortActions);
        $this->moduleTemplate->assign('tableHeader', $this->getVariablesForTableHeader($sortActions));
    }

    protected function createFlashMessagesForNoBrokenLinks(): void
    {
        $status = ContextualFeedbackSeverity::OK;
        $messageKey = '';
        if ($this->filter && $this->filter->isFilterActive()) { // Assuming isFilterActive method exists
            $status = ContextualFeedbackSeverity::WARNING;
            $messageKey = 'list.no.broken.links.filter';
        } elseif ($this->depth === 0) {
            $messageKey = 'list.no.broken.links.this.page';
            $status = ContextualFeedbackSeverity::INFO;
        } elseif ($this->depth > 0 && $this->depth < BrokenLinkListFilter::PAGE_DEPTH_INFINITE) {
            $messageKey = 'list.no.broken.links.current.level';
            $status = ContextualFeedbackSeverity::INFO;
        } else {
            $messageKey = 'list.no.broken.links.level.infinite';
        }
        $message = $this->getLanguageService()->sL("LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:{$messageKey}");
        if ($this->depth === 0 || ($this->depth > 0 && $this->depth < BrokenLinkListFilter::PAGE_DEPTH_INFINITE)) {
             $message .= ' ' . $this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:message.choose.higher.level');
        }
        $this->createFlashMessage($message, '', $status);
    }

    protected function createFlashMessagesForRootPage(): void
    {
        $this->createFlashMessage($this->getLanguageService()->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.rootpage'));
    }

    /**
     * @param int|value-of<ContextualFeedbackSeverity>|ContextualFeedbackSeverity $severity
     */
    protected function createFlashMessage(string $message, string $title = '', $severity = ContextualFeedbackSeverity::INFO): void
    {
        if (empty($message)) return;
        $flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $message, $title, $severity, true); // Store in session
        $this->defaultFlashMessageQueue?->enqueue($flashMessage);
    }

    /** @return array<string,array<string,string>> */
    protected function getVariablesForTableHeader(array $sortActions): array
    {
        $languageService = $this->getLanguageService();
        $headers = ['page', 'element', 'type', 'last_check_record', 'linktext', 'url', 'error', 'last_check_url', 'action'];
        $tableHeadData = [];

        foreach ($headers as $key) {
            $labelKeyPart1 = ''; $labelKeyPart2 = '';
            if ($key === 'last_check_record') {
                $labelKeyPart1 = 'list.tableHead.last_check.part1';
                $labelKeyPart2 = 'list.tableHead.last_check.part2.record';
            } elseif ($key === 'last_check_url') {
                $labelKeyPart1 = 'list.tableHead.last_check.part1';
                $labelKeyPart2 = 'list.tableHead.last_check.part2.url';
            } else {
                $labelKeyPart1 = 'list.tableHead.' . $key;
            }

            $label = $languageService->sL("LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:{$labelKeyPart1}");
            if ($labelKeyPart2) {
                $label .= '<br/>' . $languageService->sL("LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:{$labelKeyPart2}");
            }

            $url = '';
            $icon = '';
            if (isset($sortActions[$key])) {
                $url = $this->orderBy === $key ? ($sortActions[$key . '_reverse'] ?? '') : ($sortActions[$key] ?? '');
                if ($this->orderBy === $key) $icon = 'actions-sort-up'; // Use actual icon identifiers
                elseif ($this->orderBy === $key . '_reverse') $icon = 'actions-sort-down';
            }
            $tableHeadData[$key] = ['label' => $label, 'url' => $url, 'icon' => $icon];
        }
        return $tableHeadData; // This structure is directly assigned to Fluid, no need for further HTML formatting here.
    }

    /** @param array<mixed> $row */
    protected function renderTableRow(string $table, array $row): array
    {
        $languageService = $this->getLanguageService();
        $variables = $row; // Start with all row data
        $linkTargetResponse = LinkTargetResponse::createInstanceFromJson((string)($row['url_response'] ?? '{}'));
        $hookObj = $this->hookObjectsArr[$row['link_type']] ?? null;
        if (!$hookObj instanceof LinktypeInterface) {
            // Fallback or error if hook object not found
            $variables['linkmessage'] = 'Error: Linktype object not found for type ' . htmlspecialchars((string)$row['link_type']);
            $variables['linktarget'] = htmlspecialchars((string)$row['url']);
            $variables['linktext'] = htmlspecialchars((string)$row['url']);
            return $variables;
        }


        $context = GeneralUtility::makeInstance(Context::class);
        $currentTimestamp = $context->getPropertyFromAspect('date', 'timestamp');

        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class); // Correct UriBuilder for backend

        $backUriEditFieldParams = [
            'action' => 'editField', 'current_record_uid' => $row['record_uid'],
            'current_record_table' => $row['table_name'], 'current_record_field' => $row['field'],
            'current_record_currentTime' => $currentTimestamp,
        ];
        // Add other necessary persistent GET params like id, depth, orderBy
        $backUriEditFieldParams['id'] = $this->id;
        $backUriEditFieldParams['depth'] = $this->depth;
        $backUriEditFieldParams['orderBy'] = $this->orderBy;

        $backUriEditField = $this->constructBackendUri($backUriEditFieldParams, self::MODULE_NAME);


        $showEditButtonsSetting = $this->configuration->getShowEditButtons();
        $editUrlParametersBase = ['edit' => [$table => [$row['record_uid'] => 'edit']], 'returnUrl' => $backUriEditField];

        if ($showEditButtonsSetting === 'both' || $showEditButtonsSetting === 'full') {
            $variables['editUrlFull'] = (string)$uriBuilder->buildUriFromRoute('record_edit', $editUrlParametersBase);
        }
        if ($showEditButtonsSetting === 'both' || $showEditButtonsSetting === 'field') {
            $editUrlParametersField = $editUrlParametersBase;
            $editUrlParametersField['columnsOnly'] = (string)$row['field'];
            $variables['editUrlField'] = (string)$uriBuilder->buildUriFromRoute('record_edit', $editUrlParametersField);
        }

        $recheckUrlParams = [
            'action' => 'recheckUrl', 'current_record_url' => urlencode((string)$row['url']),
            'current_record_linkType' => $row['link_type'], 'current_record_uid' => $row['record_uid'],
            'current_record_table' => $row['table_name'], 'current_record_field' => $row['field'],
            'current_record_currentTime' => $currentTimestamp,
            'id' => $this->id, 'depth' => $this->depth, 'orderBy' => $this->orderBy,
        ];
        $variables['recheckUrl'] = $this->constructBackendUri($recheckUrlParams, self::MODULE_NAME);


        $variables['lastChecked'] = 0;
        if (isset($this->currentRecord['uid']) && (int)$row['record_uid'] === (int)$this->currentRecord['uid'] &&
            (string)$row['table_name'] === (string)$this->currentRecord['table'] && (string)$row['field'] === (string)$this->currentRecord['field']) {
            $variables['lastChecked'] = 1;
        }
        if ($this->action === 'recheckUrl' && isset($this->currentRecord['url']) &&
            (string)$this->currentRecord['url'] === (string)$row['url'] && (string)$this->currentRecord['linkType'] === (string)$row['link_type']) {
            $variables['lastChecked'] = 1;
        }

        $excludeLinkTargetStoragePid = $this->configuration->getExcludeLinkTargetStoragePid();
        if (in_array((string)($row['link_type'] ?? 'empty'), $this->configuration->getExcludeLinkTargetAllowedTypes()) &&
            $this->backendUserHasPermissionsForExcludes && !$linkTargetResponse->isExcluded()) {
            $returnUrlForExclude = $this->constructBackendUri(['id' => $this->id, 'depth' => $this->depth, 'orderBy' => $this->orderBy], self::MODULE_NAME);
            $excludeUrlParams = [
                'edit' => ['tx_pgubrofuxextras_exclude_link_target' => [$excludeLinkTargetStoragePid => 'new']],
                'defVals' => ['tx_pgubrofuxextras_exclude_link_target' => ['link_type' => $row['link_type'] ?? 'external', 'linktarget' => $row['url']]],
                'returnUrl' => $returnUrlForExclude
            ];
            $variables['excludeUrl'] = (string)$uriBuilder->buildUriFromRoute('record_edit', $excludeUrlParams);
        }

        $variables['elementHeadline'] = htmlspecialchars((string)($row['headline'] ?? ''));
        $variables['elementIcon'] = $this->iconFactory->getIconForRecord($table, $row, Icon::SIZE_SMALL)->render();

        if (isset($row['language'])) {
            $langUid = (int)$row['language'];
            if ($langUid !== -1 && isset($this->siteLanguages[$langUid])) {
                $variables['langIcon'] = $this->siteLanguages[$langUid]->getFlagIdentifier();
            } // lang is already in $row
        }

        if ($this->isAdmin()) {
            // $variables['table'] and $variables['field'] are already in $row
        }
        $variables['elementType'] = htmlspecialchars($languageService->sL($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table));
        $fieldNameLabel = $languageService->sL($GLOBALS['TCA'][$table]['columns'][(string)$row['field']]['label'] ?? (string)$row['field']);
        $variables['fieldName'] = htmlspecialchars(rtrim($fieldNameLabel, ':'));
        if (!empty($row['flexform_field_label'])) {
            $flexLabel = $languageService->sL((string)$row['flexform_field_label']);
            if ($flexLabel) $variables['fieldName'] = htmlspecialchars($flexLabel);
        }

        $pageId = (int)($table === 'pages' ? $row['record_uid'] : $row['record_pid']);
        $variables['pageId'] = $pageId;
        [$pageTitle, $pagePath] = $this->pagesRepository->getPagePath($pageId, 50);
        $variables['path'] = htmlspecialchars($pagePath);
        $variables['pagetitle'] = htmlspecialchars($pageTitle);

        $status = $linkTargetResponse->getStatus();
        $linkMessageText = '';
        switch ($status) {
            case LinkTargetResponse::RESULT_BROKEN:
                $linkMessageText = sprintf('<span class="text-danger" title="%s">%s</span>',
                    nl2br(htmlspecialchars($linkTargetResponse->getExceptionMessage())),
                    nl2br(htmlspecialchars($hookObj->getErrorMessage($linkTargetResponse)))
                );
                break;
            case LinkTargetResponse::RESULT_OK:
                $linkMessageText = '<span class="text-success">' . htmlspecialchars($languageService->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.msg.ok')) . '</span>';
                break;
            case LinkTargetResponse::RESULT_CANNOT_CHECK:
                $reason = $linkTargetResponse->getReasonCannotCheck() ? ':' . htmlspecialchars($linkTargetResponse->getReasonCannotCheck()) : '';
                $linkMessageText = sprintf('<span class="text-warning">%s%s</span>: <span class="text-danger" title="%s">%s</span>',
                    htmlspecialchars($languageService->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.msg.status.cannot_check')),
                    $reason,
                    nl2br(htmlspecialchars($linkTargetResponse->getExceptionMessage())),
                    nl2br(htmlspecialchars($hookObj->getErrorMessage($linkTargetResponse)))
                );
                break;
            case LinkTargetResponse::RESULT_EXCLUDED:
                $linkMessageText = '<span class="text-info">' . htmlspecialchars($languageService->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.msg.status.excluded')) . '</span>';
                break;
            case LinkTargetResponse::RESULT_UNKNOWN: // Cloudflare
            default:
                $linkMessageText = sprintf('<span class="status-cloudflare pgu-brofix-cloudflare">%s</span>', // Added specific class
                    htmlspecialchars($languageService->sL('LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.msg.status.cloudflare'))
                );
                break;
        }
        $variables['linkmessage'] = $linkMessageText;
        // $variables['status'] is already in $row

        $variables['linktarget'] = htmlspecialchars($hookObj->getBrokenUrl($row));
        $variables['effectiveUrl'] = htmlspecialchars($linkTargetResponse->getEffectiveUrl());
        $variables['redirectCount'] = $linkTargetResponse->getRedirectCount();
        // $variables['orig_linktarget'] is already in $row (as 'url')
        $variables['encoded_linktarget'] = ($this->filter && $this->filter->getUrlFilter() == $row['url'] && $this->filter->getUrlFilterMatch() === 'exact') ? '' : urlencode((string)$row['url']);

        if (isset($row['link_title']) && $variables['linktarget'] !== $row['link_title']) {
            $variables['link_title_display'] = htmlspecialchars((string)$row['link_title']); // Use a different key to avoid conflict if 'link_title' is used elsewhere
        } else {
            $variables['link_title_display'] = '';
        }
        $variables['linktext'] = htmlspecialchars($hookObj->getBrokenLinkText($row, $linkTargetResponse->getCustom()));

        $variables['lastcheck_combined'] = StringUtil::formatTimestampAsString(min((int)$row['last_check'], (int)$row['last_check_url']));
        $variables['last_check_formatted'] = StringUtil::formatTimestampAsString((int)$row['last_check']); // Renamed to avoid conflict
        $variables['last_check_url_formatted'] = StringUtil::formatTimestampAsString((int)$row['last_check_url']); // Renamed

        $tstamp_field = $GLOBALS['TCA'][(string)$row['table_name']]['ctrl']['tstamp'] ?? '';
        $variables['freshness'] = 'unknown';
        if ($tstamp_field && isset($row[$tstamp_field])) { // Check if tstamp field value exists in $row
            $tstamp = (int)$row[$tstamp_field];
            $last_check_db = (int)$row['last_check'];
            $variables['freshness'] = ($tstamp > $last_check_db) ? 'stale' : 'fresh';
        }
        return $variables;
    }


    protected function resetPagination(int $pageNr = 1): void
    {
        $this->paginationCurrentPage = $pageNr;
        if ($this->moduleData) {
            $this->moduleData->set('paginationPage', $pageNr);
        }
    }

    protected function resetModuleData(bool $resetCurrentRecord = true): void
    {
        if (!$this->moduleData) return;

        $persist = false;
        if ($this->moduleData->get('current_record_uid')) {
            $this->moduleData->set('current_record_uid', '');
            $this->moduleData->set('current_record_table', '');
            $this->moduleData->set('current_record_field', '');
            $this->moduleData->set('current_record_currentTime', '');
            $this->moduleData->set('current_record_url', '');
            $this->moduleData->set('current_record_linkType', '');
            if ($resetCurrentRecord) {
                $this->currentRecord = ['uid' => 0, 'table' => '', 'field' => '', 'currentTime' => 0, 'url' => '', 'linkType' => ''];
            }
            $persist = true;
        }
        if ($this->moduleData->get('action', 'report') !== 'report') {
            $this->moduleData->set('action', 'report');
            $this->action = 'report';
            $persist = true;
        }

        if ($persist && $this->getBackendUser()) { // Ensure BE_USER exists
            $this->getBackendUser()->pushModuleData(self::MODULE_NAME, $this->moduleData->toArray());
        }
    }

    /** @return int[] */
    public function getAllowedDbMounts(): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) return [];

        $dbMounts = (int)($backendUser->uc['pageTree_temporaryMountPoint'] ?? 0);
        if (!$dbMounts) {
            $rawMounts = $backendUser->returnWebmounts();
            $dbMounts = array_map('intval', $rawMounts); // Ensure all are integers
            $dbMounts = array_unique(array_filter($dbMounts)); // Filter out zeros if any after intval
        } else {
            $dbMounts = [$dbMounts];
        }
        return $dbMounts;
    }
}
