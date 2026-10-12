## ADDED Requirements

### Requirement: An applied certificate survives a failed lookup

An exemption decided by the module SHALL NOT be undone by TaxCloud being unreachable. When a lookup fails on either transport (a transport error or an error response) for a cart to which a certificate was applied, the cart SHALL be charged no tax — the outcome TaxCloud would have returned for that certificate — instead of Magento's own tax rates, whether or not the store falls back to Magento tax rates. The log SHALL record, at a level written under Basic logging, that the failed lookup was answered with no tax because of the applied certificate, naming the certificate.

Carts with no applied certificate SHALL keep the store's configured failure behavior. Resolution stays fail closed: a cart whose certificates could not be retrieved has no applied certificate, and is treated as any other taxed cart.

#### Scenario: Failed lookup with fallback enabled charges an exempt cart no tax
- **WHEN** a certificate is applied to a cart, the lookup fails, and the store falls back to Magento tax rates
- **THEN** the cart is charged no tax, and the log names the certificate as the reason

#### Scenario: Failed lookup with fallback disabled charges an exempt cart no tax
- **WHEN** a certificate is applied to a cart, the lookup fails, and the store does not fall back to Magento tax rates
- **THEN** the cart is charged no tax

#### Scenario: Both transports behave the same
- **WHEN** a lookup for an exempt cart fails on a store using V1 SOAP, or on a store using V3 REST
- **THEN** the cart is charged no tax in both cases

#### Scenario: A taxed cart still falls back
- **WHEN** no certificate is applied to a cart, the lookup fails, and the store falls back to Magento tax rates
- **THEN** the cart is taxed at Magento's rates as before

#### Scenario: Unretrievable certificates are not an exemption
- **WHEN** the customer's certificates cannot be retrieved and the lookup also fails
- **THEN** no certificate is applied and the cart follows the store's configured failure behavior

## MODIFIED Requirements

### Requirement: An order records the certificate that exempted it

A certificate is the evidence that a sale was correctly untaxed, and it is held outside Magento in a store TaxCloud may change or delete. An order exempted by a certificate SHALL therefore record both the certificate's identifier and a copy of what the certificate said at the time of the sale — at least its covered states, exemption reason, purchaser and creation date. The record SHALL be written when the exemption is applied to the order and SHALL NOT be altered afterwards by later changes to the certificate.

The record SHALL only be written for an order that carries no tax. An order that carries tax was not exempted, whatever certificate the customer holds, and recording one would contradict the order's own totals; in that case no record is written and the log SHALL warn, naming the order and the certificate, at a level written under Basic logging.

#### Scenario: The applied certificate is recorded on the order
- **WHEN** an order is placed and a certificate exempts it
- **THEN** the order carries that certificate's identifier and a copy of its detail

#### Scenario: The record survives the certificate
- **WHEN** the certificate is later deleted or altered in TaxCloud
- **THEN** the order's record still shows what was relied on when the sale was made

#### Scenario: A taxed order records no certificate
- **WHEN** an order is placed with no exemption applied
- **THEN** the order carries no certificate record

#### Scenario: A certificate does not get recorded on an order that carries tax
- **WHEN** a certificate covering the destination is resolved for the customer at placement, but the order carries tax (for example, the certificate was attached after the cart's tax was last calculated)
- **THEN** the order carries no certificate record, and the log warns naming the order and the certificate
