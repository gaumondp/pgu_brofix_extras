<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Util;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Localization\LanguageService; // For localized date formats potentially

class StringUtil
{
    /**
     * Converts timestamp into date and time.
     * Uses system default date and time formats.
     * If the date is today, only show time.
     *
     * @param int $timestamp Unix timestamp
     * @return string Formatted date/time string
     */
    public static function formatTimestampAsString(int $timestamp): string
    {
        if ($timestamp === 0) {
            return ''; // Or some placeholder for 'never'
        }

        // Get system date/time formats. In TYPO3 v12+, these might be accessed differently,
        // e.g., via a configuration service or localization utilities.
        // $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] and ['hhmm'] are traditional.
        $dateFormatString = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'd.m.y';
        $timeFormatString = $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i';

        // Get current time from TYPO3 context for consistency
        $context = GeneralUtility::makeInstance(Context::class);
        $now = $context->getPropertyFromAspect('date', 'timestamp');

        $currentDate = date($dateFormatString, $now);
        $dateOfTimestamp = date($dateFormatString, $timestamp);
        $timeOfTimestamp = date($timeFormatString, $timestamp);

        if ($currentDate === $dateOfTimestamp) {
            // If it's today, just show the time
            return $timeOfTimestamp;
        }
        // Otherwise, show date and time
        return $dateOfTimestamp . ' ' . $timeOfTimestamp;
    }
}
