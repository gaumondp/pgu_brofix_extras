<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Controller;

use Gaumondp\PguBrofixExtras\CheckLinks\ExcludeLinkTarget;
use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder; // Alias to avoid conflict with PSR UriBuilder if used elsewhere
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Pagination\PaginationInterface;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
// StandaloneView is not used in the original abstract controller, so removed.

/**
 * @internal This class may change without further warnings or increment of major version.
 */
abstract class AbstractBrofixController
{
    protected ?ModuleData $moduleData = null;
    protected string $orderBy = ''; // Initialize with a default type
    protected int $paginationCurrentPage = 1; // Initialize with a default type
    protected int $id = 0; // Current page ID

    /** @var SiteLanguage[] */
    protected array $siteLanguages = [];
    protected ?ModuleTemplate $moduleTemplate = null; // Nullable for safety
    protected ModuleTemplateFactory $moduleTemplateFactory; // Must be injected
    protected Configuration $configuration; // Must be injected
    protected ?PaginationInterface $pagination = null;
    protected IconFactory $iconFactory; // Must be injected
    protected ExcludeLinkTarget $excludeLinkTarget; // Must be injected
    protected PageRenderer $pageRenderer; // Must be injected

    public function __construct(
        Configuration $configuration,
        IconFactory $iconFactory,
        ModuleTemplateFactory $moduleTemplateFactory,
        ExcludeLinkTarget $excludeLinkTarget
        // PageRenderer should be injected if used by all subclasses, or obtained via GeneralUtility
    ) {
        $this->configuration = $configuration;
        $this->iconFactory = $iconFactory;
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->excludeLinkTarget = $excludeLinkTarget;
        // $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class); // Or inject
    }

    /**
     * @param array<string,mixed> $additionalQueryParameters
     * @param string $route Base route name, e.g., 'web_pgubrofuxextras'
     * @return string
     * @throws RouteNotFoundException
     */
    protected function constructBackendUri(array $additionalQueryParameters = [], string $route = 'web_pgubrofuxextras_brokenlinks'): string
    {
        // Ensure essential parameters have defaults if not set
        $parameters = [
            'id' => $this->id,
            'orderBy' => $this->orderBy ?: 'page', // Provide a fallback if orderBy is empty
            'paginationPage' => $this->paginationCurrentPage ?: 1, // Provide a fallback
        ];

        // Merge additional parameters, overwriting defaults if keys match
        $parameters = array_merge($parameters, $additionalQueryParameters);

        $uriBuilder = GeneralUtility::makeInstance(BackendUriBuilder::class);
        return (string)$uriBuilder->buildUriFromRoute($route, $parameters);
    }

    /**
     * @throws SiteNotFoundException
     */
    protected function resolveSiteLanguages(int $pageId): void
    {
        if ($pageId === 0) {
            $this->siteLanguages = [];
            return;
        }
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
             $this->siteLanguages = []; // Cannot determine languages without BE user context
             return;
        }

        $siteMatcher = GeneralUtility::makeInstance(SiteMatcher::class);
        try {
            $site = $siteMatcher->matchByPageId($pageId);
            $this->siteLanguages = $site->getAvailableLanguages($backendUser, true, $pageId);
        } catch (SiteNotFoundException $e) {
            // Handle cases where a page might not belong to a defined site
            $this->siteLanguages = [];
            // Optionally log this warning/error
        }
    }

    protected function getLanguageService(): LanguageService
    {
        if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        return GeneralUtility::makeInstance(LanguageService::class);
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

    // Setter for PageRenderer, typically injected or set during initialize methods of concrete controllers
    public function setPageRenderer(PageRenderer $pageRenderer): void
    {
        $this->pageRenderer = $pageRenderer;
    }
}
