# Changelog

## [1.0.0] - Unreleased

- Create a view-only agent payment quote for each checkout, targeting XMRPay 2.0.0.
- Validate signed metadata and confirmed agent state before recording payment.
- Serialize callbacks with database advisory locks and recover partial invoice application; retain excess payment as account credit.
- Acknowledge completed callbacks without re-entering Blesta payment processing.
