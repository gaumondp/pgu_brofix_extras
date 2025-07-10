<?php

defined('TYPO3') or die();

use Gaumondp\PguBrofixExtras\EventListener\LinkAnalysis\ModifyLinkTargetResponseForCloudflareListener;
use Sypets\Brofix\Event\ModifyLinkTargetResponseEvent; // Assuming Brofix might dispatch such an event.
                                                        // If not, this line and its usage would be removed.

call_user_func(function () {
    $extensionKey = 'pgu_brofix_extras';

    // --- PSR-14 Event Listener Registration (Ideal Scenario) ---
    // This is the preferred way if sypets/brofix dispatches a relevant PSR-14 event.
    // We would need to know the exact event class dispatched by Brofix.
    // Example (assuming Brofix dispatches an event like ModifyLinkTargetResponseEvent):
    //
    // $eventDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\EventHandling\EventDispatcher::class);
    // $eventDispatcher->addListener(
    //     ModifyLinkTargetResponseEvent::class, // This is a HYPOTHETICAL event from Brofix
    //     ModifyLinkTargetResponseForCloudflareListener::class . '::enhanceResponseIfCloudflare'
    // );


    // --- XCLASSing (If no events or service decoration is suitable) ---
    // This is generally a last resort.
    // Example: To override a method in Brofix's ExternalLinktype
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\Sypets\Brofix\Linktype\ExternalLinktype::class] = [
        'className' => \Gaumondp\PguBrofixExtras\Xclass\BrofixExternalLinktype::class
    ];

    // --- Constant Definition ---
    // The constants REASON_CANNOT_CHECK_CLOUDFLARE and RESULT_UNKNOWN are already defined in
    // Sypets\Brofix\CheckLinks\LinkTargetResponse\LinkTargetResponse.
    // Our XCLASS will use these existing Brofix constants.
    // No need to define new constants here for this specific purpose if we align with Brofix's definitions.

    // No specific module registration here as `pgu_brofix_extras` only extends Brofix's UI.
    // UI changes will be handled by template overrides and potentially CSS.
});
