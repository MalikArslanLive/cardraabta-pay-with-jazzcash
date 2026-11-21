# CardRaabta 1.0.0 verification

Current package verified on 24 November 2026. The runtime files and included documentation were built into one installable package. The archive's SHA-256 is
`b68156d5f073400dc7c7cbd71981857369013efb7ab9ebc81768f3d76efcf9fe`.

This update verified live Activity panel changes in Chromium and background job rescheduling on database lock contention. The rebuilt ZIP also passed 99 integration assertions, nine wallet assertions, 14 HTTP checks and WordPress Plugin Check.

The current ZIP passed focused WordPress Cron checks for hourly schedule migration, fresh five-minute scheduling and duplicate prevention. Plugin Check passed on the current package. The renamed action and activity panel registration were checked for legacy and HPOS order screens. Focused tests confirmed the order can save without a provider call, status appears above Save and updates in Chromium, and payment health appears above transaction search. The order fixture verified that a match at transaction 151 was synced without dispatching a refund. The preceding package passed WordPress Plugin Check and 99 integration assertions. Focused mocked-history checks covered linked and direct multiple partial refunds, stale refund replay protection, portal refund sync, order verification, timeline display and payment health. The partial-balance field names still need confirmation against a Live merchant history response. The broader baseline below was recorded on 21 November.

| Check | Result |
| --- | --- |
| GitHub plugin checks | 44 jobs passed: 41 functional configurations and three operating-system syntax jobs. |
| WordPress/WooCommerce release inventory | 230 targets passed with 22,080 assertions. See the [per-version results](COMPATIBILITY-RESULTS.csv). |
| WordPress Plugin Check 2.2.0 on the extracted ZIP | 0 errors and 0 warnings. |
| Security workflows | JavaScript CodeQL and Gitleaks passed. Dependency review is a pull-request-only check. |
| Local database cross-check | 96 passing assertions per profile for minimum WordPress 6.5/WooCommerce 8.3.0/PHP 8.1, current WordPress 7.1.4/WooCommerce 11.2.2/PHP 8.3 with MariaDB and legacy storage, PHP 8.5 with MariaDB and HPOS, and PHP 8.4 with MySQL, HPOS and Redis. |
| Local HTTP and UI | 14 HTTP assertions passed, including pretty return/IPN routes and refund authorization. Chromium passed the manager workflow and five viewport checks. axe passed within the transaction panel. |
| Focused payment flows | Synthetic wallet history success and failure changed real WooCommerce orders in six additional assertions. The isolated browser fixtures verified wallet polling and refund results. |
| Package integrity | Header, stable tag, manifest, exact source bytes, file permissions, ZIP checksum and deterministic rebuild verified. |

Payment checks used synthetic data. The recorded environments establish the scope of the results. The merchant's Live account still needs merchant-approved acceptance for each feature it will use. WordPress.org directory submission and approval are separate.
