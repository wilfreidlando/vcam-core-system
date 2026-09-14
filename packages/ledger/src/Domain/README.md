# Domain

Pure PHP. Entities, value objects, domain events, domain exceptions, and **ports**
(the interfaces this context needs from the outside: repositories, provider gateways,
clocks). No Laravel, no Eloquent, no HTTP, no database — enforced by Deptrac.

This is the layer that survives a framework upgrade, a persistence change, or the
extraction of this module into its own service.
