# XMRPay for Blesta 6

Nonmerchant gateway for a local XMRPay view-only agent. It creates a unique Monero address for each checkout and records an invoice payment only after the agent confirms it on-chain.

## Install

Copy `components/gateways/nonmerchant/xmrpay` into the same path in Blesta. In **Settings → Payment Gateways**, install **XMRPay (Monero)** and set:

- **Agent URL:** local HTTP endpoint, for example `http://127.0.0.1:8792`.
- **Agent token:** the agent's `AGENT_TOKEN`.
- **Webhook secret:** the agent's `FULFILL_WEBHOOK_SECRET`.
- **Network:** match `XMR_NETWORK` on the agent.

Set the agent's `FULFILL_WEBHOOK_URL` to the callback URL shown in gateway settings. Enable USD and/or EUR on the gateway. XMR also works when XMR is configured as a Blesta currency. Configure Blesta cron using the command shown under **Settings → System → Automation**. Blesta's service module handles provisioning after the invoice is paid.

Use matching agent tokens and webhook secrets at both ends of this integration. Use separate values for the token and webhook secret, and generate new values for each installation. Keep the agent on loopback and its wallet view-only.

USD and EUR quotes use CoinGecko; XMR does not need a price feed. Each quote stores
its currency amount, XMR amount and payment metadata. A callback must match that
metadata and the agent's confirmed order before Blesta records the payment.

## Test

`php tests/gateway.php`

The test checks signed metadata, tampering rejection, lock contention, agent confirmation, duplicate callbacks, recovery after partial invoice application, invoices already paid through another transaction, and XMR amount formatting. Callback serialization uses MySQL/MariaDB advisory locks and holds the database connection until request shutdown. A live end-to-end test additionally requires a stagenet wallet and Blesta invoice.

Each checkout reload creates a new quote and address. If several quotes are paid for the same invoice, each payment has its own transaction; amounts exceeding the remaining invoice balance stay as account credit.
