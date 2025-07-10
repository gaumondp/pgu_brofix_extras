<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Tests\Unit\Xclass;

use Gaumondp\PguBrofixExtras\Xclass\BrofixExternalLinktype;
use Sypets\Brofix\CheckLinks\CrawlDelay;
use Sypets\Brofix\CheckLinks\ExcludeLinkTarget;
use Sypets\Brofix\CheckLinks\LinkTargetCache\LinkTargetCacheInterface;
use Sypets\Brofix\CheckLinks\LinkTargetResponse\LinkTargetResponse;
use Sypets\Brofix\Configuration\Configuration as BrofixConfiguration; // Use alias to avoid name collision
use Psr\Http\Message\ResponseInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use TYPO3\CMS\Core\Http\RequestTransferException;


class BrofixExternalLinktypeTest extends UnitTestCase
{
    use ProphecyTrait;

    protected bool $resetSingletonInstances = true;

    private ObjectProphecy $requestFactoryProphecy;
    private ObjectProphecy $excludeLinkTargetProphecy;
    private ObjectProphecy $linkTargetCacheProphecy;
    private ObjectProphecy $crawlDelayProphecy;
    private ObjectProphecy $brofixConfigurationProphecy; // Mock for original Brofix Configuration
    private ObjectProphecy $loggerProphecy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestFactoryProphecy = $this->prophesize(RequestFactory::class);
        $this->excludeLinkTargetProphecy = $this->prophesize(ExcludeLinkTarget::class);
        $this->linkTargetCacheProphecy = $this->prophesize(LinkTargetCacheInterface::class);
        $this->crawlDelayProphecy = $this->prophesize(CrawlDelay::class);
        $this->brofixConfigurationProphecy = $this->prophesize(BrofixConfiguration::class);
        $this->loggerProphecy = $this->prophesize(Logger::class);

        // Default mock behaviors for BrofixConfiguration
        $this->brofixConfigurationProphecy->getLinktypesConfigExternalHeaders()->willReturn(['User-Agent' => 'Test Agent', 'Accept' => '*/*']);
        $this->brofixConfigurationProphecy->getLinktypesConfigExternalTimeout()->willReturn(5);
        $this->brofixConfigurationProphecy->getLinktypesConfigExternalConnectTimeout()->willReturn(3);
        $this->brofixConfigurationProphecy->isLinktypesConfigExternalSslVerifyPeer()->willReturn(true);
        $this->brofixConfigurationProphecy->getLinktypesConfigExternalRedirects()->willReturn(5);
        $this->brofixConfigurationProphecy->getLinkTargetCacheExpires(Argument::any())->willReturn(3600);
        $this->brofixConfigurationProphecy->getCombinedErrorNonCheckableMatch()->willReturn('');
        $this->brofixConfigurationProphecy->getExcludeLinkTargetStoragePid()->willReturn(0);


        // Default mock behaviors for other dependencies
        $this->excludeLinkTargetProphecy->isExcluded(Argument::any(), Argument::any())->willReturn(false);
        $this->excludeLinkTargetProphecy->setExcludeLinkTargetsPid(Argument::any())->willReturn(null);
        $this->linkTargetCacheProphecy->getUrlResponseForUrl(Argument::any(), Argument::any(), Argument::any())->willReturn(null);
        $this->linkTargetCacheProphecy->setResult(Argument::any(), Argument::any(), Argument::any())->will(function() {});
        $this->crawlDelayProphecy->crawlDelay(Argument::any())->willReturn(true);
        $this->crawlDelayProphecy->setLastCheckedTime(Argument::any())->willReturn(true);
        $this->crawlDelayProphecy->setConfiguration(Argument::any())->willReturn(null);


        // Ensure Brofix constants are available (they should be, as brofix is a dependency)
        // If not, this indicates an issue with test setup or assumptions about Brofix's availability in test context.
        if (!defined('Sypets\Brofix\CheckLinks\LinkTargetResponse\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE')) {
            // This is a fallback, ideally Brofix's constants are loaded.
            define('Sypets\Brofix\CheckLinks\LinkTargetResponse\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE', 'cloudflare');
        }
    }

    private function createSubject(): BrofixExternalLinktype
    {
        // The XCLASS extends the original, so its constructor signature should match.
        // Brofix's ExternalLinktype constructor takes optional arguments.
        $subject = new BrofixExternalLinktype(
            $this->requestFactoryProphecy->reveal(),
            $this->excludeLinkTargetProphecy->reveal(),
            $this->linkTargetCacheProphecy->reveal(),
            $this->crawlDelayProphecy->reveal()
        );
        $subject->setConfiguration($this->brofixConfigurationProphecy->reveal());
        $subject->setLogger($this->loggerProphecy->reveal());
        return $subject;
    }

    /**
     * @test
     */
    public function requestUrlDetectsCloudflareServerHeaderAndSetsStatus(): void
    {
        $subject = $this->createSubject();
        $url = 'https://cloudflare.example.com';

        $responseProphecy = $this->prophesize(ResponseInterface::class);
        $responseProphecy->getStatusCode()->willReturn(200);
        $responseProphecy->getHeaderLine('Server')->willReturn('cloudflare');
        $responseProphecy->getHeaders()->willReturn(['Server' => ['cloudflare']]);


        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->willReturn($responseProphecy->reveal());

        // Use reflection to call protected method requestUrl
        $reflection = new \ReflectionClass(BrofixExternalLinktype::class);
        $method = $reflection->getMethod('requestUrl');
        $method->setAccessible(true);
        $linkTargetResponse = $method->invokeArgs($subject, [$url, 'GET', []]);

        self::assertInstanceOf(LinkTargetResponse::class, $linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_UNKNOWN, $linkTargetResponse->getStatus());
        self::assertSame(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE, $linkTargetResponse->getReasonCannotCheck());
    }

    /**
     * @test
     */
    public function requestUrlHandlesNormalServerCorrectly(): void
    {
        $subject = $this->createSubject();
        $url = 'https://normal.example.com';

        $responseProphecy = $this->prophesize(ResponseInterface::class);
        $responseProphecy->getStatusCode()->willReturn(200);
        $responseProphecy->getHeaderLine('Server')->willReturn('nginx');
        $responseProphecy->getHeaders()->willReturn(['Server' => ['nginx']]);

        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->willReturn($responseProphecy->reveal());

        // Mock for HEAD request as checkLink calls it first
        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willReturn($responseProphecy->reveal());


        $reflection = new \ReflectionClass(BrofixExternalLinktype::class);
        $method = $reflection->getMethod('requestUrl');
        $method->setAccessible(true);
        $linkTargetResponse = $method->invokeArgs($subject, [$url, 'GET', []]);

        self::assertInstanceOf(LinkTargetResponse::class, $linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_OK, $linkTargetResponse->getStatus());
    }

    /**
     * @test
     */
    public function checkLinkPrioritizesCloudflareDetectionViaHead(): void
    {
        $subject = $this->createSubject();
        $url = 'https://head.cloudflare.com';

        $headResponseProphecy = $this->prophesize(ResponseInterface::class);
        $headResponseProphecy->getStatusCode()->willReturn(200); // Could be 403 too
        $headResponseProphecy->getHeaderLine('Server')->willReturn('Cloudflare');
        $headResponseProphecy->getHeaders()->willReturn(['Server' => ['Cloudflare']]);

        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willReturn($headResponseProphecy->reveal())->shouldBeCalledOnce();

        // GET should not be called if HEAD already determined Cloudflare
        $this->requestFactoryProphecy->request($url, 'GET', Argument::any())->shouldNotBeCalled();

        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_UNKNOWN, $linkTargetResponse->getStatus());
        self::assertSame(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE, $linkTargetResponse->getReasonCannotCheck());
    }

    /**
     * @test
     */
    public function checkLinkFallsBackToGetForCloudflareDetection(): void
    {
        $subject = $this->createSubject();
        $url = 'https://get.cloudflare.com';

        // HEAD is normal
        $headResponseProphecy = $this->prophesize(ResponseInterface::class);
        $headResponseProphecy->getStatusCode()->willReturn(200);
        $headResponseProphecy->getHeaderLine('Server')->willReturn('nginx');
        $headResponseProphecy->getHeaders()->willReturn(['Server' => ['nginx']]);
        $this->requestFactoryProphecy->request($url, 'HEAD', Argument::type('array'))
            ->willReturn($headResponseProphecy->reveal())->shouldBeCalledOnce();

        // GET shows Cloudflare
        $getResponseProphecy = $this->prophesize(ResponseInterface::class);
        $getResponseProphecy->getStatusCode()->willReturn(200);
        $getResponseProphecy->getHeaderLine('Server')->willReturn('cloudflare');
        $getResponseProphecy->getHeaders()->willReturn(['Server' => ['cloudflare']]);
        $this->requestFactoryProphecy->request($url, 'GET', Argument::type('array'))
            ->willReturn($getResponseProphecy->reveal())->shouldBeCalledOnce();

        $linkTargetResponse = $subject->checkLink($url, []);

        self::assertNotNull($linkTargetResponse);
        self::assertSame(LinkTargetResponse::RESULT_UNKNOWN, $linkTargetResponse->getStatus());
        self::assertSame(LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE, $linkTargetResponse->getReasonCannotCheck());
    }
}
