<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks;

use Gaumondp\PguBrofixExtras\Configuration\Configuration;

/**
 * Makes sure that there is a minimum wait time between checking
 * URLs. Specifically if the URLs are from the same domain.
 */
class CrawlDelay
{
    /**
     * Timestamps when an URL from the domain was last accessed.
     *
     * @var array<string,array{lastChecked: int, stopChecking: bool, retryAfter: int, reasonCannotCheck: string}>
     */
    protected array $domainInfo = [];
    protected ?Configuration $configuration = null; // Allow null until setConfiguration is called
    protected int $lastWaitSeconds = 0;

    public function __construct(?Configuration $configuration = null)
    {
        if ($configuration) {
            $this->configuration = $configuration;
        }
    }

    public function setConfiguration(Configuration $config): void
    {
        $this->configuration = $config;
    }

    /**
     * Make sure there is a delay between checks of the same domain
     *
     * @param string $domain
     * @return bool continue checking
     */
    public function crawlDelay(string $domain): bool
    {
        if (!$this->configuration) {
            // Configuration not set, cannot determine delay, so proceed without delay.
            // Consider logging this state.
            return true;
        }

        $this->lastWaitSeconds = 0;
        $current = time();

        if ($this->domainInfo[$domain]['stopChecking'] ?? false) {
            if (($this->domainInfo[$domain]['retryAfter'] ?? 0) > 0 && $current > $this->domainInfo[$domain]['retryAfter']) {
                $this->domainInfo[$domain]['stopChecking'] = false;
                $this->domainInfo[$domain]['retryAfter'] = 0;
                // Continue checking
            } else {
                // Still stop checking
                return false;
            }
        }

        $delaySeconds = $this->getCrawlDelayByDomain($domain);
        if ($delaySeconds === 0) {
            return true;
        }

        $lastTimestamp = (int)($this->domainInfo[$domain]['lastChecked'] ?? 0);

        if ($lastTimestamp > 0) { // Only calculate delay if there was a previous check
            $this->lastWaitSeconds = $delaySeconds - ($current - $lastTimestamp);
            if ($this->lastWaitSeconds > 0) {
                sleep($this->lastWaitSeconds);
            } else {
                $this->lastWaitSeconds = 0;
            }
        }

        $this->domainInfo[$domain]['lastChecked'] = time(); // Update to current time after potential sleep
        return true;
    }

    public function getLastWaitSeconds(): int
    {
        return $this->lastWaitSeconds;
    }

    /**
     * Store time for last check of this URL - used for crawlDelay.
     */
    public function setLastCheckedTime(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }
        $this->domainInfo[$domain]['lastChecked'] = time();
        return true;
    }

    protected function getCrawlDelayByDomain(string $domain): int
    {
        if (!$this->configuration || $domain === '') {
            return 0;
        }

        if ($this->configuration->isCrawlDelayNoDelayRegex()) {
            $regex = $this->configuration->getCrawlDelayNoDelayRegex();
            if ($regex && @preg_match($regex, $domain)) { // Suppress errors for invalid regex
                return 0;
            }
        } elseif (in_array($domain, $this->configuration->getCrawlDelayNodelayDomains(), true)) {
            return 0;
        }
        return $this->configuration->getCrawlDelaySeconds();
    }

    public function stopChecking(string $domain, int $retryAfter = 0, string $reasonCannotCheck = ''): void
    {
        $this->domainInfo[$domain]['stopChecking'] = true;
        if ($retryAfter > 0) {
            $this->domainInfo[$domain]['retryAfter'] = $retryAfter;
        }
        if ($reasonCannotCheck !== '') {
            $this->domainInfo[$domain]['reasonCannotCheck'] = $reasonCannotCheck;
        }
    }
}
