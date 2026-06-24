# DreamFactory API Builder

`df-api-builder` is a human-first custom API builder for DreamFactory.

The package stores custom API definitions, endpoint definitions, execution
plans, and response mappings. The first runtime slice supports testing a
service-backed endpoint with one or more `service_request` steps.

AI assistance should draft or refine these definitions later, but it is not
required to create, edit, publish, or run custom APIs.

## Initial Service Resources

```text
api-builder/apis
api-builder/endpoints
api-builder/test
```

## First Execution Shape

```json
{
  "endpoint_id": 1,
  "path_params": {
    "id": 42
  },
  "query": {},
  "body": {}
}
```

## Execution-plan step types

- **`service_request`** — dispatch to a backing DF service (database / file /
  remote) in the API's workspace. Selectors (service/resource/method) are
  admin-authored and static; only `params`/`body` resolve caller input.
- **`transform`** — reshape a prior step's data in-memory (no backing request),
  so an endpoint can shape its response without a script. `from` resolves a
  context path; `ops` run in order.

```json
{
  "id": "shaped",
  "type": "transform",
  "from": "{steps.customers.resource}",
  "ops": [
    { "op": "pick", "fields": ["id", "name", "email"] },
    { "op": "rename", "map": { "name": "customer_name" } },
    { "op": "limit", "count": 25 }
  ]
}
```

Transform ops: `pick`, `omit`, `rename`, `defaults`, `filter`, `sort`, `first`,
`limit`, `count`, `wrap`, `unwrap`. Each handles a resource-wrapped list, a bare
list, or a single record.

- `filter` — `{ "op": "filter", "field": "status", "cmp": "eq", "value": "shipped" }`
  (cmp: `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `contains`, `in`).
- `sort` — `{ "op": "sort", "by": "total", "dir": "desc" }`.

## Testing an endpoint (step-by-step trace)

`POST api-builder/test` with `"trace": true` returns an envelope with a
per-step trace — each step's target, status, output preview, and timing, and it
pinpoints the failing step:

```json
{
  "ok": true,
  "result": { "...": "mapped response" },
  "trace": [
    { "key": "customers", "type": "service_request", "service": "db",
      "resource": "_table/customers", "method": "GET", "ok": true,
      "preview": "25 row(s)", "ms": 11 }
  ]
}
```

Without `trace`, the raw result is returned (back-compatible). The admin UI's
**Preview** panel renders this trace.
