type CopyKind = 'settings' | 'shipment' | 'debug' | 'debug_clear';

type CopyBody = {
    kind?: CopyKind;
    site_url?: string;
    payload?: Record<string, unknown>;
};

function json(data: unknown, status = 200): Response {
    return Response.json(data, { status, headers: { 'Cache-Control': 'no-store' } });
}

function unauthorized(): Response {
    return json({ ok: false, error: 'unauthorized' }, 401);
}

export default {
    async fetch(request: Request, env: { DB: D1Database; INGEST_SECRET?: string }): Promise<Response> {
        const url = new URL(request.url);
        if (url.pathname === '/health') {
            let d1 = false;
            try {
                await env.DB.prepare('SELECT 1 AS ok').first();
                d1 = true;
            } catch {
                d1 = false;
            }
            return json({ ok: true, d1 });
        }

        if (request.method !== 'POST' || url.pathname !== '/copy') {
            return json({ ok: false, error: 'not_found' }, 404);
        }

        const secret = String(env.INGEST_SECRET || '').trim();
        const provided = (request.headers.get('X-TNXL-Key') || '').trim();
        if (!secret || provided !== secret) {
            return unauthorized();
        }

        let body: CopyBody = {};
        try {
            body = (await request.json()) as CopyBody;
        } catch {
            return json({ ok: false, error: 'invalid_json' }, 400);
        }

        const siteUrl = String(body.site_url || '').trim();
        const kind = body.kind;
        if (!siteUrl || !kind) {
            return json({ ok: false, error: 'missing_fields' }, 400);
        }

        try {
            if (kind === 'settings') {
                await env.DB.prepare(
                    `INSERT INTO shop_config (site_url, data, updated_at)
                     VALUES (?, ?, ?)
                     ON CONFLICT(site_url) DO UPDATE SET
                       data = excluded.data,
                       updated_at = excluded.updated_at`
                )
                    .bind(siteUrl, JSON.stringify(body.payload ?? {}), new Date().toISOString())
                    .run();
            } else if (kind === 'shipment') {
                const orderId = String(body.payload?.order_id || '');
                if (!orderId) return json({ ok: false, error: 'missing_order_id' }, 400);
                const id = `${siteUrl}_${orderId}`;
                await env.DB.prepare(
                    `INSERT INTO order_shipments (id, site_url, order_id, data, created_at)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT(id) DO UPDATE SET
                       data = excluded.data,
                       created_at = excluded.created_at`
                )
                    .bind(id, siteUrl, orderId, JSON.stringify(body.payload ?? {}), new Date().toISOString())
                    .run();
            } else if (kind === 'debug') {
                const id = String(body.payload?.id || `log_${Date.now()}`);
                const loggedAt = String(body.payload?.timestamp || new Date().toISOString());
                await env.DB.prepare(
                    `INSERT INTO debug_logs (id, site_url, logged_at, data)
                     VALUES (?, ?, ?, ?)
                     ON CONFLICT(id) DO UPDATE SET data = excluded.data`
                )
                    .bind(id, siteUrl, loggedAt, JSON.stringify(body.payload ?? {}))
                    .run();
            } else if (kind === 'debug_clear') {
                await env.DB.prepare('DELETE FROM debug_logs WHERE site_url = ?').bind(siteUrl).run();
            } else {
                return json({ ok: false, error: 'unknown_kind' }, 400);
            }
        } catch (err) {
            console.warn('[woo-d1]', err instanceof Error ? err.message : err);
            return json({ ok: false, error: 'd1_write_failed' }, 500);
        }

        return json({ ok: true });
    },
};
