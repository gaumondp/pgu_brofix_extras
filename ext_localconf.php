<?php

defined('TYPO3') or die();

// This file is for registering hooks, services, event listeners,
// and other runtime configurations.
// We will populate this as we adapt the Brofix logic.

// Example: If Brofix had its LinkAnalyzer or Linktype classes as services,
// or if we need to register backend modules or XCLASSes (though XCLASSing is older).

call_user_func(function () {
    // Module registration (TYPO3 v12+)
    // This will be adapted from how Brofix registers its backend module.
    // \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
    //     'PguBrofixExtras', // Was 'Sypets.Brofix' or similar in original
    //     'web',
    //     'brokenlinks', // Module key
    //     '', // Position
    //     [
    //         // Controller Actions e.g.,
    //         // \Gaumondp\PguBrofixExtras\Controller\BrokenLinkListController::class => 'handleRequest, main, report, checklinks, recheckUrl, editField',
    //     ],
    //     [
    //         'access' => 'user,group',
    //         'icon' => 'EXT:pgu_brofix_extras/Resources/Public/Icons/module-brokenlinks.svg', // Adjust icon path
    //         'labels' => 'LLL:EXT:pgu_brofix_extras/Resources/Private/Language/locallang_mod_brokenlinks.xlf', // Adjust LLL path
    //     ]
    // );

    // If Brofix uses PSR-14 events for extensibility, listeners would be registered here.
    // Or if it provides hooks.

    // Define the custom LinkTargetResponse reason constant if it's not defined by core.
    // This ensures it's available globally within TYPO3 once our extension is loaded.
    // We might move this to a more specific bootstrap location if appropriate.
    if (!defined('TYPO3\CMS\Linkvalidator\LinkTarget\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE')) {
        define('TYPO3\CMS\Linkvalidator\LinkTarget\LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE', 'Link is behind Cloudflare');
    }
});
