# Changelog

## [1.1.0] - Unreleased

- Default new installations to Mainnet and bundle the classic RingCT runtime.
- Add PHP-native checkout and a Blesta cron plugin using the existing view-key engine.
- Persist immutable quotes, resumable scans and uniquely claimed outputs in InnoDB.
- Commit native accounting, validated invoice applications and payment state atomically.
- Reject native accounting mutations and wallet configuration changes with pending requests.
- Preserve legacy signed agent callbacks and bundle pinned engine sources with notices.
- Match checkout and cron node-health budgets and retain queue priority for unattempted scans.
- Document local nodes, container networking and Basic/Digest authentication.
- Reject stale invoice checkouts after the balance changes while preserving settlement of already-sent funds.
- Preserve saved secrets in Blesta's settings form and keep mobile payment addresses within the viewport.
- Verify licensed HTTP checkout, chain detection, invoice closure and service activation with test funds.
- Bundle only required runtime files and retain complete upstream license notices.
- Keep generated dependencies outside Git and exclude untracked files from installation packages.

## [1.0.0] - Unreleased

- Create a view-only agent payment quote for each checkout, targeting XMRPay 2.0.0.
- Validate signed metadata and confirmed agent state before recording payment.
- Serialize callbacks with database advisory locks and recover partial invoice application; retain excess payment as account credit.
- Acknowledge completed callbacks without re-entering Blesta payment processing.
