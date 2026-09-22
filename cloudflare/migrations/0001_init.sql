-- Thai Nexus WooCommerce plugin - Cloudflare D1 copy (SQLite)
-- Primary store remains WordPress options / order meta. This database is write-only.

CREATE TABLE IF NOT EXISTS shop_config (
    site_url TEXT PRIMARY KEY,
    data TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS order_shipments (
    id TEXT PRIMARY KEY,
    site_url TEXT NOT NULL,
    order_id TEXT NOT NULL,
    data TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_woo_shipments_site ON order_shipments (site_url);

CREATE TABLE IF NOT EXISTS debug_logs (
    id TEXT PRIMARY KEY,
    site_url TEXT NOT NULL,
    logged_at TEXT NOT NULL,
    data TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_woo_debug_site_time ON debug_logs (site_url, logged_at DESC);
