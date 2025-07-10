<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Tests\Unit\Linktype;

use Gaumondp\PguBrofixExtras\CheckLinks\CrawlDelay;
use Gaumondp\PguBrofixExtras\CheckLinks\ExcludeLinkTarget;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetCache\LinkTargetCacheInterface;
use Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Gaumondp\PguBrofixExtras\Configuration\Configuration;
use Gaumondp\PguBrofixExtras\Linktype\ExternalLinktype;
use GuzzleHttp\Psr7\Response as GuzzleResponse; // Use a concrete PSR-7 Response for mocking if easier
use Psr\Http\Message\ResponseInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use TYPO3\CMS\Core\Http\RequestTransferException; // For simulating network errors


class ExternalLinktypeTest extends UnitTestCase
{
    use ProphecyTrait;

    protected bool $resetSingletonInstances = true;

    private ObjectProphecy $requestFactoryProphecy;
    private ObjectProphecy $excludeLinkTargetProphecy;
    private ObjectProphecy $linkTargetCacheProphecy;
    private ObjectProphecy $crawlDelayProphecy;
    private ObjectProphecy $configurationProphecy;
    private ObjectProphecy $loggerProphecy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactoryProphecy = $this->prophesize(RequestFactory::class);
        $this->excludeLinkTargetProphecy = $this->prophesize(ExcludeLinkTarget::class);
        $this->linkTargetCacheProphecy = $this->prophesize(LinkTargetCacheInterface::class);
        $this->crawlDelayProphecy = $this->prophesize(CrawlDelay::class);
        $this->configurationProphecy = $this->prophesize(Configuration::class);
        $this->loggerProphecy = $this->prophesize(Logger::class);

        // Default mock behaviors for configuration
        $this->configurationProphecy->getLinktypesConfigExternalHeaders()->willReturn(['User-Agent' => 'Test Agent', 'Accept' => '*/*']);
        $this->configurationProphecy->getLinktypesConfigExternalTimeout()->willReturn(5);
        $this->configurationProphecy->getLinktypesConfigExternalConnectTimeout()->willReturn(3);
        $this->configurationProphecy->isLinktypesConfigExternalSslVerifyPeer()->willReturn(true);
        $this->configurationProphecy->getLinktypesConfigExternalRedirects()->willReturn(5);
        $this->configurationProphecy->getLinkTargetCacheExpires(Argument::any())->willReturn(3600); // Default cache expiry
        $this->configurationProphecy->getCombinedErrorNonCheckableMatch()->willReturn(''); // Default: no specific non-checkable patterns

        // Default mock behaviors for dependencies
        $this->excludeLinkTargetProphecy->isExcluded(Argument::any(), Argument::any())->willReturn(false);
        $this->linkTargetCacheProphecy->getUrlResponseForUrl(Argument::any(), Argument::any(), Argument::any())->willReturn(null);
        $this->linkTargetCacheProphecy->setResult(Argument::any(), Argument::any(), Argument::any())->will(function() {});
        $this->crawlDelayProphecy->crawlDelay(Argument::any())->willReturn(true);
        $this->crawlDelayProphecy->setLastCheckedTime(Argument::any())->willReturn(true);
        $this->crawlDelayProphecy->setConfiguration(Argument::any())->will(function() {});
        $this->excludeLinkTargetProphecy->setExcludeLinkTargetsPid(Argument::any())->will(function() {});


        // Define the Cloudflare reason constant if not already defined (mimicking runtime)
        // This should ideally be defined in one central place in the extension for tests to pick up.
        if (!defined('TYPO3\CMS\Linkvalidator\LinkTarget\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE')) {
            define('TYPO3\CMS\Linkvalidator\LinkTarget\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE', 'Link is behind Cloudflare');
        }
    }

    private function createSubject(): ExternalLinktype
    {
        $subject = new ExternalLinktype(
            $this->requestFactoryProphecy->reveal(),
            $this->excludeLinkTargetProphecy->reveal(),
            $this->linkTargetCacheProphecy->reveal(),
            $this->crawlDelayProphecy->reveal()
        );
        $subject->setConfiguration($this->configurationProphecy->reveal());
        // Logger can be set via LoggerAwareTrait, or manually if needed for specific tests
        $subject->setLogger($this->loggerProphecy->reveal());
        return $subject;
    }

    /**
     * @test
     */
    public function checkLinkDetectsCloudflareServerViaHeadRequest(): void
    {
        $url = 'https://www.cloudflare-protected.com';

        // Mock HEAD response (Cloudflare detected)
        $headResponseProphecy = $this->prophesize(ResponseInterface::class);
        $headResponseProphecy->getStatusCode()->willReturn(200); // Or 403, etc.
        $headResponseProphecy->getHeaderLine('Server')->willReturn('cloudflare');
        $headResponseProphecy->getHeaders()->willReturn(['Server' => ['cloudflare']]);


        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willReturn($headResponseProphecy->reveal())->shouldBeCalledOnce();

        // GET request should NOT be called if HEAD detects Cloudflare and returns early
        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->shouldNotBeCalled();


        $subject = $this->createSubject();
        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_UNKNOWN, $linkTargetResponse->getStatus());
        self::assertSame(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE, $linkTargetResponse->getReasonCannotCheck());
    }

    /**
     * @test
     */
    public function checkLinkDetectsCloudflareServerViaGetRequestIfHeadIsInconclusive(): void
    {
        $url = 'https://www.cloudflare-protected-on-get.com';

        // Mock HEAD response (Normal server, or inconclusive for Cloudflare)
        $headResponseProphecy = $this->prophesize(ResponseInterface::class);
        $headResponseProphecy->getStatusCode()->willReturn(200);
        $headResponseProphecy->getHeaderLine('Server')->willReturn('nginx');
        $headResponseProphecy->getHeaders()->willReturn(['Server' => ['nginx']]);


        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willReturn($headResponseProphecy->reveal())->shouldBeCalledOnce();

        // Mock GET response (Cloudflare detected)
        $getResponseProphecy = $this->prophesize(ResponseInterface::class);
        $getResponseProphecy->getStatusCode()->willReturn(200); // Or 403
        $getResponseProphecy->getHeaderLine('Server')->willReturn('cloudflare');
        $getResponseProphecy->getHeaders()->willReturn(['Server' => ['cloudflare']]);


        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->willReturn($getResponseProphecy->reveal())->shouldBeCalledOnce();

        $subject = $this->createSubject();
        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_UNKNOWN, $linkTargetResponse->getStatus());
        self::assertSame(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE, $linkTargetResponse->getReasonCannotCheck());
    }


    /**
     * @test
     */
    public function checkLinkReturnsOkForNormalServer(): void
    {
        $url = 'https://www.example.com';

        $headResponseProphecy = $this->prophesize(ResponseInterface::class);
        $headResponseProphecy->getStatusCode()->willReturn(200);
        $headResponseProphecy->getHeaderLine('Server')->willReturn('apache');
         $headResponseProphecy->getHeaders()->willReturn(['Server' => ['apache']]);


        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willReturn($headResponseProphecy->reveal());
        // GET should not be called if HEAD is successful and not inconclusive for Cloudflare
        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->shouldNotBeCalled();


        $subject = $this->createSubject();
        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        // Depending on how RESULT_OK_BUT_MAYBE_CHECK_GET is handled, this might be RESULT_OK
        // For this test, we assume that if HEAD is 200 and not Cloudflare, it's considered final OK.
        // The ExternalLinktype has logic for RESULT_OK_BUT_MAYBE_CHECK_GET.
        // If HEAD is 200 and server is not cloudflare, it sets RESULT_OK_BUT_MAYBE_CHECK_GET.
        // Then checkLink() calls GET.
        // So we need to mock GET response as well for this case.

        $getResponseProphecy = $this->prophesize(ResponseInterface::class);
        $getResponseProphecy->getStatusCode()->willReturn(200);
        $getResponseProphecy->getHeaderLine('Server')->willReturn('apache');
        $getResponseProphecy->getHeaders()->willReturn(['Server' => ['apache']]);
        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->willReturn($getResponseProphecy->reveal());


        $linkTargetResponse = $subject->checkLink($url, []); // Re-call with GET mock in place.

        self::assertSame(LinkTargetResponse::RESULT_OK, $linkTargetResponse->getStatus());
    }

    /**
     * @test
     */
    public function checkLinkHandlesHttp404Error(): void
    {
        $url = 'https://www.example.com/notfound';

        // Mock HEAD response (404)
        $headErrorResponse = new GuzzleResponse(404, ['Server' => ['nginx']]);
        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willThrow(new RequestTransferException('Not Found', 0, null, ['response' => $headErrorResponse]));


        // Mock GET response (404)
        $getErrorResponse = new GuzzleResponse(404, ['Server' => ['nginx']]);
        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
             ->willThrow(new RequestTransferException('Not Found', 0, null, ['response' => $getErrorResponse]));


        $subject = $this->createSubject();
        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertTrue($linkTargetResponse->isError());
        self::assertSame(LinkTargetResponse::RESULT_BROKEN, $linkTargetResponse->getStatus());
        self::assertSame(ExternalLinktype::ERROR_TYPE_HTTP_STATUS_CODE, $linkTargetResponse->getErrorType());
        self::assertSame(404, $linkTargetResponse->getErrno());
    }

    /**
     * @test
     */
    public function checkLinkUsesCacheWhenAvailable(): void
    {
        $url = 'https://www.cached-url.com';
        $cachedResponse = LinkTargetResponse::createInstanceByStatus(LinkTargetResponse::RESULT_OK);
        $cachedResponse->setLastChecked(time() - 100); // Recently cached

        $this->linkTargetCacheProphecy->getUrlResponseForUrl($url, 'external', Argument::type('integer'))
            ->willReturn($cachedResponse)->shouldBeCalledOnce();

        // RequestFactory should not be called if cache is hit
        $this->requestFactoryProphecy->request(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();

        $subject = $this->createSubject();
        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertSame($cachedResponse, $linkTargetResponse);
    }

     /**
     * @test
     */
    public function checkLinkCloudflareErrorResponseSetsUnknownStatus(): void
    {
        $url = 'https://www.cloudflare-error.com';

        // Mock HEAD response (e.g., 403 from Cloudflare)
        $headErrorResponse = new GuzzleResponse(403, ['Server' => ['cloudflare']]);
        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willThrow(new RequestTransferException('Forbidden', 0, null, ['response' => $headErrorResponse]));

        // GET should not be called if HEAD already identified Cloudflare, even with an error
        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->shouldNotBeCalled();

        $subject = $this->createSubject();
        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_UNKNOWN, $linkTargetResponse->getStatus(), "Status should be UNKNOWN for Cloudflare 403");
        self::assertSame(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE, $linkTargetResponse->getReasonCannotCheck());
        // Error type and errno might still be set from the Cloudflare response if it's not a typical block
        // self::assertSame(ExternalLinktype::ERROR_TYPE_HTTP_STATUS_CODE, $linkTargetResponse->getErrorType());
        // self::assertSame(403, $linkTargetResponse->getErrno());
        // The current ExternalLinktype logic prioritizes RESULT_UNKNOWN for Cloudflare 403s over BROKEN.
    }
}
