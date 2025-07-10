# PGU Brofix Cloudflare Helper (pgu_brofix_extras)

## Overview

`pgu_brofix_extras` is a TYPO3 extension designed to enhance the functionality of the `sypets/brofix` link checker extension. Its primary purpose is to improve the detection and reporting of external links that are protected by Cloudflare.

When `sypets/brofix` checks links, sites behind Cloudflare can sometimes be misidentified as broken (e.g., returning a 403 Forbidden status to automated checkers) or simply as "not checkable" without specific indication of Cloudflare's presence. This extension aims to provide a more precise status for such links.

## Features

*   **Improved Cloudflare Detection:** Actively checks the `Server` HTTP header of external links for "cloudflare".
*   **Dedicated Cloudflare Status:** Assigns a specific status (using Brofix's existing `RESULT_UNKNOWN` status code 5) and reason (`REASON_CANNOT_CHECK_CLOUDFLARE`) to links identified as being behind Cloudflare.
*   **Enhanced UI in Brofix Module:**
    *   Displays a distinct cloud icon (<core:icon identifier="actions-cloud"/>) next to Cloudflare-protected links in the Brofix backend module.
    *   Shows a specific status message "Cloudflare Protected (PGU)" (localized) for these links.
    *   Adds a "Cloudflare" option to the status filter in the Brofix module, allowing users to easily find or exclude these links.

## How It Works

This extension extends the `sypets/brofix` extension by:

1.  **XCLASSing `Sypets\Brofix\Linktype\ExternalLinktype`:** The core logic for checking external links in Brofix is modified. The overridden methods now inspect the `Server` header of the HTTP response. If "cloudflare" is detected, the link's `LinkTargetResponse` is updated with status 5 and the `REASON_CANNOT_CHECK_CLOUDFLARE` reason code (both of which are predefined in Brofix).
2.  **Leveraging Existing Brofix Logic:** Brofix's `LinkAnalyzer` class already contains logic (as per the changes in PR #436 for sypets/brofix) to handle `REASON_CANNOT_CHECK_CLOUDFLARE` and set the status to `RESULT_UNKNOWN` (5). Our XCLASS ensures this reason is set more reliably based on the `Server` header.
3.  **Template Overrides:** Fluid template partials from `sypets/brofix` responsible for displaying link statuses and filter options are overridden to include the visual indicators and filter option for Cloudflare links.
4.  **Language Files:** Provides the necessary language strings for the new UI elements (e.g., "Cloudflare" filter option, "Cloudflare Protected (PGU)" status message).

## Requirements

*   TYPO3 CMS version 12.4.0 or later (including v13).
*   **`sypets/brofix` extension must be installed and active.** This extension *extends* `sypets/brofix` and does not replace it.

## Installation

1.  Install the `sypets/brofix` extension if you haven't already.
2.  Install `pgu_brofix_extras` extension via Composer:
    ```bash
    composer require gaumondp/pgu-brofix-extras
    ```
    (Or install through the TYPO3 Extension Manager if available as a TER release).
3.  Activate the extension in the TYPO3 Extension Manager.
4.  Clear all caches in TYPO3.

## Usage

After installation and activation, the `pgu_brofix_extras` extension will automatically enhance the link checking behavior of the `sypets/brofix` backend module.

When you use the Brofix module to check links:

*   Links identified as being behind Cloudflare will now show up with a cloud icon and the "Cloudflare Protected (PGU)" status.
*   You will be able to filter for "Cloudflare" links in the status filter dropdown.

This allows for more accurate identification of links that are not necessarily broken but are difficult to check due to Cloudflare's protective measures.

## Contribution

Contributions are welcome! Please refer to the repository on GitHub.

## License

This extension is licensed under GPL-2.0-or-later.
