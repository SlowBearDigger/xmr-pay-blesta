# XMRPay for Blesta 6

Nonmerchant gateway for a local XMRPay view-only agent. It creates a unique Monero address for each checkout and records an invoice payment only after the agent confirms it on-chain.

## Install

Copy `components/gateways/nonmerchant/xmrpay` into the same path in Blesta. In **Settings → Payment Gateways**, install **XMRPay (Monero)** and set:

- **Agent URL:** local HTTP endpoint, for example `http://127.0.0.1:8792`.
- **Agent token:** the agent's `AGENT_TOKEN`.
- **Webhook secret:** the agent's `FULFILL_WEBHOOK_SECRET`.
- **Network:** match `XMR_NETWORK` on the agent.

Set the agent's `FULFILL_WEBHOOK_URL` to the callback URL shown in gateway settings. Enable USD and/or EUR on the gateway. XMR also works when XMR is configured as a Blesta currency. Configure Blesta cron using the command shown under **Settings → System → Automation**. Blesta's service module handles provisioning after the invoice is paid.

The agent and Blesta need different secrets for each installation. Keep the agent on loopback and its wallet view-only.

## Test

`php tests/gateway.php`

The test checks signed order metadata, tampering rejection, short transaction IDs, and XMR amount formatting. A live end-to-end test additionally requires a stagenet wallet and Blesta invoice.
