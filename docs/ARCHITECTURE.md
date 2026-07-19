# Architecture

## Dispatch path

Registration produces immutable `Route` definitions. On the first dispatch, `RouterEngine` compiles non-regex routes into a radix tree whose edges represent path segments:

- static edges are checked first;
- dynamic edges are ordered by constraint specificity;
- route targets are stored by HTTP method at leaf nodes;
- regex routes remain a fallback for patterns that cannot be segmented.

The engine clones only the matched route to attach request parameter values. The route catalog and tree remain shared and immutable across requests.

## Compiled representations

The memory matcher uses object nodes. The file matcher uses compact arrays containing only static edges (`s`), dynamic edges (`d`), and method-to-route indexes (`r`). Cache payloads are validated while loading; malformed or incompatible data is rejected.

File writes use a temporary file followed by an atomic rename. A fingerprint covers paths, methods, constraints, and regex patterns.

## Middleware lifecycle

Class names are instantiated per request. Shared objects are accepted only when they explicitly declare themselves stateless, cloneable per request, or factories. This makes lifetime decisions visible during review and avoids cross-request state leaks in persistent workers.

Terminable middleware is collected only after its `handle()` method succeeds and terminates in reverse order after the handler.

## Mutation model

Compilation freezes route definitions. Mutating or adding routes afterwards throws unless development code explicitly calls `allowRouteMutation()`. Recompilation is therefore exceptional rather than an accidental hot-path cost.
