# Adobe Commerce Domain Reference (BDAD)

Source of truth for domain classification and the **Cross-Domain Verification Gate**. Derived from
`domains.pdf` (Product Domain Ownership). Two jobs:

1. **Classify** a ticket / change into its owning domain (Scribe on intake; Dependency Tracer in Phase 0).
2. **Select dependent-domain test suites** deterministically when shared sales/inventory/pricing/checkout code
   changes — turning the vague "also run MSI / B2B" rule into a lookup (§ Cross-Domain Adjacency Map).

> These module lists and adjacencies are first-pass engineering judgments for the sales/inventory/checkout/
> pricing cluster we touch most. Refine them as real blast-radius data accrues; the PDF remains the canonical
> ownership record.
>
> **PM-attribution caveat:** verified against a clean `pdftotext -layout` extract. Owners are accurate except
> the **Merchandising & Promotions / Shopper Experience & Gift Cards / Payments / Marketplace** block — the
> source PDF uses tall merged cells there, so the Ritesh Somani vs Badirinath MV split is a best-guess. PM
> owner is metadata only; the domain→module→adjacency data (what BDAD actually uses) is unaffected.

---

## Domain Catalog

| Domain | PM Owner | Key functional areas / representative modules |
|---|---|---|
| Analytics & Reporting | Daniel Rios | Google site tools (Analytics, Tag Manager, AdWords), Standard reporting (sales/customer/product reports), Advanced Reporting / MBI |
| SaaS Data Services | Alex Jose | Registry Service, Services Connector, Catalog/Inventory/Order/Customer Domain Service, Unified Ranking Indexing, SaaS Data Export, SaaS Price Indexing, Catalog Sync; Composable Catalog Data Model (CCDM) |
| Commerce Unified Experience | Sandra Gonzalez Mangana | Merchandising Services (Live Search, Product Recommendations, Category Merchandising), Storefront Services (Catalog Service, federated storefront API), ACO-ASO Integration, Success Metrics Report |
| Personalization (AEP/AEC) | Poornima Agoramurthy | Data Sharing (Project Beacon), Data Connection Extension, Audience Activation Extension |
| Customer Management | Poornima Agoramurthy | `Magento_Customer`, customer accounts/scope/attributes, Store Credit (`Magento_CustomerBalance`), Customer groups & segments (`Magento_CustomerSegment`), Audience Activation |
| Performance | Nishant Kapoor | Cache Framework, CDN/Fastly, Index management, performance monitoring, New Relic, `AsynchronousOperations`, async order placement, split database |
| Commerce Web APIs | Nishant Kapoor | GraphQL, REST (`Magento_Webapi`, `Magento_GraphQl`) |
| IMS / Passport / SSO | Nishant Kapoor | Adobe IMS Integration, ABP onboarding |
| B2B Commerce | John Romney | `Magento_Company`, `Magento_SharedCatalog`, `Magento_NegotiableQuote`, `Magento_PurchaseOrder`, `Magento_B2b` |
| Commerce on Cloud Infrastructure | Matt Johnson | Cloud platform, ACCS Migration tools, `ece-tools` |
| Adobe Commerce Extensibility | Naomi Robichaud | Admin UI Extensibility, Adobe I/O Events, Webhooks, App Builder, Integration Starter Kit; Cloud Patch Automation Tool; API Mesh |
| Security | Smita Verma | Encryption key, Security Scan Tool, Admin security, CAPTCHA, reCAPTCHA, 2FA, `WebapiSecurity` |
| Compliance | Smita Verma | HIPAA readiness, Privacy Policy, CCPA, GDPR, Cookie law |
| EDS Storefront for B2B | Vinod Sivagnanam | EDS Storefront developer experience (B2B) |
| Gainsight & OMEGA | Stephan Bieber | Adobe Analytics (OMEGA), Gainsight PX, Product Adoption Scores, `Magento_AdminAnalytics` |
| Product Visuals (AEM Assets) | Daniel Perez Tanco | Product Visuals powered by AEM Assets Integration for Commerce |
| Order Management & Delivery | Daniel Perez Tanco | `Magento_Sales`, `Magento_SalesInventory`, Invoices, Shipments, Credit Memos, Returns (`Magento_Rma`), Shipping & Delivery, Carriers (`Magento_Shipping`, `Magento_OfflineShipping`), Shipping Labels |
| Site & Store Management | Daniel Perez Tanco | `Magento_Store`, store/site structure, localization, store URLs |
| Taxes / Currency | Daniel Perez Tanco | `Magento_Tax`, tax rules/classes/zones/rates, FPT (`Magento_Weee`), hidden tax, VAT, Currency (`Magento_Directory`) |
| Inventory Management (MSI) | Daniel Perez Tanco | `Magento_CatalogInventory`, `ext/magento/inventory` (`Magento_Inventory*`, `Magento_InventorySales`, `Magento_InventoryReservations`), `GroupedCatalogInventory`, `ScalableInventory` |
| Data Transfers | Misha Chernenko | Data Import/Export (`Magento_ImportExport`) |
| Catalog & Pricing Management | Misha Chernenko | `Magento_Catalog`, catalog/product URLs, Products, Product attributes, Product Pricing (`Magento_CatalogRule`? see Promotions), `Magento_Pricing`, `Magento_Msrp` |
| PHP Application Server | Misha Chernenko | Application Server |
| Install & Administer | Misha Chernenko | Authorization, Backup, CatalogPermissions, Composer, CurrencySymbol, Deploy, Directory, `Eav`, Enterprise, Locale, MigrationTool, Setup, Store, Timezone, User, `ReleaseNotification`, `SampleData` |
| Admin panel UI Framework | Misha Chernenko | Admin Framework, AdminNotification, Data Grid, `Magento_Backend`, `AdminGWS`, Logging |
| Other Developer Tools | Misha Chernenko | Code Process Framework, Console, Controller Framework, Entity Manager, Module Framework, MVC Model Framework, Session Framework, `SingleTenantCompiler` |
| Core Framework | Misha Chernenko | `Magento\Framework` (Declarative Schema, App, Config, Cron, Bulk Operations, DI, Event, Message Queue (`MessageQueue`, `MysqlMq`), Service Contracts, `ResourceConnections`, `AwsS3`) |
| Test Framework | Misha Chernenko | Integration Test Framework, MFTF, Unit Test Framework |
| Core Upgrade | Misha Chernenko | Upgrade Compatibility Tool |
| Packaging Framework | Misha Chernenko | Repo |
| Merchandising & Promotions | Ritesh Somani | `Magento_SalesRule` (Cart Price Rules), `Magento_CatalogRule` (Catalog price rules), Gift registries, Reward/loyalty (`Magento_Reward`), Product Reviews (`Magento_Review`), Product relationships, Newsletters (`Magento_Newsletter`), SEO, URL Rewrites (`Magento_UrlRewrite`, `Magento_CatalogUrlRewrite`) |
| Shopper Experience & Gift Cards | Ritesh Somani | Shopper Tools, Wish List (`Magento_Wishlist`), Compare, Recently viewed, Reorder, Cancel order, Gift cards (`Magento_GiftCard`, `Magento_GiftCardAccount`) |
| Payments | Badirinath MV | `Magento_Payment`, Payment Services, PayPal (`Magento_Paypal`), Braintree (`Magento_Braintree`), Offline Payment methods (`Magento_OfflinePayments`), Fraud protection |
| Adobe Commerce Marketplace | Badirinath MV | Marketplace |
| AEM Integrations | Cedric Huesler | AEM ↔ Commerce via Commerce Integration Framework (CIF) |
| Cart & Checkout | Olena Kochis | `Magento_Checkout`, `Magento_Quote`, `Magento_QuoteGraphQl`, Shopping cart, Instant Purchase, Shopping assistance |
| Commerce Content & Design | Olena Kochis | Design, Themes, Page setup/layouts, `Magento_Cms`, WYSIWYG, Page Builder (`Magento_PageBuilder`), Content Staging |
| Adobe Commerce Storefront | Olena Kochis | PWA Studio, EDS with Commerce, Blank/Luma themes, JS Framework, RequireJs, Templating, Widget, Localization, Accessibility (WCAG 2.0) |
| SWAT | Sathya Chinnalagu | SWAT |

---

## Cross-Domain Adjacency Map

When a change lands in the **left** domain, the Consensus Gateway MUST also run the dependent-domain test
suites on the **right** (in addition to the change's own domain). Covers the sales/inventory/checkout/pricing
cluster; extend as needed.

| Changed domain | Also run dependent-domain suites |
|---|---|
| Order Management & Delivery | Inventory (MSI), Cart & Checkout, Payments, Merchandising & Promotions, Taxes/Currency, Customer Management, B2B Commerce |
| Cart & Checkout | Order Management & Delivery, Inventory (MSI), Payments, Taxes/Currency, Merchandising & Promotions, Customer Management, Commerce Web APIs (GraphQL) |
| Inventory Management (MSI) | Order Management & Delivery, Cart & Checkout, Catalog & Pricing Management |
| Catalog & Pricing Management | Cart & Checkout, Order Management & Delivery, Merchandising & Promotions, Inventory (MSI), Storefront Services |
| Merchandising & Promotions | Cart & Checkout, Order Management & Delivery, Catalog & Pricing Management |
| Taxes / Currency | Cart & Checkout, Order Management & Delivery |
| Payments | Cart & Checkout, Order Management & Delivery |
| Customer Management | Cart & Checkout, Order Management & Delivery, B2B Commerce |
| B2B Commerce | Cart & Checkout, Order Management & Delivery, Catalog & Pricing Management, Customer Management |
| Core Framework | Cross-cutting — treat as full-suite blast radius; do not rely on a narrow dependent set |
