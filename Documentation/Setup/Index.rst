.. include:: /Includes.rst.txt

.. _extensionConfigurationReference:

===============================
Extension Configuration Reference
===============================

This document outlines key configuration aspects of the `pgu_brofix_extras` extension,
focusing on how link statuses are handled, including the Cloudflare integration.

Link Status Codes
=================

The link checker uses several status codes to categorize the results of a link check.
When the `pgu_brofix_extras` extension is active, the typical link statuses you might
encounter in the backend module include:

*   **1: Broken**
    *   The link is definitively broken (e.g., 404 Not Found, server error, DNS failure).
*   **2: OK**
    *   The link was checked successfully and is valid.
*   **3: Not possible to check ("non-checkable")**
    *   The link could not be definitively verified as broken or OK. This can occur due
      to various reasons like robots.txt restrictions, specific server configurations
      that block automated checks, or errors that are configured to be treated as
      "non-checkable" (see `combinedErrorNonCheckableMatch` in the original Brofix settings).
*   **4: Is excluded**
    *   The link or its domain is explicitly excluded from checking via the exclusion rules.
*   **5: Cloudflare Protected** (Reported as `RESULT_UNKNOWN` internally with a specific reason)
    *   The link is identified as being behind Cloudflare. While the link target might be
      accessible, Cloudflare's security measures can interfere with direct automated checks,
      often returning a 403 Forbidden status to bots. This status helps differentiate
      such cases from truly broken links.

The `combinedErrorNonCheckableMatch` setting (adapted from Brofix) allows for fine-tuning
which types of errors should result in a "non-checkable" (status 3) state. With the
Cloudflare detection in `pgu_brofix_extras`, if a site is identified by its `Server` header
as Cloudflare, it will be marked as status 5, often preempting a status 3 that might
have arisen from a Cloudflare-induced 403 error.

Extension Configuration Options (Global)
========================================

Configuration for `pgu_brofix_extras` is managed in the TYPO3 backend via
**Admin Tools > Settings > Extension Configuration > pgu_brofix_extras**.

The available options are adapted from the original Brofix extension. Key settings include:

*   **Link Checking Behavior:** Timeouts, redirect limits, User-Agent string.
*   **Caching:** Configuration for the link target cache.
*   **Exclusions:** Default storage PID for link exclusion rules.
*   **Reporting:** UI behavior in the backend module.
*   **Email Notifications:** Settings for email reports of broken links.
*   **Performance:** Limits on pages traversed, crawl delays.

*(Detailed list of all adapted Brofix extension configuration options will be expanded here
as the adaptation of the Configuration class and its settings is finalized and tested.)*

Page TSconfig
=============

Many global settings can be overridden or refined on a per-page or per-site basis
using Page TSconfig in the `mod.tx_pgubrofuxextras` namespace (or `mod.brofix` if maintaining
compatibility with original Brofix TSconfig paths is desired and configured).

Example:
````typoscript
mod.tx_pgubrofuxextras {
    # Example: Override link check timeout for a specific part of the site
    linktypesConfig.external.timeout = 15

    # Example: Different set of search fields for a specific part of the site
    searchFields {
        pages = url, media, header_link
        tt_content = bodytext, imagecaption
    }
}
````

Please refer to the original Brofix documentation for a comprehensive list of TSconfig
options, and adapt the namespace (`brofix` to `tx_pgubrofuxextras`) as needed.
The specific TSconfig structure used by `pgu_brofix_extras` will be finalized during
the adaptation of the `Configuration.php` class.
