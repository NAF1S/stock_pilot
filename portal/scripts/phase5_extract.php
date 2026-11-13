<?php
/**
 * Phase 5 extraction: emit the raw datasets as one JSON document.
 *
 * Usage: php phase5_extract.php <org_id> <store_id> <start_date>
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$org = (int) ($argv[1] ?? 1);
$store = (int) ($argv[2] ?? 1);
$start = $argv[3] ?? '2026-08-02';

$data = [];

fwrite(STDERR, "entities\n");
$items = DB::select("
    SELECT 'item' AS entity_type, id, name, sku, NULL::bigint AS store_id
    FROM sp_items WHERE organization_id = ? AND deleted_at IS NULL
    ORDER BY id
", [$org]);
$products = DB::select("
    SELECT 'product' AS entity_type, id, name, sku, store_id
    FROM sp_products WHERE organization_id = ? AND store_id = ? AND deleted_at IS NULL
    ORDER BY id
", [$org, $store]);
$data['entities'] = array_merge($items, $products);

fwrite(STDERR, "consumption\n");
$data['consumption'] = DB::select("
    WITH confirmations AS (
        SELECT m.organization_id, m.item_id, m.product_id,
               date_trunc('day', COALESCE(m.occurred_at, m.created_at))::date AS ds,
               SUM(ABS(m.qty_delta)) AS consumed
        FROM sp_item_movements m
        WHERE m.movement_type = 'confirm' AND m.qty_delta < 0
          AND m.organization_id = ?
          AND COALESCE(m.occurred_at, m.created_at) >= ?
          AND NOT EXISTS (SELECT 1 FROM sp_movement_exclusions e WHERE e.movement_id = m.id)
        GROUP BY m.organization_id, m.item_id, m.product_id, ds
    ), returns AS (
        SELECT r.organization_id, r.item_id, r.product_id,
               date_trunc('day', COALESCE(r.occurred_at, r.created_at))::date AS ds,
               SUM(ABS(r.qty_delta)) AS returned
        FROM sp_item_movements r
        WHERE r.movement_type = 'return'
          AND r.organization_id = ?
          AND COALESCE(r.occurred_at, r.created_at) >= ?
          AND NOT EXISTS (SELECT 1 FROM sp_movement_exclusions e WHERE e.movement_id = r.id)
        GROUP BY r.organization_id, r.item_id, r.product_id, ds
    )
    SELECT c.organization_id, c.item_id, c.product_id, c.ds, c.consumed,
           COALESCE(r.returned, 0) AS returned,
           GREATEST(c.consumed - COALESCE(r.returned, 0), 0) AS net_consumed
    FROM confirmations c
    LEFT JOIN returns r ON r.organization_id = c.organization_id AND r.ds = c.ds
      AND ((c.item_id IS NOT NULL AND r.item_id = c.item_id)
           OR (c.item_id IS NULL AND c.product_id IS NOT NULL AND r.product_id = c.product_id))
    ORDER BY c.ds, c.item_id, c.product_id
", [$org, $start, $org, $start]);

fwrite(STDERR, "traffic\n");
$data['traffic'] = DB::select("
    SELECT product_id, traffic_date AS ds, page_views, add_to_cart, cart_removals,
           sessions, unique_visitors, source
    FROM sp_traffic_daily
    WHERE organization_id = ? AND store_id = ? AND traffic_date >= ?
", [$org, $store, $start]);

fwrite(STDERR, "site\n");
$data['site'] = DB::select("
    SELECT traffic_date AS ds, page_views AS site_views, unique_visitors AS site_visitors,
           cart_page_views, checkout_page_views
    FROM sp_traffic_daily_org
    WHERE organization_id = ? AND store_id = ?
      AND source = 'jetpack' AND traffic_date >= ?
    ORDER BY traffic_date
", [$org, $store, $start]);

fwrite(STDERR, "referrers\n");
$data['referrers'] = DB::select("
    SELECT traffic_date AS ds,
           COALESCE(SUM(page_views) FILTER (WHERE referrer_group = 'facebook.com'), 0) AS facebook_views,
           COALESCE(SUM(page_views) FILTER (WHERE referrer_group = 'instagram.com'), 0) AS instagram_views,
           COALESCE(SUM(page_views) FILTER (WHERE referrer_group ILIKE '%search%'), 0) AS search_views
    FROM sp_traffic_referrers_daily
    WHERE organization_id = ? AND store_id = ?
      AND source = 'jetpack' AND traffic_date >= ?
    GROUP BY traffic_date ORDER BY traffic_date
", [$org, $store, $start]);

fwrite(STDERR, "product_orders\n");
$data['product_orders'] = DB::select("
    SELECT oi.product_id AS entity_id, o.order_created_at::date AS ds,
           COUNT(DISTINCT o.id) AS order_count,
           COALESCE(SUM(oi.line_total), 0) AS revenue,
           COALESCE(SUM(oi.quantity), 0) AS units
    FROM sp_orders o
    JOIN sp_order_items oi ON oi.order_id = o.id
    WHERE o.organization_id = ? AND o.store_id = ?
      AND oi.product_id IS NOT NULL AND o.order_created_at >= ?
    GROUP BY oi.product_id, ds ORDER BY entity_id, ds
", [$org, $store, $start]);

fwrite(STDERR, "item_orders\n");
$data['item_orders'] = DB::select("
    SELECT c.item_id AS entity_id, o.order_created_at::date AS ds,
           COUNT(DISTINCT o.id) AS order_count,
           COALESCE(SUM(o.total), 0) AS revenue
    FROM sp_orders o
    JOIN sp_order_item_components c ON c.order_id = o.id
    WHERE o.organization_id = ? AND o.store_id = ?
      AND c.item_id IS NOT NULL AND o.order_created_at >= ?
    GROUP BY c.item_id, ds ORDER BY entity_id, ds
", [$org, $store, $start]);

fwrite(STDERR, "product_returns\n");
$data['product_returns'] = DB::select("
    SELECT oi.product_id AS entity_id, o.returned_at::date AS ds,
           COALESCE(SUM(oi.quantity), 0) AS returned
    FROM sp_orders o
    JOIN sp_order_items oi ON oi.order_id = o.id
    WHERE o.organization_id = ? AND o.store_id = ?
      AND oi.product_id IS NOT NULL
      AND o.returned_at IS NOT NULL AND o.returned_at >= ?
      AND o.status IN ('returned', 'returned-charge')
    GROUP BY oi.product_id, ds ORDER BY entity_id, ds
", [$org, $store, $start]);

fwrite(STDERR, "stock\n");
$data['stock'] = DB::select("
    SELECT ds, item_id, product_id, on_hand, reserved FROM (
        SELECT date_trunc('day', COALESCE(occurred_at, created_at))::date AS ds,
               item_id, product_id, on_hand_after AS on_hand, reserved_after AS reserved,
               row_number() OVER (
                   PARTITION BY item_id, product_id,
                                date_trunc('day', COALESCE(occurred_at, created_at))::date
                   ORDER BY COALESCE(occurred_at, created_at) DESC, id DESC
               ) AS rn
        FROM sp_item_movements
        WHERE organization_id = ?
          AND COALESCE(occurred_at, created_at) >= ?
          AND (item_id IS NOT NULL OR product_id IS NOT NULL)
    ) latest WHERE rn = 1 ORDER BY ds
", [$org, $start]);

fwrite(STDERR, "reserved\n");
$data['reserved'] = DB::select("
    SELECT date_trunc('day', COALESCE(occurred_at, created_at))::date AS ds,
           item_id, product_id, COALESCE(SUM(reserved_delta), 0) AS reserved
    FROM sp_item_movements
    WHERE organization_id = ? AND movement_type = 'reserve'
      AND COALESCE(occurred_at, created_at) >= ?
    GROUP BY ds, item_id, product_id ORDER BY ds
", [$org, $start]);

fwrite(STDERR, "events\n");
$data['events'] = DB::select("
    SELECT event_date, event_type, event_name, impact_factor
    FROM ml_calendar_events
    WHERE (organization_id = ? OR organization_id IS NULL)
      AND event_date >= ?
      AND event_date <= ?
    ORDER BY event_date
", [$org, $start, date('Y-m-d')]);

echo json_encode($data, JSON_THROW_ON_ERROR);
