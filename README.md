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

The endpoint execution plan currently supports `service_request` steps.
