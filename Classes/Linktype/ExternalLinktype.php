<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Linktype;

use Gaumondp\PguBrofixExtras\CheckLinks\CrawlDelay;
use Gaumondp\PguBrofixExtras\CheckLinks\ExcludeLinkTarget;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetCache\LinkTargetCacheInterface;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetCache\LinkTargetPersistentCache;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;

/**
 * This class provides Check External Links plugin implementation
 */
class ExternalLinktype extends AbstractLinktype implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    // HTTP status code was delivered (and can be found in $errorParams->errno)
    public const ERROR_TYPE_HTTP_STATUS_CODE = 'httpStatusCode';
    // An error occurred in lowlevel handler and a cURL error code can be found in $errorParams->errno
    public const ERROR_TYPE_LOWLEVEL_LIBCURL_ERRNO = 'libcurlErrno';
    public const ERROR_TYPE_TOO_MANY_REDIRECTS = 'tooManyRedirects';
    public const ERROR_TYPE_UNABLE_TO_PARSE = 'unableToParseUri';
    public const ERROR_TYPE_UNKNOWN = 'unknown';

    protected RequestFactory $requestFactory;
    protected ExcludeLinkTarget $excludeLinkTarget;
    protected string $domain = '';
    protected LinkTargetCacheInterface $linkTargetCache;
    protected CrawlDelay $crawlDelay;

    /** @var array<int,array{from:string, to:string}> */
    protected array $redirects = [];

    public function __construct(
        ?RequestFactory $requestFactory = null,
        ?ExcludeLinkTarget $excludeLinkTarget = null,
        ?LinkTargetCacheInterface $linkTargetCache = null,
        ?CrawlDelay $crawlDelay = null
    ) {
        $this->requestFactory = $requestFactory ?: GeneralUtility::makeInstance(RequestFactory::class);
        $this->excludeLinkTarget = $excludeLinkTarget ?: GeneralUtility::makeInstance(ExcludeLinkTarget::class);
        $this->linkTargetCache = $linkTargetCache ?: GeneralUtility::makeInstance(LinkTargetPersistentCache::class); // Ensure this class exists or is adapted
        $this->crawlDelay = $crawlDelay ?: GeneralUtility::makeInstance(CrawlDelay::class);
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    public function setConfiguration(Configuration $configuration): void
    {
        parent::setConfiguration($configuration);
        $this->excludeLinkTarget->setExcludeLinkTargetsPid($this->configuration->getExcludeLinkTargetStoragePid());
        $this->crawlDelay->setConfiguration($this->configuration);
    }

    protected function insertIntoLinkTargetCache(string $url, LinkTargetResponse $linkTargetResponse): void
    {
        $this->linkTargetCache->setResult($url, 'external', $linkTargetResponse);
    }

    public function checkLink(string $origUrl, array $softRefEntry, int $flags = 0): ?LinkTargetResponse
    {
        if (!$this->configuration) {
            $this->logger->error('ExternalLinktype configuration not initialized in checkLink.');
            return LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNKNOWN, 0, '', 'Configuration not set');
        }

        if ($this->excludeLinkTarget->isExcluded($origUrl, 'external')) {
            return LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_EXCLUDED);
        }

        if ((($flags & AbstractLinktype::CHECK_LINK_FLAG_NO_CACHE) === 0)) {
            $urlResponse = $this->linkTargetCache->getUrlResponseForUrl(
                $origUrl,
                'external',
                $this->configuration->getLinkTargetCacheExpires($flags)
            );
            if ($urlResponse) {
                if ((($flags & AbstractLinktype::CHECK_LINK_FLAG_NO_CACHE_ON_ERROR) !== 0)
                    && $urlResponse->getStatus() === LinkTargetResponse::RESULT_BROKEN) {
                    // Skip cache result here and continue checking
                } else {
                    return $urlResponse;
                }
            }
        }

        $cookieJar = GeneralUtility::makeInstance(CookieJar::class);
        $this->redirects = [];

        $onRedirect = function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
            $this->redirects[] = ['from' => (string)$request->getUri(), 'to' => (string)$uri];
        };

        $options = [
            'cookies' => $cookieJar,
            'allow_redirects' => [
                'strict' => true,
                'referer' => true,
                'max' => $this->configuration->getLinktypesConfigExternalRedirects(),
                'on_redirect' => $onRedirect,
            ],
            'headers' => $this->configuration->getLinktypesConfigExternalHeaders(),
            'timeout' => $this->configuration->getLinktypesConfigExternalTimeout(),
            'connect_timeout' => $this->configuration->getLinktypesConfigExternalConnectTimeout(), // Added connect_timeout
            'verify' => $this->configuration->isLinktypesConfigExternalSslVerifyPeer(), // Added SSL verify
        ];

        $url = $this->preprocessUrl($origUrl);
        $linkTargetResponse = null;

        if (!empty($url)) {
            if ((($flags & AbstractLinktype::CHECK_LINK_FLAG_NO_CRAWL_DELAY) === 0)) {
                $continueChecking = $this->crawlDelay->crawlDelay($this->domain);
                if (!$continueChecking) {
                    return null; // Temporary error, don't cache as broken
                }
            }

            // Try HEAD request first
            $linkTargetResponse = $this->requestUrl($url, 'HEAD', $options);

            // If HEAD fails or results in an error that might be Cloudflare, try GET
            // The original PR implies that status 5 (RESULT_UNKNOWN) should be set if Cloudflare is detected,
            // potentially overriding other statuses like 403.
            $isCloudflareDetectedByHead = ($linkTargetResponse->getStatus() === LinkTargetResponse::RESULT_UNKNOWN &&
                                          $linkTargetResponse->getReasonCannotCheck() === LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);

            if (!$isCloudflareDetectedByHead && ($linkTargetResponse->isError() || $linkTargetResponse->getStatus() === LinkTargetResponse::RESULT_OK_BUT_MAYBE_CHECK_GET)) {
                 // RESULT_OK_BUT_MAYBE_CHECK_GET is a hypothetical status if HEAD was inconclusive for Cloudflare
                $this->logger->debug('HEAD request for ' . $url . ' resulted in status ' . $linkTargetResponse->getStatus() . '. Trying GET.');
                $getOptions = $options;
                $getOptions['headers']['Range'] = 'bytes=0-4048'; // Limit download for GET
                $linkTargetResponseGet = $this->requestUrl($url, 'GET', $getOptions);

                // Prioritize GET response if it's more definitive or if HEAD was problematic
                // If GET detects Cloudflare, it should override HEAD's result.
                $isCloudflareDetectedByGet = ($linkTargetResponseGet->getStatus() === LinkTargetResponse::RESULT_UNKNOWN &&
                                             $linkTargetResponseGet->getReasonCannotCheck() === LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);

                if ($isCloudflareDetectedByGet) {
                    $linkTargetResponse = $linkTargetResponseGet;
                } elseif ($linkTargetResponse->getStatus() === LinkTargetResponse::RESULT_OK_BUT_MAYBE_CHECK_GET) {
                    // If HEAD was only 'maybe' and GET is not Cloudflare, use GET's result.
                    $linkTargetResponse = $linkTargetResponseGet;
                } elseif ($linkTargetResponse->isError() && !$linkTargetResponseGet->isError()) {
                    // If HEAD had an error but GET was successful (and not Cloudflare), prefer GET.
                     $linkTargetResponse = $linkTargetResponseGet;
                } elseif ($linkTargetResponse->isError() && $linkTargetResponseGet->isError()){
                    // Both errored, GET might have more info (e.g. from combinedErrorNonCheckable)
                    $linkTargetResponse = $linkTargetResponseGet;
                }
                // Otherwise, if GET didn't detect Cloudflare and HEAD was a non-Cloudflare error, stick with HEAD's error.
            }
            $this->crawlDelay->setLastCheckedTime($this->domain);
        } else {
            $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNABLE_TO_PARSE, 0, '', 'URL became empty after preprocessing.');
        }

        if ($linkTargetResponse) {
            $this->insertIntoLinkTargetCache($origUrl, $linkTargetResponse); // Use $origUrl for cache key
        }
        return $linkTargetResponse;
    }

    protected function requestUrl(string $url, string $method, array $options): LinkTargetResponse
    {
        $responseHeaders = [];
        $linkTargetResponse = LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_OK); // Default to OK

        try {
            $this->redirects = []; // Reset redirects for this specific request
            $response = $this->requestFactory->request($url, $method, $options);
            $responseHeaders = $response->getHeaders(); // Get headers for later checks

            // Explicitly check for Cloudflare via Server header first
            $serverHeader = $response->getHeaderLine('Server');
            if (stripos($serverHeader, 'cloudflare') !== false) {
                $this->logger->debug('Cloudflare detected via Server header for URL: ' . $url);
                $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_UNKNOWN);
                $linkTargetResponse->setReasonCannotCheck(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);
                // If Cloudflare is detected, we might not need to process other errors unless it's a hard error like 5xx from Cloudflare itself
                 if ($response->getStatusCode() >= 500) { // Real server error from Cloudflare
                    $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                    $linkTargetResponse->setErrno($response->getStatusCode());
                    // Status remains RESULT_UNKNOWN, but now with an error type/code from Cloudflare.
                 } else if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500 && $response->getStatusCode() !== 403) {
                     // If Cloudflare returns other client errors (not 403, which is typical for blocking)
                     $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                     $linkTargetResponse->setErrno($response->getStatusCode());
                 }
                 // For Cloudflare + 403, we keep RESULT_UNKNOWN and REASON_CANNOT_CHECK_CLOUDFLARE
                return $linkTargetResponse; // Return early if Cloudflare detected and handled
            }

            // If not Cloudflare, proceed with normal status code evaluation
            if ($response->getStatusCode() >= 300) {
                $linkTargetResponse = LinkTargetResponse::createInstanceByError(
                    self::ERROR_TYPE_HTTP_STATUS_CODE,
                    $response->getStatusCode()
                );
            } else { // Status < 300
                $linkTargetResponse = LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_OK);
                 // Special case for HEAD: if it's OK, it might still hide Cloudflare.
                 // We could return a temporary status to signal checkLink to try GET.
                if ($method === 'HEAD') {
                    $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_OK_BUT_MAYBE_CHECK_GET); // Hypothetical status
                }
            }
        } catch (TooManyRedirectsException $e) {
            $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_TOO_MANY_REDIRECTS, 0, '', $e->getMessage());
        } catch (ClientException | ServerException $e) {
            $response = $e->getResponse();
            if ($response) {
                $responseHeaders = $response->getHeaders(); // Get headers from error response
                $serverHeader = $response->getHeaderLine('Server');
                if (stripos($serverHeader, 'cloudflare') !== false) {
                    $this->logger->debug('Cloudflare detected via Server header in error response for URL: ' . $url);
                    $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_UNKNOWN);
                    $linkTargetResponse->setReasonCannotCheck(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);
                     if ($response->getStatusCode() >= 500) {
                        $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                        $linkTargetResponse->setErrno($response->getStatusCode());
                    } else if ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 403) {
                        $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                        $linkTargetResponse->setErrno($response->getStatusCode());
                    }
                    // For Cloudflare + 403 error, keep RESULT_UNKNOWN and REASON_CANNOT_CHECK_CLOUDFLARE
                } else {
                    $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_HTTP_STATUS_CODE, $response->getStatusCode());
                }
            } else {
                $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNKNOWN);
            }
            $linkTargetResponse->setExceptionMessage($e->getMessage());
        } catch (ConnectException | RequestException $e) {
            $exceptionMessage = $e->getMessage();
            $handlerContext = method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : [];
            if (($handlerContext['errno'] ?? 0) !== 0 && str_starts_with($e->getMessage(), 'cURL error')) {
                if (isset($handlerContext['error'])) {
                    $exceptionMessage = $handlerContext['error'];
                }
                $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_LOWLEVEL_LIBCURL_ERRNO, (int)($handlerContext['errno']), '', $exceptionMessage);
            } else {
                $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNKNOWN, 0, '', $exceptionMessage);
            }
        } catch (\InvalidArgumentException $e) {
            $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNABLE_TO_PARSE, 0, '', $e->getMessage());
        } catch (\Exception $e) {
            $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNKNOWN, 0, '', $e->getMessage());
        }

        if (!empty($this->redirects)) {
            $linkTargetResponse->setRedirects($this->redirects);
        }
        $linkTargetResponse->setEffectiveUrl($url); // Should be set to the final URL after redirects, Guzzle might handle this.

        // If Cloudflare was not detected by Server header, check for cf-* headers as a fallback for non-checkable determination
        if ($linkTargetResponse->getStatus() !== LinkTargetResponse::RESULT_UNKNOWN ||
            $linkTargetResponse->getReasonCannotCheck() !== LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE) {
            if ($method === 'GET' && $this->isCombinedErrorNonCheckable($linkTargetResponse)) {
                $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_CANNOT_CHECK);
                // Check for cf-* headers only if not already marked as Cloudflare by Server header
                $isCfHeaderPresent = false;
                foreach ($responseHeaders as $headerName => $headerValue) {
                    if (is_string($headerName) && str_starts_with(mb_strtolower($headerName), 'cf-')) {
                        $isCfHeaderPresent = true;
                        break;
                    }
                }
                if ($isCfHeaderPresent) {
                     // This might override a more specific non-Cloudflare reason if isCombinedErrorNonCheckable was true for other reasons
                    $linkTargetResponse->setReasonCannotCheck(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);
                }
            }
        }


        if ($method === 'GET' && $linkTargetResponse->getErrorType() === self::ERROR_TYPE_HTTP_STATUS_CODE
            && ($linkTargetResponse->getErrno() === 429 || $linkTargetResponse->getErrno() === 503)) {
            $retryAfter = 0;
            foreach ($responseHeaders as $headerName => $headerValues) {
                if (is_string($headerName) && mb_strtolower($headerName) === 'retry-after') {
                    foreach ((array)$headerValues as $headerValue) {
                        if (is_numeric($headerValue)) {
                            $retryAfter = ((int)($headerValue)) + time();
                            break 2;
                        }
                        try {
                            $parsedRetryAfter = strtotime((string)$headerValue);
                            if ($parsedRetryAfter !== false) {
                                $retryAfter = $parsedRetryAfter;
                                break 2;
                            }
                        } catch (\Throwable $ex) { /* ignore parse error */ }
                    }
                }
            }
            $effectiveUrl = $linkTargetResponse->getEffectiveUrl() ?: $url;
            $effectiveDomain = $this->getDomainForUrl($effectiveUrl);
            $this->logger->info(sprintf(
                'ExternalLinktype detected HTTP status code: %d for url=<%s> (domain=<%s>) => effective url=<%s> (domain=<%s>) stop checking this domain in this cycle. Retry-After: %s',
                $linkTargetResponse->getErrno(), $url, $this->domain, $effectiveUrl, $effectiveDomain, $retryAfter > 0 ? date('Y-m-d H:i:s', $retryAfter) : 'N/A'
            ));
            $this->crawlDelay->stopChecking($effectiveDomain, $retryAfter, $linkTargetResponse->getErrno() === 429 ? LinkTargetResponse::REASON_CANNOT_CHECK_429 : LinkTargetResponse::REASON_CANNOT_CHECK_503);
            $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_CANNOT_CHECK);
            $linkTargetResponse->setReasonCannotCheck($linkTargetResponse->getErrno() === 429 ? LinkTargetResponse::REASON_CANNOT_CHECK_429 : LinkTargetResponse::REASON_CANNOT_CHECK_503);
        }

        return $linkTargetResponse;
    }

    protected function isCombinedErrorNonCheckable(LinkTargetResponse $linkTargetResponse): bool
    {
        if (!$this->configuration) {
            return false;
        }
        $combinedErrorNonCheckableMatch = $this->configuration->getCombinedErrorNonCheckableMatch();
        $combinedError = $linkTargetResponse->getCombinedError(true);
        if (!$combinedErrorNonCheckableMatch || !$combinedError) {
            return false;
        }

        if (str_starts_with($combinedErrorNonCheckableMatch, 'regex:')) {
            $regex = trim(substr($combinedErrorNonCheckableMatch, strlen('regex:')));
            if (@preg_match($regex, $combinedError)) { // Suppress errors from invalid regex from config
                return true;
            }
        } else {
            foreach (GeneralUtility::trimExplode(',', $combinedErrorNonCheckableMatch, true) as $match) {
                if (str_starts_with($combinedError, $match)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function getErrorMessage(?LinkTargetResponse $linkTargetResponse): string
    {
        if ($linkTargetResponse === null) {
            return '';
        }
        $lang = $this->getLanguageService();
        $errorType = $linkTargetResponse->getErrorType();
        $errno = $linkTargetResponse->getErrno();
        $exception = $linkTargetResponse->getExceptionMessage();
        $message = '';

        switch ($errorType) {
            case self::ERROR_TYPE_HTTP_STATUS_CODE:
                $lllKey = 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.error.httpstatus.' . $errno;
                $_message = $lang->sL($lllKey);
                if ($_message && $_message !== $lllKey) {
                    $message = $_message;
                } else {
                    $lllGenericKey = 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.error.httpstatus.general';
                    $_genericMessage = $lang->sL($lllGenericKey);
                    if ($_genericMessage && $_genericMessage !== $lllGenericKey) {
                         $message = sprintf($_genericMessage, (string)$errno);
                    } else {
                        $message = "HTTP status: {$errno}"; // Fallback
                    }
                }
                break;
            case self::ERROR_TYPE_LOWLEVEL_LIBCURL_ERRNO:
                $lllKey = 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.error.libcurl.' . $errno;
                $_message = $lang->sL($lllKey);
                if ($_message && $_message !== $lllKey) {
                    $message = $_message;
                } else {
                    $lllGenericKey = 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.error.networkexception';
                    $message = $lang->sL($lllGenericKey) ?: "Network error"; // Fallback
                    if ($exception !== '') {
                        $message .= ' (' . htmlspecialchars($exception) . ')';
                    }
                }
                break;
            case self::ERROR_TYPE_TOO_MANY_REDIRECTS:
                 $lllKey = 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_module.xlf:list.report.error.tooManyRedirects';
                 $message = $lang->sL($lllKey) ?: "Too many redirects";
                 break;
            default:
                $message = htmlspecialchars($exception ?: $errorType ?: 'Unknown error');
        }
        return $message;
    }

    public function fetchType(array $value, string $type, string $key): string
    {
        $tokenValue = $value['tokenValue'] ?? '';
        if ($tokenValue === '' || !is_string($tokenValue)) {
            return $type;
        }
        preg_match_all('/((?:http|https))(?::\\/\\/)(?:[^\\s<>]+)/i', $tokenValue, $urls, PREG_PATTERN_ORDER);
        if (!empty($urls[0][0])) {
            $type = 'external';
        }
        return $type;
    }

    protected function preprocessUrl(string $url): string
    {
        $url = html_entity_decode($url);
        $parts = parse_url($url);
        $host = (string)($parts['host'] ?? '');
        if ($host !== '') {
            try {
                // Suppress errors for idn_to_ascii, as it can throw exceptions for invalid domains
                $newDomain = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if ($newDomain !== false && strcmp($host, $newDomain) !== 0) {
                    $parts['host'] = $newDomain;
                    $url = HttpUtility::buildUrl($parts) ?? $url; // Fallback to original URL on build failure
                }
            } catch (\Exception | \Throwable $e) {
                $this->logger->warning('Failed to convert host to Punycode: ' . $host . ' - ' . $e->getMessage());
            }
        }
        $this->domain = $parts['host'] ?? '';
        return $url;
    }

    protected function getDomainForUrl(string $url): string
    {
        $url = html_entity_decode($url);
        $parts = parse_url($url);
        $host = (string)($parts['host'] ?? '');
        if ($host !== '') {
            try {
                $newDomain = @idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                 if ($newDomain !== false && strcmp($host, $newDomain) !== 0) {
                    return $newDomain;
                }
            } catch (\Exception | \Throwable $e) {
                 $this->logger->warning('Failed to convert host to Punycode for domain extraction: ' . $host . ' - ' . $e->getMessage());
            }
        }
        return $host;
    }

    /**
     * Helper to get LanguageService.
     */
    protected function getLanguageService(): \TYPO3\CMS\Core\Localization\LanguageService
    {
        if (isset($GLOBALS['LANG']) && $GLOBALS['LANG'] instanceof \TYPO3\CMS\Core\Localization\LanguageService) {
            return $GLOBALS['LANG'];
        }
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageService::class);
    }
}
