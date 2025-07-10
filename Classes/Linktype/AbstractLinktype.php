<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Linktype;

use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractLinktype implements LinktypeInterface
{
    /**
     * Flag used int checkLink() $flags
     * All CHECK_LINK_FLAG_ flags can be combined
     * with bitwise OR (|)
     *
     * @var int
     */
    public const CHECK_LINK_FLAG_NO_CRAWL_DELAY = 1;

    /**
     * If this flag is set, we do not use result from cache if URL is invalid.
     * This results in URLs with errors always getting rechecked
     */
    public const CHECK_LINK_FLAG_NO_CACHE_ON_ERROR = 2;

    /**
     * Do not get result from cache. Always recheck URL (but write it to cache)
     */
    public const CHECK_LINK_FLAG_NO_CACHE = 4;

    /**
     * If synchronous checking is performed (in contrast to asynchronous checking
     * in the background), the objective is usually to do
     * a faster check - e.g. always retrieve from link target cache and
     * possibly use a longer expires time for that.
     *
     * Synchronous checking is currently used for On-the-fly checking after editing
     * a record and returning to list of broken links. This will most likely change
     * in the future, as it is clunky.
     *
     * @var int
     */
    public const CHECK_LINK_FLAG_SYNCHRONOUS = 8;

    protected ?Configuration $configuration = null;

    public function setConfiguration(Configuration $configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * Base type fetching method, based on the type that softRefParserObj returns
     *
     * @param mixed[] $value Reference properties
     * @param string $type Current type
     * @param string $key Validator hook name
     * @return string Fetched type
     */
    public function fetchType(array $value, string $type, string $key): string
    {
        $newType = $value['type'] ?? '';
        if ($newType === '' || !is_string($newType)) {
            return $type;
        }

        if ($newType === $key) {
            $type = $newType;
        }
        return $type;
    }

    /**
     * Construct a valid Url for browser output
     *
     * @param mixed[] $row Broken link record
     * @return string Parsed broken url
     */
    public function getBrokenUrl(array $row): string
    {
        return (string)($row['url'] ?? '');
    }

    /**
     * Text to be displayed with the Link as anchor text
     * (not the real anchor text of the Link.
     * @param mixed[] $row
     * @param array<mixed>|null $additionalConfig
     * @return string
     */
    public function getBrokenLinkText(array $row, ?array $additionalConfig = null): string
    {
        return (string)($row['url'] ?? '');
    }

    /**
     * @return LanguageService
     */
    protected function getLanguageService(): LanguageService
    {
        if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] instanceof LanguageService) {
            return $GLOBALS['LANG'];
        }
        return GeneralUtility::makeInstance(LanguageService::class);
    }
}
