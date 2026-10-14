# Changelog

## [1.1.3] - Unreleased

- Reuse the XMRPay payment card with the exact amount, network, address and late-payment guidance.
- Generate payment QR codes with Blesta's bundled library in SVG format, without requiring GD or an external service.
- Add address copying with a selection fallback and an explicit failure message.
- Group wallet, node and payment settings using Blesta's responsive form components.
- Verify native and plugin-prefixed checkout routes and dense-block recovery, accounting and queue progress on stock Blesta.

## [1.1.2] - Unreleased

- Validate invoice ownership and unique selections before native checkout renders or stores payment allocations.
- Reject malformed invoice and amount inputs and clear invalid payment sessions.
- Preserve signed guest links, partial payments and account credit through Blesta's native payment flow.
- Require the upgraded Watch event before creating native XMRPay quotes.
- Reuse each node's cURL connection while resetting request options between RPC calls.
- Rotate the first scan turn independently from shorter attempts so pending requests receive the full available batch budget.

## [1.1.1] - Unreleased

- Add a native Monero Payments page with separate viewing and late-acceptance permissions.
- Verify late payments again before accepting the original quote, preserving atomic output claims and accounting.
- Record the accepting staff member, time and reason with the payment; preserve them on retries.
- Deny administrative access until the plugin permissions are registered and granted.
- Decode legacy RingCT amounts and masks using Monero's scalar encoding while retaining compact RingCT support.
- Let slow-node scans use the remaining cron budget so canonical rechecks can complete.

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
