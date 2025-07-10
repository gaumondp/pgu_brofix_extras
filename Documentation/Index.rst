.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. include:: /Includes.rst.txt

======================
PGU Brofix Extras
======================

`pgu_brofix_extras` is a TYPO3 extension that provides advanced link checking capabilities,
based on the original `sypets/brofix` extension. This version includes specific
enhancements such as the detection of links protected by Cloudflare.

.. toctree::
   :maxdepth: 2
   :titlesonly:
   :glob:

   Setup/Index

Features
========

- Comprehensive link checking for various link types (external, internal, files).
- Detailed reports of broken or problematic links.
- Cloudflare Detection: Identifies links behind Cloudflare and assigns them a specific status,
  allowing for better filtering and management.
- Configurable behavior through TypoScript and Extension Configuration.

Cloudflare Integration Details
------------------------------

When a link is checked that is served through Cloudflare, this extension attempts to
identify it based on the server headers.

**New Link Status for Cloudflare:**

*   **Status Code:** 5 (Utilizes the existing `LinkTargetResponse::RESULT_UNKNOWN` status code from the Brofix logic)
*   **Description in UI:** "Cloudflare Protected" (or similar, based on language file)
*   **Internal Reason:** "Link is behind Cloudflare" (Stored as `LinkTargetResponse::REASON_CANNOT_CHECK_CLOUDFLARE`)

This means that instead of potentially being marked as "broken" (e.g., due to a 403 Forbidden
response from Cloudflare's security measures), these links will be flagged specifically.
This allows for better filtering and understanding of link check results within the
backend module.

In the backend module:

*   Links detected as Cloudflare links will be displayed with a cloud icon (<core:icon identifier="actions-cloud"/>).
*   The status message will reflect the Cloudflare status.
*   A new filter option "Cloudflare" will be available in the status dropdown.

See :ref:`extensionConfigurationReference` for how this status fits into the overall system.

Installation
============

Install the extension as any other TYPO3 extension. After installation, ensure the
necessary database tables (`tx_pgubrofuxextras_*`) are created by running database schema updates.

Configuration
=============

The extension can be configured via:

*   **Extension Configuration:** Global settings in Admin Tools > Settings > Extension Configuration.
*   **Page TSconfig:** Per-page or per-site tree specific settings.

Refer to the :ref:`extensionConfigurationReference` and the original Brofix documentation
for details on available options.

Usage
=====

Access the link checker module in the TYPO3 backend (usually under "Info" or a similar top-level module).
Select a page from the page tree to start checking links for that page and its subpages,
according to the configured depth and filters.

The module provides a list of found links with their statuses, allowing for easy
identification and management of problematic links.
