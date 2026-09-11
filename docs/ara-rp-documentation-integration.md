# ARA Integration with RP Documentation Pages

The ACCESS Resource Advisor (ARA) can link users to RP documentation pages with recommendation context that highlights why specific resources or resource groups were recommended.

## URL Parameters

### Listing Page (`/rp-documentation`)

Link to the listing page with one or more resource groups highlighted.

**Single group recommendation:**
```
/rp-documentation?ara_context=Recommended+for+Python,+Earth+Sciences&ara_group=gpu-computing
```

**Multiple groups, same recommendation context:**
```
/rp-documentation?ara_context=Recommended+for+Python&ara_group=gpu-computing,hpc-clusters
```

**Multiple groups, different contexts:**
```
/rp-documentation?ara_recs=gpu-computing:Recommended+for+Python;hpc-clusters:Good+for+large+memory+jobs
```

### Resource Group Detail Page (`/rp-documentation/{group-slug}`)

Link directly to a resource group page:
```
/rp-documentation/gpu-computing?ara_context=Recommended+for+Python,+Earth+Sciences
```

### Individual Resource Page (`/rp-documentation/{resource-slug}`)

Link directly to an individual resource page:
```
/rp-documentation/bridges-2?ara_context=Recommended+for+Python,+large+memory+jobs
```

**Structured recommendation (preferred):**
```
/rp-documentation/bridges-2?ara_ref=<opaque id>
```
See "Structured Recommendations (`ara_ref`)" below. `ara_context` remains supported on the resource page as a legacy fallback — see that section for when each path is used.

## Parameter Reference

| Parameter | Used On | Description |
|-----------|---------|-------------|
| `ara_context` | All pages | The recommendation text to display in the banner (e.g., "Recommended for Python, Earth Sciences"). On the individual resource page this is a **legacy** path — see below. |
| `ara_group` | Listing page | Resource group slug(s) to highlight. Single slug or comma-separated for multiple groups sharing the same `ara_context`. |
| `ara_recs` | Listing page | Alternative to `ara_context`+`ara_group` for multiple groups with different contexts. Format: `slug1:context1;slug2:context2` |
| `ara_ref` | Individual resource page only | An opaque recommendation-set id. The page fetches the full structured recommendation set from ARA and renders this resource's entry. See "Structured Recommendations" below. |

## Structured Recommendations (`ara_ref`)

On the individual resource page only (not the resource group page, not the listing view), ARA can hand off a richer, structured recommendation instead of a single free-text string.

### Flow

1. ARA links to the resource page with `?ara_ref=<opaque id>`.
2. The page (`js/rp-ara-banner.js`) fetches the full recommendation set from a theme-configured ARA endpoint: `endpoint + encodeURIComponent(ref)`. The endpoint origin/path comes only from `drupalSettings.aspTheme.ara.endpoint` (a theme setting, `ara_endpoint`, defaulting to `https://ara.access-ci.org/api/recommendations/`) — **never** from the URL or from anything in the fetched payload, so a crafted link can't redirect the fetch elsewhere.
3. The response is validated and cached in `localStorage` under a single `ara_recommendations` key (see below).
4. The page looks up this resource's entry by a **resource key** and renders it.

### Resource key (pending confirmation with ARA)

The resource key used to look up this node's entry in the payload's `resources` map is currently assumed to be the node's `field_access_global_resource_id` value (`drupalSettings.aspTheme.ara.resourceKey`, set in `aspTheme_preprocess_node__access_active_resources_from_cid()`). **This contract is not yet confirmed with the ARA team** and may change once the API is finalized. If the node has no global resource id, `resourceKey` is `NULL` and the page falls back to the legacy `ara_context` behavior for that resource.

### Payload shape

```json
{
  "expires_at": "2026-09-25T00:00:00Z",
  "resources": {
    "<resourceKey>": {
      "description": "Recommended because your project uses GPU-accelerated ML workloads.",
      "reasons": [
        { "type": "hardware", "label": "Has A100 GPUs" },
        { "type": "software", "label": "TensorFlow preinstalled" },
        { "type": "history", "label": "You used this resource before" }
      ],
      "score": 0.87
    }
  }
}
```

- `resources` is required; each entry's `reasons` must be an array of `{type, label}` string pairs and `description` must be a string. Anything that doesn't match this shape is dropped rather than rendered.
- `reasons[].type` is mapped through a hardcoded lookup (`hardware`, `software`, `history`) to a label prefix in the UI; unknown types render the label alone. `type` is never used to build markup or a class name.
- `score` is stored but **not currently rendered** — its meaning and any tooltip/display semantics are **pending confirmation with the ARA team**.
- `expires_at` (ISO 8601) is optional; see Expiry below.

### Plain-text-only rendering

Every value from the payload (`description`, reason `label`s) reaches the DOM exclusively via `textContent`/`createTextNode`. Nothing from the payload is ever assigned via `innerHTML` or interpolated into a template string that becomes markup, so HTML in a payload value (accidental or malicious) always renders as literal text, never as markup.

### `ara_recommendations` localStorage key

A single key holds the whole cached recommendation set for the current `ara_ref`:

```json
{
  "version": 1,
  "ref": "<the ara_ref that was fetched>",
  "fetchedAt": 1758700000000,
  "expiresAt": 1759909600000,
  "resources": { "...": "sanitized entries, same shape as the payload's resources map" }
}
```

A new `ara_ref` (different from the cached `ref`) or a missing/expired cache triggers a re-fetch; the same `ara_ref` reuses the cache without a network call.

### Expiry and cache cap

- If the payload includes `expires_at`, it is honored.
- Independently, the cache is capped at 14 days from fetch time regardless of `expires_at` — whichever expiry is sooner wins.
- On every read, if the current time is at or past the effective expiry, the `ara_recommendations` key is deleted and nothing is rendered for that resource.

### Legacy fallback (`ara_context`)

If there's no usable structured entry for this resource (no resource key, no cached entry, the fetch failed, the ref is unknown, etc.), the page falls back to the original behavior:
- `?ara_context=<text>` from the URL is persisted to `ara_recommendation_{node_id}` and rendered as plain text.
- Absent that, the existing `ara_recommendation_{node_id}` value (if any) is rendered.

`ara_context` is **legacy but still supported** on the resource page for this reason — existing ARA links using it keep working.

### Dismiss

The Dismiss button clears whichever source fed the currently-visible banner — the structured entry for this resource, or the legacy `ara_recommendation_{node_id}` string — and hides the banner. Dismissal survives a reload.

## Group and Resource Slugs

Slugs are the last segment of the URL path alias. For example:
- Page URL: `/rp-documentation/gpu-computing` -> slug: `gpu-computing`
- Page URL: `/rp-documentation/bridges-2` -> slug: `bridges-2`

Slugs are set by the pathauto pattern from the node title, or manually via the URL alias field on the node edit form.

## Behavior

### Banner Display
- A recommendation banner appears above the targeted resource group or at the top of the resource page
- The banner shows the `ara_context` text explaining why the resource was recommended
- On the listing page, targeted group rows are highlighted with a blue ring border

### Navigation
- The listing page auto-scrolls to the first recommended group
- The `ara_context` parameter is passed through to individual resource links within a highlighted group, so the banner persists when navigating from the listing to a resource page

### Persistence
- Recommendations are stored in the browser's `localStorage` and persist across page reloads
- Each banner has a "Dismiss" button that removes that specific recommendation
- On the listing page, dismissing one group's recommendation does not affect others
- Multiple ARA visits accumulate recommendations — new groups are added, existing ones are updated with the latest context

### localStorage Keys
- Listing page: `ara_rp_recommendations` (JSON array of `{group, context}` objects)
- Resource group page and legacy resource-page path: `ara_recommendation_{node_id}` (context string)
- Individual resource page, structured path: `ara_recommendations` (single key, all resources — see "Structured Recommendations" above)
