# XMRPay for Blesta 6

> **Draft: not ready for production.** The current code is an agent-based prototype. A PHP-native integration using Blesta cron is being developed so merchants will not need to install the XMRPay agent or Node.js. That implementation is not available yet.

The instructions below describe the existing prototype.

Nonmerchant gateway for a local XMRPay 2.0.0 view-only agent. It creates a unique Monero address for each checkout and records an invoice payment only after the agent confirms it on-chain.

## How payment detection works

The **agent** is the XMRPay payment service running on your server. It watches the
Monero blockchain and tells Blesta when an invoice has been paid. It is not an
external payment provider.

**Watch** means automatic payment detection. **View-only** means the service can
see incoming payments but cannot spend your funds. The agent uses your wallet's
primary address and private view key. Never give it your seed phrase or private
spend key. Keep the view key private: it reveals incoming payment information.

No XMRPay-operated server or account is required. The agent runs on the merchant's own installation.

The Blesta integration requires this agent. It does not scan the blockchain
inside Blesta's PHP process. You need a server that can keep the Node.js service
running; a PHP-only hosting account is not enough on its own.

The payment flow is:

1. Blesta asks your agent for a payment address and amount.
2. The customer pays that address from their Monero wallet.
3. Your agent detects the transfer and checks its confirmations.
4. The agent notifies Blesta. Blesta checks the notification and the agent's
   payment record before recording the invoice payment.

## Install

### 1. Set up the payment service

Use the [XMRPay agent setup guide](https://github.com/SlowBearDigger/xmr-pay/blob/main/docs/AGENT.md)
to install the matching agent version with Node.js 20 or later. Configure your
wallet address, private view key and a Monero node on the same network. Keep the
wallet files and order ledger in a private directory outside the web root, and
run the agent as a background service so it survives logout and server restarts.

Install Blesta and the agent on the same server. This gateway accepts only a
loopback URL (`127.0.0.1`), not a remote XMRPay service. The gateway initially shows
port `8792`: set `PORT=8792` on the agent to match. The agent otherwise defaults to
`8788`; if you keep that default, change the gateway URL to use `8788` instead.

Create two different random secrets: one agent token and one webhook secret.
Run this command once for each secret:

```bash
openssl rand -hex 32
```

These are passwords for communication between Blesta and the agent, not Monero
wallet keys. Save them in the agent's private configuration as `AGENT_TOKEN` and
`FULFILL_WEBHOOK_SECRET`. Use new secrets for each installation.

### 2. Connect Blesta to the agent

Copy `components/gateways/nonmerchant/xmrpay` into the same path in Blesta. In
**Settings → Payment Gateways**, install **XMRPay (Monero)** and fill in:

| Setting | What to enter |
|---|---|
| **Agent URL** | The address where your agent is listening, `http://127.0.0.1:8792` if you set `PORT=8792`. This is not a public Monero node URL. |
| **Agent token** | The first secret you created, exactly matching the agent's `AGENT_TOKEN`. It lets Blesta request and check payments. |
| **Webhook secret** | The second secret, exactly matching `FULFILL_WEBHOOK_SECRET`. It lets Blesta check payment notifications from the agent. |
| **Network** | `mainnet` for real payments or `stagenet` for testing. It must match the wallet, Monero node and the agent's `XMR_NETWORK`. |

The gateway settings show a **callback URL**. Copy it into the agent's
`FULFILL_WEBHOOK_URL`: this is where the agent sends payment notifications. Save
the configuration and restart the agent after changing its settings. Do not
expose the full agent API, its token or its secrets to customers.

### 3. Enable invoice payments

Enable USD and/or EUR on the gateway. XMR also works when XMR is configured as a
Blesta currency. Invoice amounts follow Blesta's database precision (four decimal
places in Blesta 6.0.2).

Configure Blesta cron using the command shown under **Settings → System →
Automation**. This runs Blesta's scheduled work; the agent remains a separate
service. Blesta's service module handles provisioning after the invoice is paid.
Test a stagenet invoice before accepting real payments.

USD and EUR quotes use CoinGecko; XMR does not need a price feed. Each quote stores
its currency amount, XMR amount and payment metadata. A callback must match that
metadata and the agent's confirmed order before Blesta records the payment.

## Test

`php tests/gateway.php`

The test checks signed metadata, tampering rejection, lock contention, agent confirmation, duplicate callbacks, recovery after partial invoice application, invoices already paid through another transaction, and XMR amount formatting. Callback serialization uses MySQL/MariaDB advisory locks and holds the database connection until request shutdown. A live end-to-end test additionally requires a stagenet wallet and Blesta invoice.

Each checkout reload creates a new quote and address. If several quotes are paid for the same invoice, each payment has its own transaction; amounts exceeding the remaining invoice balance stay as account credit.
