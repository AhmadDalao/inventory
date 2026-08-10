# Mobile Workflow Map

```mermaid
flowchart TD
    A["Employee signs in"] --> B["API checks mobile access, device, version, permissions, and storages"]
    B --> C["Flutter caches authorized catalog and balances"]
    C --> D{"Employee action"}
    D -->|Check| E["Scan or search item"]
    D -->|Use| F["Build usage review cart"]
    D -->|Receive| G["Open handover by reference"]
    D -->|Transfer| H["Build accountable transfer cart"]
    D -->|Custody| I["Build long-term custody cart"]
    E --> J["Show assigned-storage balance and sync time"]
    F --> K["Submit online or save offline draft"]
    G --> L["Report exact, short, or excess receipt"]
    H --> K
    I --> K
    K --> M["PHP rechecks token, permission, storage, idempotency, and balance"]
    M -->|Valid| N["Database transaction posts movements or workflow state"]
    M -->|Stale balance| O["409 conflict: employee reviews latest quantity"]
    M -->|Duplicate operation| P["Return original successful result"]
    N --> Q["Immutable movement and audit history"]
```

## Authority Boundary

The Flutter application is a field interface. The PHP application owns final permissions, balances, workflow transitions, proof storage, and audit history. No offline draft changes stock.
