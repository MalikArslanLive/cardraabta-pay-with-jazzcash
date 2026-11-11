# Security

Internal sandbox beta only. Live/refund processing remains disabled pending provider contract validation.

Signed returns match gateway, sandbox environment, merchant, currency, current order total, durable attempt and constrained provider reference. An authentic callback does not reveal a customer's private order URL without owner/session binding. Replays are idempotent; concurrent mutations require MySQL/MariaDB connection locks. REST IPN V1 never settles payment alone.

Credentials are authenticated encrypted using site configuration salts. Back up matching wp-config.php and database; rotate/re-enter credentials after changing salts. Protect backups and review the data-handling behavior of other installed plugins. Restrict administrator/database access and maintain updates.

Logs use diagnostic shapes and allowlisted flags, not raw callback bodies, passwords, hashes or hosted links. Redact identifiers from provider response text before sharing. Transaction CSVs and database backups are private merchant data.

Report suspected issues privately; never post customer data, merchant secrets, private order links or raw payment payloads. Automated checks cannot certify that all vulnerabilities are absent.
