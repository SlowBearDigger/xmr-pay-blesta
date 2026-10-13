# XMRPay for Blesta 6

> Release candidate under validation. Do not deploy this snapshot for real funds.

This branch targets classic Monero mainnet and bundles the classic RingCT engine.
Experimental CARROT/FCMP++ support is maintained separately and is not included.

The nonmerchant gateway derives payment subaddresses using the existing XMRPay
PHP engine. The companion XMRPay Watch plugin scans Monero nodes through Blesta's
cron and records confirmed payments using native Transactions and Invoices models.
Native mode requires no XMRPay agent, Node.js, wallet files or wallet RPC service.

The licensed laboratory verified classic regtest payments through HTTP checkout,
native accounting, invoice closure and service activation. Node-health metadata
was simulated for that offline chain. Mainnet qualification, sustained scanner
capacity and external provisioning modules remain pending.

A primary address and private view key allow incoming-payment detection without
spending authority. Never enter a seed or private spend key. The view key exposes
incoming payment information; keep it private. Blesta encrypts the view key and
node configuration in gateway metadata. Protect Blesta's encryption key, database
backups and administrator access.

## Installation

Use a licensed Blesta 6 installation with MariaDB/MySQL InnoDB and PHP extensions
GMP, BCMath, cURL and PDO MySQL. The integration laboratory uses Blesta 6.0.3,
PHP 8.3.35, ionCube 15.5 and MariaDB 11.4.13. Blesta's bundled Minphp Date raised
an installation deprecation exception under PHP 8.4; that combination is not
qualified by these tests.

1. Extract the built installation ZIP. GitHub's automatic source archives do not
   include the bundled engine; build them from a Git checkout as described below.
   Copy `components/gateways/nonmerchant/xmrpay` and `plugins/xmrpay_watch` into
   the matching paths in Blesta. Install or upgrade the gateway, then install
   XMRPay Watch under Settings > Company > Plugins.
2. Configure XMRPay under Settings > Company > Payment Gateways with a matching
   primary address, private view key and network (Mainnet by default). Enter node
   URLs as separate lines or in the JSON format supported by the existing engine.
3. Choose a confirmation threshold and minimum healthy-node count. Defaults are
   10 confirmations and two nodes. Nodes must report the configured network and
   synchronized chain state; unavailable evidence leaves invoices unpaid.
4. Enable USD, EUR or XMR on the gateway. Configure XMR as a Blesta currency if
   needed. Fiat quotes require CoinGecko; XMR invoices do not use a price feed.
5. Configure Blesta's system cron using its Automation instructions. The plugin
   registers an enabled minutely scan task for each installed company. Provisioning
   follows Blesta's existing automation and module rules, normally on a later run.

The default subaddress index offset is 1,000,000. Reserve a separate index range
for this installation and never reuse it in another payment integration. Keep the
Blesta payment tables and their identifiers across migrations and restores.

## Own and local nodes

Enter the HTTP(S) RPC endpoint of `monerod`, for example
`http://127.0.0.1:18081` for a mainnet node running alongside PHP. A single trusted
node requires **Minimum distinct nodes = 1**; the default of two requires two
distinct endpoints. The network setting must match the node. The scanner obtains
blockchain data from these endpoints; the private view key stays in PHP.
Every configured endpoint must be healthy and agree on the chain state.

Inside a container, `127.0.0.1` refers to that container. Use the node's service
name on a shared private container network, or the reachable host address for a
node on the host. The laboratory uses host networking on Linux to reach its
loopback test node. Do not expose the node publicly just to connect containers.

For nodes protected by Basic or Digest authentication, enter a JSON array in the
nodes field. Credentials belong in their own fields, never in the URL:

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

Use `"auth": "basic"` for a Basic-auth reverse proxy. Authenticated plain HTTP,
including loopback, requires `"allow_insecure_http": true` in that node's JSON
object. Enable it only for a connection whose lack of transport encryption is
intentional. HTTPS keeps certificate and hostname verification enabled; configure
PHP cURL's CA trust for a private certificate authority. Both checkout PHP and
cron PHP must have the same node reachability and CA configuration.

## Payment behavior

Checkout stores its exact quote, address, allocations and scan birthday. Reloading
an active request preserves these values. Default expiry is 60 minutes. The full
payment must be observed by the scanner before expiry to settle automatically;
confirmations may arrive later. Payments first detected or completed after expiry
remain pending for manual review, even if they were mined before the deadline.
Monitor scanner progress: sending funds before expiry alone is not sufficient.
If another payment reduces an invoice's balance, its old checkout stops offering
payment instructions and asks the client to start again from their invoices.
Funds fully observed before expiry still settle; any excess becomes credit.

Blesta stores currency amounts at four decimal places; XMR detection uses exact
atomic amounts. Confirmed excess funds become proportional native account credit,
rounded down to Blesta's four-decimal precision. Output reservations, transaction
creation, invoice application and the paid state commit in one database transaction.
Unique output constraints prevent the same output paying two requests; retries
of a committed request do not create another transaction.

Scanning uses bounded, resumable batches and per-gateway database advisory locks.
An unsuccessful attempt does not prevent later pending requests receiving work.
Node timeouts, corrupt evidence, ownership errors or failed accounting checks
leave the request pending. Monitor the Blesta cron logs and investigate repeated
failures before accepting more payments.

Wallet, network and index changes, including switching back to agent mode, are
blocked while native requests remain pending. Expiry alone does not retire a
request: late funds are possible. This version has no automatic wallet-retirement
operation. Preserve the old configuration until requests are reconciled and
supervised; do not delete pending rows or keys to bypass the guard.

## Existing agent installations

The previous loopback agent configuration and signed callback paths remain for
outstanding legacy payments. Keep that agent and its secrets until those payments
are reconciled. Native settings select the PHP checkout path; retain legacy
credentials when saving settings if old callbacks still need verification.
The systemd example in `deploy/` applies only to legacy installations.

## Bundling

Generated dependencies are excluded from Git and included in the installation ZIP.
The bundle contains the required engine and adapter files, license notices, pinned
revisions and a SHA-256 manifest. Account-key generation, the in-memory test store
and upstream research documents are excluded. No sibling checkout, Composer or
package download is required at runtime.

Build from a Git checkout with Bash, Git, GNU coreutils, tar and zip. Use clean
local checkouts of these exact revisions; the bundler verifies both before copying:

- [xmr-pay-php](https://github.com/SlowBearDigger/xmr-pay-php) at `1eb8e34d0cf5049698f6591427356f53eae79c88`.
- [xmr-pay-adapter-core](https://github.com/SlowBearDigger/xmr-pay-adapter-core) at `14cff31b99206d544db4b337f2fce356c631bfcf`.

```bash
bash tools/bundle-engine.sh /path/to/xmr-pay-php /path/to/xmr-pay-adapter-core
bash tools/package.sh /existing/output/directory/xmrpay-blesta.zip
```

The packager copies tracked gateway/plugin files and manifest-listed dependencies
into a temporary directory. Untracked local files are excluded. The ZIP contains
the gateway, watch plugin, installation documentation and license notices. Preserve
the bundled notices when redistributing it.
