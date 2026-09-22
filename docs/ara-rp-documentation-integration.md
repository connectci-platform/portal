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

**With a structured payload (what the ARA sends today):**
```
/rp-documentation/bridges-2?ara_context=Recommended+for+Python&ara_data=<base64url JSON>
```
See "Structured Recommendations (`ara_data`)" below. `ara_context` is always sent alongside and is the fallback whenever `ara_data` can't be used.

## Parameter Reference

| Parameter | Used On | Description |
|-----------|---------|-------------|
| `ara_context` | All pages | The recommendation text to display in the banner (e.g., "Recommended for Python, Earth Sciences"). On the individual resource page it is the fallback for `ara_data` — see below. |
| `ara_group` | Listing page | Resource group slug(s) to highlight. Single slug or comma-separated for multiple groups sharing the same `ara_context`. |
| `ara_recs` | Listing page | Alternative to `ara_context`+`ara_group` for multiple groups with different contexts. Format: `slug1:context1;slug2:context2` |
| `ara_data` | Individual resource page only | base64url JSON describing this resource's recommendation. See "Structured Recommendations" below. |

## Structured Recommendations (`ara_data`)

On the individual resource page only (not the resource group page, not the listing view), the ARA sends a structured recommendation inline alongside `ara_context`. Handled by `js/rp-ara-banner.js`. Nothing is fetched; there is no ARA API endpoint yet.

### Payload

`ara_data` is base64url (padding optional) of one JSON object for the linked resource:

```json
{
  "global_resource_id": "delta-gpu.ncsa.access-ci.org",
  "name": "Delta GPU",
  "score": 234,
  "reasons": ["NVIDIA H200 141 GB", "256.0 GB Memory", "aocc-mixed", "GPU", "Biological Sciences"],
  "tooltip": "",
  "blurb": "<long ARA-side text, not shown>",
  "rp_docs_description": "<banner body>"
}
```

- `global_resource_id` must equal the page's `field_access_global_resource_id` (passed to the JS as `drupalSettings.aspTheme.ara.globalResourceId`). Otherwise the payload is ignored.
- Banner body: `rp_docs_description`, else `description`, else `ara_context`.
- `reasons` are plain strings, rendered as a plain list under the body. Non-string or empty entries are dropped.
- `blurb`, `name`, `score` and `tooltip` are not displayed.
- There is no version, generated-at or expiry field yet.

Real example links from the ARA team are on D8-2762 and are the Cypress fixtures in `tests/cypress/cypress/e2e/accessmatch2/rp-docs/ara-banner.cy.js`.

### Fallback

If `ara_data` is missing, isn't valid base64url, isn't a JSON object, or is for a different resource, the page silently shows the `ara_context` banner exactly as before `ara_data` existed. No console errors.

### Plain-text-only rendering

Every payload value reaches the DOM via `textContent`/`createTextNode`, never `innerHTML`, so HTML in a payload renders as literal text.

### Persistence and Dismiss

- `ara_recommendation_{node_id}` holds the `ara_context` string (unchanged from before).
- `ara_data_{node_id}` holds the sanitized payload (`global_resource_id`, `reasons`, `rp_docs_description`/`description`).
- A visit carrying either parameter replaces both keys; a visit with neither renders from them, so the banner survives a reload.
- Dismiss removes both keys and hides the banner.

### Deferred: `ara_ref`

An earlier design fetched a recommendation set from an ARA API by `?ara_ref=<id>`. It is deferred until the ARA API exists and is not implemented.

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
- Resource group page and resource page: `ara_recommendation_{node_id}` (context string)
- Individual resource page, structured payload: `ara_data_{node_id}` (see "Structured Recommendations" above)
