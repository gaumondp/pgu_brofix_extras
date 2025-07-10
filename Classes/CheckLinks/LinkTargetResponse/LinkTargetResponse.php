<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse;

// Define the constant here if it's not defined by the core or another part of this extension already.
// This ensures it's available when ExternalLinktype or LinkAnalyzer use it.
// It's better to have constants defined in one central place or intrinsically with the class using them.
if (!defined('TYPO3\CMS\Linkvalidator\LinkTarget\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE')) {
    // Note: This path might be misleading as this constant is specific to our extension's logic,
    // not necessarily a TYPO3 core Linkvalidator constant.
    // For true encapsulation, this constant would be Gaumondp\PguBrofixExtras\CheckLinks\LinkTargetResponse\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE
    // However, the PR's LinkAnalyzer used the TYPO3\CMS\... path.
    // Let's stick to the PR's implied intent for now, but acknowledge this is a bit messy.
    define('TYPO3\CMS\Linkvalidator\LinkTarget\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE', 'Link is behind Cloudflare');
}


class LinkTargetResponse
{
    public const RESULT_ALL = -1;
    public const RESULT_BROKEN = 1;
    public const RESULT_OK = 2;
    public const RESULT_CANNOT_CHECK = 3;
    public const RESULT_EXCLUDED = 4;
    public const RESULT_UNKNOWN = 5; // Used for Cloudflare

    // This constant is now defined globally above, but having it here as well for clarity within this class's context is fine.
    // It won't redefine if already defined. This is the constant used by sypets/brofix PR.
    public const REASON_CANNOT_CHECK_CLOUDFLARE = 'Link is behind Cloudflare'; // Value from global define

    public const REASON_CANNOT_CHECK_429 = '429:too many requests';
    public const REASON_CANNOT_CHECK_503 = '503:service unavailable';

    // Hypothetical status for ExternalLinktype to signal that HEAD was OK, but GET might be needed for Cloudflare.
    // This is internal to the linktype logic and not a final status to be stored.
    public const RESULT_OK_BUT_MAYBE_CHECK_GET = 102;


    protected int $status;
    protected int $lastChecked = 0;

    /** @var array<mixed> */
    protected array $custom = [];
    protected string $errorType = '';
    protected int $errno = 0;
    protected string $exceptionMessage = '';
    protected string $message = ''; // General message, potentially from error type
    protected string $reasonCannotCheck = '';

    /** @var array<int,array{from:string, to:string}> */
    protected array $redirects = [];

    protected string $effectiveUrl = '';


    public function __construct(
        int $status,
        int $lastChecked = 0,
        array $custom = [],
        string $errorType = '',
        int $errno = 0,
        string $exceptionMessage = '',
        string $message = '',
        string $reasonCannotCheck = ''
    ) {
        $this->status = $status;
        $this->lastChecked = $lastChecked ?: time();
        $this->custom = $custom;
        $this->errorType = $errorType;
        $this->errno = $errno;
        $this->exceptionMessage = $exceptionMessage;
        $this->message = $message;
        $this->reasonCannotCheck = $reasonCannotCheck;
    }

    public static function createInstanceFromJson(string $jsonString): self
    {
        $values = json_decode($jsonString, true, 512, JSON_THROW_ON_ERROR);
        if (isset($values['valid'])) { // Legacy format from original LinkValidator
            return self::createInstanceFromLegacyArray($values);
        }
        return self::createInstanceFromArray($values);
    }

    /** @param array<mixed> $values */
    public static function createInstanceFromLegacyArray(array $values): self
    {
        $status = ($values['valid'] ?? false) ? self::RESULT_OK : self::RESULT_BROKEN;
        $errorParams = $values['errorParams'] ?? [];
        $instance = new self(
            $status,
            (int)($values['lastChecked'] ?? 0),
            $errorParams['custom'] ?? [],
            (string)($errorParams['errorType'] ?? ''),
            (int)($errorParams['errno'] ?? 0),
            (string)($errorParams['exceptionMsg'] ?? ''), // Note: 'exceptionMsg' in legacy
            (string)($errorParams['message'] ?? '')
        );
        // Legacy did not have reasonCannotCheck or redirects explicitly stored this way.
        return $instance;
    }

    /** @param array<mixed> $values */
    public static function createInstanceFromArray(array $values): self
    {
        $instance = new self(
            (int)($values['status'] ?? self::RESULT_UNKNOWN), // Default to UNKNOWN if status missing
            (int)($values['lastChecked'] ?? time()),
            $values['custom'] ?? [],
            (string)($values['errorType'] ?? ''),
            (int)($values['errno'] ?? 0),
            (string)($values['exceptionMessage'] ?? ''),
            (string)($values['message'] ?? ''),
            (string)($values['reasonCannotCheck'] ?? '')
        );
        if (!empty($values['redirects'])) {
            $instance->setRedirects($values['redirects']);
        }
        if (!empty($values['effectiveUrl'])) {
            $instance->setEffectiveUrl((string)$values['effectiveUrl']);
        }
        return $instance;
    }

    /** @param array<mixed> $custom */
    public static function createInstanceByStatus(int $status, int $lastChecked = 0, array $custom = []): self
    {
        return new self($status, $lastChecked, $custom);
    }

    /** @param array<mixed> $custom */
    public static function createInstanceByError(
        string $errorType = '',
        int $errno = 0,
        string $message = '',
        string $exceptionMessage = '',
        array $custom = [],
        int $lastChecked = 0
    ): self {
        return new self(
            self::RESULT_BROKEN,
            $lastChecked ?: time(),
            $custom,
            $errorType,
            $errno,
            $exceptionMessage,
            $message
        );
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function isOk(): bool
    {
        return $this->status === self::RESULT_OK;
    }

    public function isError(): bool
    {
        return $this->status === self::RESULT_BROKEN;
    }

    public function isExcluded(): bool
    {
        return $this->status === self::RESULT_EXCLUDED;
    }

    public function isCannotCheck(): bool
    {
        return $this->status === self::RESULT_CANNOT_CHECK;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getLastChecked(): int
    {
        return $this->lastChecked;
    }

    public function setLastChecked(int $lastChecked): void
    {
        $this->lastChecked = $lastChecked;
    }

    /** @return array<mixed> */
    public function getCustom(): array
    {
        return $this->custom;
    }

    /** @param array<mixed> $custom */
    public function setCustom(array $custom): void
    {
        $this->custom = $custom;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }
    public function setErrorType(string $errorType): void
    {
        $this->errorType = $errorType;
    }


    public function getErrno(): int
    {
        return $this->errno;
    }
    public function setErrno(int $errno): void
    {
        $this->errno = $errno;
    }

    public function getExceptionMessage(): string
    {
        return $this->exceptionMessage;
    }

    public function setExceptionMessage(string $exceptionMessage): void
    {
        $this->exceptionMessage = $exceptionMessage;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
     public function setMessage(string $message): void
    {
        $this->message = $message;
    }

    public function getReasonCannotCheck(): string
    {
        return $this->reasonCannotCheck;
    }

    public function setReasonCannotCheck(string $reasonCannotCheck): void
    {
        $this->reasonCannotCheck = $reasonCannotCheck;
    }

    public function getCombinedError(bool $withExceptionString = false): string
    {
        $result = $this->getErrorType() . ':' . $this->getErrno();
        if ($withExceptionString && !empty($this->getExceptionMessage())) {
            $result .= ':' . $this->getExceptionMessage();
        }
        return $result;
    }

    public function getEffectiveUrl(): string
    {
        if (!empty($this->effectiveUrl)) {
            return $this->effectiveUrl;
        }
        if ($this->redirects) {
            $lastRedirect = end($this->redirects);
            return (string)($lastRedirect['to'] ?? '');
        }
        return '';
    }

    public function setEffectiveUrl(string $effectiveUrl): void
    {
        $this->effectiveUrl = $effectiveUrl;
    }

    /** @param array<int,array{from:string, to:string}> $redirects */
    public function setRedirects(array $redirects): void
    {
        $this->redirects = $redirects;
    }

    /** @return array<int,array{from:string, to:string}> */
    public function getRedirects(): array
    {
        return $this->redirects;
    }

    public function getRedirectCount(): int
    {
        return count($this->redirects);
    }
}
