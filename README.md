<img src="docs/images/tax-cloud-logo.png" align="right" />

# TaxCloud Magento 2 Module
> Sales Tax at the Speed of Commerce

##### How It Works
TaxCloud is the Internet's most affordable sales tax compliance service with a state-paid option from 25 member states - because the states paying us lets us give back to you. Working closely with states allows TaxCloud to offer industry leading tax data, automated filing options, the lowest prices and best value for your business's transactions in the USA.

**Calculate** - We determine the applicable sales tax rate based on product and service taxability.

**Collect** - Sales tax is collected at the time of transaction on your site when your customers checkout.

**File** - We can file returns and remit your collected sales tax proceeds to the appropriate state and local jurisdictions.

**Audit-Ready** - We can support you with any state-issued notices or audit inquiries.

[Learn more](https://taxcloud.com/)

## Installing the TaxCloud Module

### Step 1: Create a TaxCloud Account

[Create your TaxCloud account](https://taxcloud.com/) and select either Core or Enhanced services. New accounts are granted a free testing period to make sure your Magento store integrates properly.

### Step 2: Configure your TaxCloud Account

Now that you have created your TaxCloud account, there are a few important matters to take care of. Please log in to your TaxCloud account and complete all of the items below.

1. **Add your website.** While logged in, go to *Settings → Stores*. If your store is not listed on this page, you will need to add it by clicking *Add Store* and following the on-screen prompt.
1. **Add business locations.** If your business has a physical presence in the United States, it is imperative that you register your business locations, including stores, warehouses, and distribution facilities, with TaxCloud. To do so, navigate to *Settings → Locations* and click *Add Location.*
1. **Select your tax states.** Navigate to *Settings → Tax States*. You will be presented with a map of the United States. Click the map to highlight those states where you would like to collect sales tax.

### Step 3: Install the Magento 2 Module

#### Manual Installation

Download the extension as a ZIP file from the [releases page](/releases/latest). Unzip the archive and place at `app/code/Taxcloud/Magento2` on your webserver. Then run the following commands from your Magento 2 root directory.

```
bin/magento setup:upgrade
bin/magento setup:di:compile
```

#### Install via Composer

```
{
    "repositories": [
        {
            "url": "https://github.com/FedTax/Magento2.git",
            "type": "git"
        }
    ],
    "require": {
        "taxcloud/magento2": "*"
    }
}
```

## Configuring the TaxCloud Module

After installing the module, there are a few important configuration options you must set in the Magento 2 admin dashboard.

#### Shipping Origin Settings

Navigate to *Stores → Configuration* and then *Sales → Shipping Settings*.

![Shipping Settings](docs/images/configuration-admin-shipping-settings.png)

It is very important to enter the full shipping origin address for accurate sales tax calculation. Ensure that you enter the full Zip+4 code.

#### TaxCloud Settings

Navigate to *Stores → Configuration* and then *Sales → Tax*.

![TaxCloud Settings](docs/images/configuration-admin-settings.png)

* **Enabled** - Select `Enabled` in order to enable the TaxCloud module.
* **Logging Enabled** - Useful for debugging and testing, select `Enabled` in order to log all TaxCloud API calls into `var/log/taxcloud.log`. Make sure to set up log rotation if you keep this option enabled during production!
* **Verify Address** - Select `Enabled` to turn on TaxCloud's address verification API calls. You may want to disable this if you have another module that validates shipping addresses.
* **API ID** - Enter your API ID from your TaxCloud account.
* **API Key** - Enter your API Key from your TaxCloud account.
* **Guest Customer ID** - Enter the customer ID to send to TaxCloud during a guest checkout. Unless there is a special reason to change this for your store, use the default value of `-1`.
* **Default TIC** - Enter the Taxability Information Code you would like to use for products where an explicit TIC has not been specified.
* **Shipping TIC** - Enter the Taxability Information Code you would like to use for shipping costs. Use `11010` if you charge only postage, and `11000` for shipping & handling.
* **Cache Lifetime** - Enter the amount of time in seconds you would like to cache the sales tax lookup and verify address API calls. The default value is `86400` (24 hours), or enter `0` to disable caching for development purposes.

#### Product Settings

If applicable, you should set a Taxability Information Code per product. Navigate to *Catalog → Products*.

![Product Grid](docs/images/configuration-admin-product-grid.png)

From the main product grid, you will be able to see and sort by each product's TIC. In order to edit a TIC, click on the *Edit* link for a specific product.

![Product Edit](docs/images/configuration-admin-product-edit.png)

There are two main fields you should properly set per product.

* **Tax Class** - In most cases, you should select `Taxable Goods` for this, even if the product you are selling may be tax exempt under certain, or all, circumstances. If you select `None`, then this product will never be sent to TaxCloud's API during checkout. ___It is strongly discouraged to select `None`___ as you will not have an audit trail of the sale of this item in your TaxCloud account!
* **Taxcloud TIC** - The five digit Taxability Information Code for this product. For more information, see [Taxability Information Codes](https://taxcloud.com/tic).

##### Bulk Updating

To bulk update product TICs, navigate to *Catalog → Products*.

![Bulk Product Grid](docs/images/configuration-admin-product-bulk-grid.png)

Select the checkbox next to the items you want to update, then click *Actions → Update attributes*.

![Bulk Product Edit](docs/images/configuration-admin-product-bulk-edit.png)

Find the Taxcloud TIC textbox and click the *Change* checkbox below it. Enter a new TIC and click *Save*.

#### Customer Settings

If you have tax exempt customers, you can add an exemption certificate ID per user. Currently, there is no method to create an exemption certificate through the Magento 2 module, but if you have an existing exemption certificate in TaxCloud, you can link it to a customer's profile.

Navigate to *Customers → All Customers*, click on the *Edit* link for a specific customer, and then click on *Account Information*.

![Customer Edit](docs/images/configuration-admin-customer-edit.png)

Here you can add the 36 character plus dashes UUID for the already existing exemption certificate.

## Testing the TaxCloud Module

At this point, the TaxCloud module should be fully configured. However, it is very important to test the integration before going live. This module has been tested with stock Magento, but your store may have other modules that could interfere or cause unintended consequences with the TaxCloud module.

#### Sales Tax Calculation During Checkout

Test adding an item to your cart, clicking *Proceed to Checkout*, entering a shipping address, and selecting a shipping option. At this point, you should verify that the calculated tax is accurate. Retry this process with each product type / TIC you sell through your store, and combinations of product types / TICs.

![Tax Calculation During Checkout](docs/images/testing-checkout.png)

Note that on the shopping cart page, before the customer has entered their shipping address, sales tax will not be calculated unless the customer has started the checkout process and returned to the shopping cart page.

#### Order Completion

Test completing an order and comparing the results to your TaxCloud dashboard. The tax percentages may be slightly different on the Magento side due to rounding, but the tax amounts should match exactly. Make sure the correct TICs, quantities, and other fields are correct on the TaxCloud dashboard.

![Completed Order in Magento Admin](docs/images/testing-order-magento.png)

![Completed Order in TaxCloud Dashboard](docs/images/testing-order-taxcloud.png)

#### Order Refund

Test creating a credit memo for an order and comparing the results to your TaxCloud dashboard. The TaxCloud Magento 2 module supports both partial and total refunds.

![Completed Refund in Magento Admin](docs/images/testing-refund-magento.png)

![Completed Refund in TaxCloud Dashboard](docs/images/testing-refund-taxcloud.png)

## Extending the TaxCloud Module

In certain cases, a store owner may need to extend this module. Specific use cases might include: needing to adjust the shipping cost for a shipment containing both taxable and non-taxable items, fetching exemption certificates from an external source, or changing the shipping origin for multi-warehouse fulfillment.

Each of these situations can be accomplished using an event observer. For every API call to TaxCloud, this module emits a before and after event. The before events can be used to modify the parameters sent to TaxCloud's API, and the after events can be used to modify the response.

| Event Name | Description | Data Objects |
| ----- | ---- | --- |
| `taxcloud_lookup_before` | Emitted before the `Lookup` call to get tax rates | `$params`, `$customer`, `$address`, `$quote`, `$itemsByType`, `$shippingAssignment` |
| `taxcloud_lookup_after` | Emitted after the `Lookup` call to get tax rates | `$result`, `$customer`, `$address`, `$quote`, `$itemsByType`, `$shippingAssignment` |
| `taxcloud_verify_address_before` | Emitted before the `VerifyAddress` call during checkout | `$params` |
| `taxcloud_verify_address_after` | Emitted after the `VerifyAddress` call during checkout | `$result` |
| `taxcloud_authorized_with_capture_before` | Emitted before the `AuthorizedWithCapture` call when an order is placed | `$params`, `$order` |
| `taxcloud_authorized_with_capture_after` | Emitted after the `AuthorizedWithCapture` call when an order is placed | `$result`, `$order` |
| `taxcloud_returned_before` | Emitted before the `Returned` call when a credit memo is created | `$params`, `$order`, `$items`, `$creditmemo` |
| `taxcloud_returned_after` | Emitted after the `Returned` call when a credit memo is created | `$result`, `$order`, `$items`, `$creditmemo` |


## License

[![OSL 3.0](docs/images/osl-3.0.svg)](https://opensource.org/licenses/OSL-3.0)

This project is distributed under the Open Software License ("OSL") v. 3.0 (see the LICENSE file in the project root).
