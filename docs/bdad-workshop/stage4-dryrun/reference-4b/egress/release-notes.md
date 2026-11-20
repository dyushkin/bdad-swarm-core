# Release Notes — ACP2E-5597

## Fixed: Slow cart totals for stores using the "Newsletter Subscription" customer segment condition

**Issue.** On stores with an active cart price rule conditioned on a customer segment that uses "Newsletter Subscription is subscribed", checking a subscribed customer loaded every customer record on each cart totals calculation (for example `POST /rest/V1/carts/mine/totals-information`). On large stores (millions of customers) this caused heavy database load and slow responses.

**Fix.** The newsletter condition check now looks only at the customer being evaluated. The work no longer grows with the number of customers in the store. Segment membership results, newsletter subscription matching per website, and cart price rule discounts are unchanged. This includes the "Newsletter Subscription is not subscribed" condition.

**Behavior change.** If the condition is evaluated for a customer ID that has no customer record, it is now treated as not satisfied. Previously it could be reported as satisfied just because other customers existed.

**Upgrade impact.** No configuration changes or reindex are required. Bulk segment matching is unchanged.
