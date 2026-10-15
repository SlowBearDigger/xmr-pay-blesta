# XMRPay for Blesta 6

Accept Monero invoice payments in Blesta. Funds go directly to your wallet.
Setup needs your primary wallet address and private view key. XMRPay runs in PHP
with Blesta cron and Monero nodes, without an agent or wallet RPC.

Checkout creates a fixed quote and payment address. XMRPay Watch detects incoming
funds and records confirmed payments in Blesta. Checkout updates automatically.
XMR invoices need no price feed; USD and EUR quotes use CoinGecko.

![Monero checkout](docs/screenshots/checkout.png)

## Requirements

- Licensed Blesta 6 with its PHP and ionCube requirements.
- GMP, BCMath, cURL and PDO MySQL in web and cron PHP; MariaDB or MySQL with InnoDB.
- A primary Monero address, matching private view key and reachable Monero nodes.
- Blesta system cron running every minute.

Tested on Blesta 6.0.3, PHP 8.3.35 and MariaDB 11.4.13.
Never enter a seed phrase or private spend key. Keep the view key private.

## Installation

1. Extract the installation ZIP into Blesta, keeping
   `components/gateways/nonmerchant/xmrpay/` and `plugins/xmrpay_watch/` in place.
2. Under **Settings > Company**, install
   **XMRPay (Monero)** in Payment Gateways and **XMRPay Watch** in Plugins.
3. Configure the gateway and accepted currencies. Add XMR to Blesta if needed.
4. Set the system cron to run every minute using **Settings > System > Automation**,
   and enable XMRPay Watch.
5. Grant staff Monero payment permissions and configure Blesta mail delivery with
   **Payment Received (Non-Merchant)** enabled. Verify an invoice, service and receipt.

Upgrade both components through Blesta after copying new files. GitHub source
archives omit the engine; use the source build instructions below.

## Settings

| Setting | Default | Purpose |
|---|---|---|
| Monero network | Mainnet | Mainnet, Stagenet or Testnet. Must match the wallet and nodes. |
| Primary wallet address | Required | Use the primary address. |
| Private view key | Required | Detect incoming payments without spending funds. |
| Monero nodes | Required | One endpoint per line, or authenticated JSON. |
| Minimum distinct nodes | 2 | Set to 1 for one trusted node. |
| Confirmations required | 10 | Confirmations before recording payment. |
| Subaddress index offset | 1,000,000 | Reserve a separate range for this installation. |
| Quote validity in minutes | 60 | Deadline for detecting the full payment. |
| Watch unpaid quotes after expiry (hours) | 24 | Monitor empty requests for 1 to 8760 hours before pausing. |
| Show author credit | Enabled | Show the optional By SlowBearDigger link to xmrpay.shop. |

Use a dedicated wallet or a subaddress range reserved for this installation.
Wallet, network and index changes are blocked while requests remain pending.

Local mainnet nodes can use `http://127.0.0.1:18081`. Inside containers, use the
node's reachable service or host address. All configured nodes must be healthy
and agree on the chain.

<details>
<summary>Authenticated nodes</summary>

```json
[
  {
    "url": "https://node.example.com:18081",
    "auth": "digest",
    "username": "billing",
    "password": "replace-with-node-password"
  }
]
```

Use `"auth": "basic"` for Basic authentication. Authenticated plain HTTP requires
`"allow_insecure_http": true`, including loopback. HTTPS verifies certificates.

</details>

## Payment handling

- Automatic settlement requires detecting the full payment before expiry;
  confirmations may arrive later.
  Accept late payments under **Tools > Monero Payments**, not Record Payment.
- Empty expired requests pause after a complete current scan. Funded requests stay watched.
  Staff can resume monitoring with a recorded reason.
- Excess becomes account credit at Blesta's four-decimal precision. Blesta sends
  payment receipts; XMRPay retries failed delivery.
- When migrating from the agent, keep it and its secrets until outstanding agent
  payments are reconciled.

Watch shares scans per wallet, processing up to 25 requests per run with a
20-second scan budget. Capacity depends on your host or VPS, node response times,
blockchain activity and the pending queue. Monitor scan delays as volume grows.

<details>
<summary>Configuration and payment states</summary>

![Gateway configuration](docs/screenshots/configuration.png)

![Payment detected](docs/screenshots/payment-detected.png)

![Payment recorded](docs/screenshots/paid.png)

Desktop screenshots use isolated test installations and valueless payments.
Private settings are masked.

</details>

<details>
<summary>Build from source</summary>

Use clean [engine](https://github.com/SlowBearDigger/xmr-pay-php) and
[Adapter Core](https://github.com/SlowBearDigger/xmr-pay-adapter-core) checkouts at
the revisions pinned in `tools/bundle-engine.sh`. Requires Bash, Git, GNU coreutils,
tar and zip.

```bash
bash tools/bundle-engine.sh /path/to/xmr-pay-php /path/to/xmr-pay-adapter-core
bash tools/package.sh /existing/output/directory/xmrpay-blesta.zip
```

The ZIP includes the required runtime, license notices and SHA-256 manifest.
Preserve the notices when redistributing it.

</details>

MIT. See [LICENSE](LICENSE).

[Website](https://xmrpay.shop/) ·
[Issues](https://github.com/SlowBearDigger/xmr-pay-blesta/issues) ·
[Other XMRPay integrations](https://github.com/SlowBearDigger/xmr-pay#choose-an-integration)
