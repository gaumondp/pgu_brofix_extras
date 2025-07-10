<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Xclass;

use Psr\Http\Message\ResponseInterface as PsrResponseInterface; // Alias to avoid conflict if TYPO3 ResponseInterface is different
use Sypets\Brofix\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Sypets\Brofix\Linktype\ExternalLinktype as BrofixExternalLinktype; // Original class
use TYPO3\CMS\Core\Http\RequestFactory;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;

/**
 * XCLASS extension for Sypets\Brofix\Linktype\ExternalLinktype
 * to add specific Cloudflare detection logic.
 */
class BrofixExternalLinktype extends BrofixExternalLinktype
{
    /**
     * Override requestUrl to check for Cloudflare server header.
     * The rest of the method is largely a copy of the original, with the
     * Cloudflare check integrated.
     *
     * @param string $url
     * @param string $method
     * @param mixed[] $options
     * @return LinkTargetResponse
     */
    protected function requestUrl(string $url, string $method, array $options): LinkTargetResponse
    {
        $responseHeaders = []; // Initialize to ensure it's always an array
        /** @var LinkTargetResponse $linkTargetResponse */
        $linkTargetResponse = null; // Initialize

        try {
            $this->redirects = []; // Reset redirects for this specific request
            /** @var PsrResponseInterface $response */
            $response = $this->requestFactory->request($url, $method, $options);
            $responseHeaders = $response->getHeaders();

            // ==> PGU Cloudflare Extras: Explicit Cloudflare Server Header Check <==
            $serverHeader = $response->getHeaderLine('Server');
            if (stripos($serverHeader, 'cloudflare') !== false) {
                $this->logger->debug('Cloudflare detected via Server header for URL: ' . $url . ' (Method: ' . $method . ')');
                $linkTargetResponse = LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_UNKNOWN);
                $linkTargetResponse->setReasonCannotCheck(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE); // Use Brofix's constant
                // If Cloudflare itself returns a server error, reflect that, but keep main status as UNKNOWN due to Cloudflare
                if ($response->getStatusCode() >= 500) {
                    $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                    $linkTargetResponse->setErrno($response->getStatusCode());
                } elseif ($response->getStatusCode() >= 400 && $response->getStatusCode() !== 403) {
                    // Reflect other client errors from Cloudflare if not a typical 403 block
                    $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                    $linkTargetResponse->setErrno($response->getStatusCode());
                }
                // For Cloudflare + 2xx or 403, status remains RESULT_UNKNOWN with Cloudflare reason.
                return $linkTargetResponse; // Return early as Cloudflare is detected
            }
            // ==> End PGU Cloudflare Extras Check <==

            // Original Brofix logic if not Cloudflare by Server header
            if ($response->getStatusCode() >= 300) {
                $linkTargetResponse = LinkTargetResponse::createInstanceByError(
                    self::ERROR_TYPE_HTTP_STATUS_CODE,
                    $response->getStatusCode()
                );
            } else {
                $linkTargetResponse = LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_OK);
                // The original logic for RESULT_OK_BUT_MAYBE_CHECK_GET was in checkLink,
                // which calls this method. If HEAD is OK here, checkLink will then call with GET.
            }
        } catch (TooManyRedirectsException $e) {
            $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_TOO_MANY_REDIRECTS, 0, '', $e->getMessage());
        } catch (ClientException | ServerException $e) {
            $psrResponse = $e->getResponse();
            if ($psrResponse) {
                $responseHeaders = $psrResponse->getHeaders();
                // ==> PGU Cloudflare Extras: Check Server header in error response <==
                $serverHeader = $psrResponse->getHeaderLine('Server');
                if (stripos($serverHeader, 'cloudflare') !== false) {
                    $this->logger->debug('Cloudflare detected via Server header in error response for URL: ' . $url . ' (Method: ' . $method . ')');
                    $linkTargetResponse = LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_UNKNOWN);
                    $linkTargetResponse->setReasonCannotCheck(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);
                    if ($psrResponse->getStatusCode() >= 500) {
                        $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                        $linkTargetResponse->setErrno($psrResponse->getStatusCode());
                    } elseif ($psrResponse->getStatusCode() >= 400 && $psrResponse->getStatusCode() !== 403) {
                        $linkTargetResponse->setErrorType(self::ERROR_TYPE_HTTP_STATUS_CODE);
                        $linkTargetResponse->setErrno($psrResponse->getStatusCode());
                    }
                    // For Cloudflare + 403 error, keep RESULT_UNKNOWN and REASON_CANNOT_CHECK_CLOUDFLARE
                } else {
                    // Original Brofix error handling
                    $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_HTTP_STATUS_CODE, $psrResponse->getStatusCode());
                }
                // ==> End PGU Cloudflare Extras Check <==
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

        // Ensure $linkTargetResponse is initialized if an exception occurred before it was.
        if ($linkTargetResponse === null) {
             $this->logger->warning('LinkTargetResponse was not initialized in requestUrl for ' . $url . ' (Method: ' . $method . '). This should not happen.');
             $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNKNOWN, 0, '', 'Internal error during link check.');
        }


        if (!empty($this->redirects)) {
            $linkTargetResponse->setRedirects($this->redirects);
        }
        // Set effective URL, Guzzle usually provides this on the response from the last request in a redirect chain.
        // If not directly available, it might need to be tracked via on_redirect. For now, assume $url is final if no redirects.
        $finalUrlAfterRedirects = $url;
        if (!empty($this->redirects)) {
            $lastRedirect = end($this->redirects);
            $finalUrlAfterRedirects = $lastRedirect['to'] ?? $url;
        }
        $linkTargetResponse->setEffectiveUrl($finalUrlAfterRedirects);


        // Original Brofix logic for combinedErrorNonCheckable and cf-* headers,
        // but only if Cloudflare wasn't already detected by Server header.
        if (!($linkTargetResponse->getStatus() === LinkTargetResponse::RESULT_UNKNOWN &&
              $linkTargetResponse->getReasonCannotCheck() === LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE)) {
            if ($method === 'GET' && $this->isCombinedErrorNonCheckable($linkTargetResponse)) {
                $linkTargetResponse->setStatus(LinkTargetResponse::RESULT_CANNOT_CHECK);
                $isCfHeaderPresent = false;
                foreach ($responseHeaders as $headerName => $headerValueArray) {
                    if (is_string($headerName) && str_starts_with(mb_strtolower($headerName), 'cf-')) {
                        $isCfHeaderPresent = true;
                        break;
                    }
                }
                if ($isCfHeaderPresent) {
                    // This might set REASON_CANNOT_CHECK_CLOUDFLARE if cf-* headers are found,
                    // even if Server header didn't say "cloudflare".
                    $linkTargetResponse->setReasonCannotCheck(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE);
                }
            }
        }

        // Original Brofix logic for 429/503
        if ($method === 'GET' && $linkTargetResponse->getErrorType() === self::ERROR_TYPE_HTTP_STATUS_CODE
            && ($linkTargetResponse->getErrno() === 429 || $linkTargetResponse->getErrno() === 503)) {
            // ... (copied from original Brofix ExternalLinktype, with namespace/logger adjustments)
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
            $effectiveDomain = $this->getDomainForUrl($effectiveUrl); // Ensure this method is accessible (it's protected in parent)
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

    // We also need to override checkLink to refine the HEAD vs GET logic slightly,
    // ensuring that if HEAD detects cloudflare, we don't bother with GET.
    // And if HEAD is OK but not Cloudflare, the GET call (which will happen due to RESULT_OK_BUT_MAYBE_CHECK_GET)
    // gets a chance to detect Cloudflare.

    /**
     * @inheritDoc
     */
    public function checkLink(string $origUrl, array $softRefEntry, int $flags = 0): ?LinkTargetResponse
    {
        if (!$this->configuration) {
            $this->logger->error('ExternalLinktype configuration not initialized in checkLink.');
            return LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNKNOWN, 0, '', 'Configuration not set');
        }

        if ($this->excludeLinkTarget->isExcluded($origUrl, 'external')) {
            return LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_EXCLUDED);
        }

        if ((($flags & self::CHECK_LINK_FLAG_NO_CACHE) === 0)) {
            $urlResponse = $this->linkTargetCache->getUrlResponseForUrl(
                $origUrl,
                'external',
                $this->configuration->getLinkTargetCacheExpires($flags)
            );
            if ($urlResponse) {
                if ((($flags & self::CHECK_LINK_FLAG_NO_CACHE_ON_ERROR) !== 0) && $urlResponse->isError()) {
                    // Continue checking
                } else {
                    return $urlResponse;
                }
            }
        }

        // ... (CookieJar, onRedirect, options setup as in parent)
        $cookieJar = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\GuzzleHttp\Cookie\CookieJar::class);
        $this->redirects = [];
        $onRedirect = function (
            \Psr\Http\Message\RequestInterface $request,
            \Psr\Http\Message\ResponseInterface $response,
            \Psr\Http\Message\UriInterface $uri
        ) {
            $this->redirects[] = [
                'from' => (string)$request->getUri(),
                'to' => (string)$uri,
            ];
        };
        $options = [
            'cookies' => $cookieJar,
            'allow_redirects' => [
                'strict' => true,
                'referer' => true,
                'max' => $this->configuration->getLinktypesConfigExternalRedirects(),
                'on_redirect' => $onRedirect,
            ],
            'headers'         => $this->configuration->getLinktypesConfigExternalHeaders(),
            'timeout' => $this->configuration->getLinktypesConfigExternalTimeout(),
            'connect_timeout' => $this->configuration->getLinktypesConfigExternalConnectTimeout(),
            'verify' => $this->configuration->isLinktypesConfigExternalSslVerifyPeer(),
        ];


        $url = $this->preprocessUrl($origUrl); // This sets $this->domain
        $linkTargetResponse = null;

        if (!empty($url)) {
            if ((($flags & self::CHECK_LINK_FLAG_NO_CRAWL_DELAY) === 0)) {
                if (!$this->crawlDelay->crawlDelay($this->domain)) {
                    return null;
                }
            }

            // First, try HEAD request
            $linkTargetResponseHead = $this->requestUrl($url, 'HEAD', $options);

            // If HEAD detected Cloudflare, that's our definitive answer.
            if ($linkTargetResponseHead->getStatus() === LinkTargetResponse::RESULT_UNKNOWN &&
                $linkTargetResponseHead->getReasonCannotCheck() === LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE) {
                $linkTargetResponse = $linkTargetResponseHead;
            } else {
                // If HEAD was an error (but not Cloudflare) OR if HEAD was OK (which our overridden requestUrl might return as RESULT_OK_BUT_MAYBE_CHECK_GET or just RESULT_OK)
                // Then, try GET request.
                // The original Brofix logic: if ($linkTargetResponse->isError()) { // try GET }
                // We refine this: if HEAD didn't give a final Cloudflare status, or if it errored, try GET.
                $this->logger->debug('HEAD request for ' . $url . ' status ' . $linkTargetResponseHead->getStatus() . '. Proceeding with GET.');
                $getOptions = $options;
                // Add Range header for GET as in original Brofix, but ensure it's not in HEAD options if not desired
                $getOptions['headers']['Range'] = 'bytes=0-4048';
                unset($getOptions['headers']['range']); // Ensure no lowercase 'range' if 'Range' is set by config

                $linkTargetResponseGet = $this->requestUrl($url, 'GET', $getOptions);

                // Prioritize GET if it found Cloudflare.
                if ($linkTargetResponseGet->getStatus() === LinkTargetResponse::RESULT_UNKNOWN &&
                    $linkTargetResponseGet->getReasonCannotCheck() === LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE) {
                    $linkTargetResponse = $linkTargetResponseGet;
                } else {
                    // If GET didn't find Cloudflare, and HEAD was an error, use HEAD's error.
                    // If HEAD was OK, and GET is also OK (and not cloudflare), use GET's OK.
                    // If HEAD was OK, and GET is an error, use GET's error.
                    $linkTargetResponse = $linkTargetResponseHead->isError() && !$linkTargetResponseGet->isError() ? $linkTargetResponseHead : $linkTargetResponseGet;
                     if ($linkTargetResponseHead->isError() && $linkTargetResponseGet->isError()) {
                        // Both errored, GET might have more info (e.g. from combinedErrorNonCheckable)
                        $linkTargetResponse = $linkTargetResponseGet;
                    } else if (!$linkTargetResponseHead->isError() && $linkTargetResponseGet->isError()){
                         // HEAD was ok, GET errored. Report GET error.
                         $linkTargetResponse = $linkTargetResponseGet;
                    } else if (!$linkTargetResponseHead->isError() && !$linkTargetResponseGet->isError()){
                        // Both OK, use GET. (HEAD might have been RESULT_OK_BUT_MAYBE_CHECK_GET)
                        $linkTargetResponse = $linkTargetResponseGet;
                    } else { // Default to HEAD if logic is complex
                        $linkTargetResponse = $linkTargetResponseHead;
                    }

                }
            }
            $this->crawlDelay->setLastCheckedTime($this->domain);
        } else {
            $linkTargetResponse = LinkTargetResponse::createInstanceByError(self::ERROR_TYPE_UNABLE_TO_PARSE, 0, '', 'URL became empty after preprocessing.');
        }

        if ($linkTargetResponse) {
            $this->insertIntoLinkTargetCache($origUrl, $linkTargetResponse);
        }
        return $linkTargetResponse;
    }
}
