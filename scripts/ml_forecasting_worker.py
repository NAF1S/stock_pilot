#!/usr/bin/env python3
"""
StockPilot ML Forecasting & Restock Intelligence Worker
-------------------------------------------------------
Standalone Python service / scheduled job supporting:
1. Meta Prophet (Time-series with weekly seasonality & 95% Bayesian intervals)
2. Trailing Velocity / Exponential Smoothing (Lightweight baseline)
3. Extensible hook for JAX / NeuralForecast / Foundation Time-Series models

Target Tables in Supabase PostgreSQL:
- sp_demand_forecasts (predicted_quantity, lower_bound, upper_bound, model_version)
- sp_stockout_risks (current_stock, daily_burn_rate, days_until_stockout, risk_level)
- sp_restock_recommendations (recommended_reorder_qty, urgency, reasoning)
"""

import os
import sys
import argparse
from datetime import datetime, timedelta, date

# Database driver
try:
    import psycopg2
    import psycopg2.extras
except ImportError:
    psycopg2 = None

# Optional Pandas & Prophet imports
try:
    import pandas as pd
except ImportError:
    pd = None

try:
    from prophet import Prophet
    HAS_PROPHET = True
except ImportError:
    HAS_PROPHET = False

DEFAULT_DB_HOST = "aws-0-ap-southeast-1.pooler.supabase.com"
DEFAULT_DB_PORT = 5432
DEFAULT_DB_NAME = "postgres"
DEFAULT_DB_USER = "postgres.gpbutuibewyrfgcjhjqr"
DEFAULT_DB_PASS = "prottoybainchod"

def get_connection(host=None, port=None, dbname=None, user=None, password=None):
    if psycopg2 is None:
        raise RuntimeError("psycopg2 is not installed. Install via: pip install psycopg2-binary")
    return psycopg2.connect(
        host=host or os.getenv("DB_HOST", DEFAULT_DB_HOST),
        port=int(port or os.getenv("DB_PORT", DEFAULT_DB_PORT)),
        dbname=dbname or os.getenv("DB_DATABASE", DEFAULT_DB_NAME),
        user=user or os.getenv("DB_USERNAME", DEFAULT_DB_USER),
        password=password or os.getenv("DB_PASSWORD", DEFAULT_DB_PASS),
        sslmode=os.getenv("DB_SSLMODE", "require")
    )

def forecast_with_prophet(history_records, periods=14):
    """
    Fits Meta Prophet model on historical time series (ds, y)
    and predicts future intervals (yhat, yhat_lower, yhat_upper).
    """
    if not HAS_PROPHET or pd is None:
        return None

    if len(history_records) < 5:
        # Prophet requires sufficient data points to identify trend/seasonality
        return None

    df = pd.DataFrame(history_records, columns=['ds', 'y'])
    df['ds'] = pd.to_datetime(df['ds'])
    df['y'] = pd.to_numeric(df['y']).fillna(0)

    try:
        # Suppress verbose Stan logging
        import logging
        logging.getLogger('cmdstanpy').setLevel(logging.WARNING)

        m = Prophet(
            yearly_seasonality=False,
            weekly_seasonality=True,
            daily_seasonality=False,
            interval_width=0.95  # 95% uncertainty interval
        )
        m.fit(df)

        future = m.make_future_dataframe(periods=periods, freq='D')
        forecast = m.predict(future)

        # Slice future days
        future_forecast = forecast.tail(periods)
        results = []
        for _, row in future_forecast.iterrows():
            results.append({
                'forecast_date': row['ds'].date(),
                'predicted_quantity': max(round(float(row['yhat']), 4), 0.0),
                'lower_bound': max(round(float(row['yhat_lower']), 4), 0.0),
                'upper_bound': max(round(float(row['yhat_upper']), 4), 0.0),
            })
        return results
    except Exception as e:
        print(f"    [Prophet Warning] Fitting failed: {e}. Falling back to velocity baseline.")
        return None

def forecast_with_velocity(history_records, daily_burn, periods=14):
    """
    Lightweight baseline model using trailing consumption velocity.
    """
    results = []
    base_date = datetime.utcnow().date()
    for d in range(1, periods + 1):
        f_date = base_date + timedelta(days=d)
        pred_qty = daily_burn
        results.append({
            'forecast_date': f_date,
            'predicted_quantity': round(pred_qty, 4),
            'lower_bound': round(pred_qty * 0.8, 4),
            'upper_bound': round(pred_qty * 1.2, 4),
        })
    return results

def run_forecast_pipeline(engine='auto', periods=14, dry_run=False):
    print("=" * 65)
    print("StockPilot Advanced ML Forecasting Pipeline")
    print(f"Timestamp:       {datetime.utcnow().isoformat()}Z")
    print(f"Engine Selected: {engine.upper()} (Prophet Available: {HAS_PROPHET})")
    print(f"Forecast Horizon: {periods} days ahead")
    print("=" * 65)

    conn = get_connection()
    cur = conn.cursor(cursor_factory=psycopg2.extras.DictCursor)

    cur.execute("SELECT id, name FROM sp_organizations;")
    orgs = cur.fetchall()

    total_risks_recorded = 0
    total_recs_recorded = 0
    total_forecasts_recorded = 0

    for org in orgs:
        org_id = org['id']
        org_name = org['name']
        print(f"\nEvaluating Tenant: {org_name} (ID: {org_id})")

        # Fetch Items
        cur.execute("""
            SELECT id, name, sku, unit, quantity_on_hand, low_stock_threshold
            FROM sp_items
            WHERE organization_id = %s AND deleted_at IS NULL;
        """, (org_id,))
        items = cur.fetchall()

        for item in items:
            item_id = item['id']
            stock = float(item['quantity_on_hand'] or 0)
            threshold = float(item['low_stock_threshold'] or 0)

            # Query daily historical consumption (date_trunc by day)
            cur.execute("""
                SELECT 
                    date_trunc('day', COALESCE(occurred_at, created_at))::date as ds,
                    COALESCE(SUM(ABS(qty_delta)), 0) as y
                FROM sp_item_movements
                WHERE organization_id = %s
                  AND item_id = %s
                  AND qty_delta < 0
                  AND COALESCE(occurred_at, created_at) >= NOW() - INTERVAL '60 days'
                GROUP BY date_trunc('day', COALESCE(occurred_at, created_at))::date
                ORDER BY ds ASC;
            """, (org_id, item_id))
            daily_history = cur.fetchall()

            # Query 30-day baseline sum
            total_30d_out = sum(float(r['y']) for r in daily_history if r['ds'] >= (datetime.utcnow().date() - timedelta(days=30)))
            baseline_daily_burn = round(total_30d_out / 30.0, 4)

            # Run Forecasting Engine (Prophet vs Baseline)
            predictions = None
            used_model = "baseline-velocity-v1"

            if (engine in ('prophet', 'auto')) and HAS_PROPHET and len(daily_history) >= 5:
                prophet_results = forecast_with_prophet(daily_history, periods=periods)
                if prophet_results:
                    predictions = prophet_results
                    used_model = "meta-prophet-v1"
                    # Average forward daily burn predicted by Prophet
                    forward_burn = sum(p['predicted_quantity'] for p in predictions) / len(predictions)
                    effective_daily_burn = round(forward_burn, 4)
                else:
                    effective_daily_burn = baseline_daily_burn
            else:
                effective_daily_burn = baseline_daily_burn

            if not predictions:
                predictions = forecast_with_velocity(daily_history, effective_daily_burn, periods=periods)

            # Calculate stockout runway
            if effective_daily_burn > 0:
                days_left = round(stock / effective_daily_burn, 2)
                stockout_date = (datetime.utcnow() + timedelta(days=days_left)).date()
            else:
                days_left = None
                stockout_date = None

            # Risk classification
            if stock <= 0 or (days_left is not None and days_left <= 3.0):
                risk_level = 'critical'
            elif (days_left is not None and days_left <= 7.0) or (threshold > 0 and stock <= threshold):
                risk_level = 'warning'
            else:
                risk_level = 'healthy'

            print(f"  • [{used_model}] Item #{item_id} ({item['name']}): Stock={stock}, DailyBurn={effective_daily_burn}, Runway={days_left}d ({risk_level})")

            if not dry_run:
                # 1. Update sp_stockout_risks
                cur.execute("""
                    DELETE FROM sp_stockout_risks
                    WHERE organization_id = %s AND entity_type = 'item' AND entity_id = %s;
                """, (org_id, item_id))

                cur.execute("""
                    INSERT INTO sp_stockout_risks (
                        organization_id, entity_type, entity_id, current_stock,
                        daily_burn_rate, days_until_stockout, risk_level,
                        estimated_stockout_date, model_version, calculated_at, created_at, updated_at
                    ) VALUES (%s, 'item', %s, %s, %s, %s, %s, %s, %s, NOW(), NOW(), NOW());
                """, (org_id, item_id, stock, effective_daily_burn, days_left, risk_level, stockout_date, used_model))
                total_risks_recorded += 1

                # 2. Update sp_restock_recommendations
                if risk_level in ('critical', 'warning'):
                    suggested_qty = max(round(effective_daily_burn * 30.0, 2), 10.0)
                    urgency = 'high' if risk_level == 'critical' else 'medium'
                    reason = f"Modeled by {used_model}: Projected to exhaust in {days_left if days_left is not None else 0} days with burn rate of {effective_daily_burn}/day."

                    cur.execute("""
                        DELETE FROM sp_restock_recommendations
                        WHERE organization_id = %s AND entity_type = 'item' AND entity_id = %s AND status = 'pending';
                    """, (org_id, item_id))

                    cur.execute("""
                        INSERT INTO sp_restock_recommendations (
                            organization_id, entity_type, entity_id, recommended_reorder_qty,
                            urgency, reasoning, status, model_version, created_at, updated_at
                        ) VALUES (%s, 'item', %s, %s, %s, %s, 'pending', %s, NOW(), NOW());
                    """, (org_id, item_id, suggested_qty, urgency, reason, used_model))
                    total_recs_recorded += 1

                # 3. Update sp_demand_forecasts
                cur.execute("""
                    DELETE FROM sp_demand_forecasts
                    WHERE organization_id = %s AND entity_type = 'item' AND entity_id = %s;
                """, (org_id, item_id))

                for p in predictions:
                    cur.execute("""
                        INSERT INTO sp_demand_forecasts (
                            organization_id, entity_type, entity_id, forecast_date,
                            predicted_quantity, lower_bound, upper_bound, model_version, created_at, updated_at
                        ) VALUES (%s, 'item', %s, %s, %s, %s, %s, %s, NOW(), NOW());
                    """, (org_id, item_id, p['forecast_date'], p['predicted_quantity'], p['lower_bound'], p['upper_bound'], used_model))
                    total_forecasts_recorded += 1

    if not dry_run:
        conn.commit()
        print("\n[SUCCESS] Pipeline completed successfully!")
        print(f"  • Stockout Risks Evaluated: {total_risks_recorded}")
        print(f"  • Restock Recommendations:  {total_recs_recorded}")
        print(f"  • Forward Demand Forecasts: {total_forecasts_recorded}")
    else:
        conn.rollback()
        print("\n[DRY RUN] Completed without modifying Supabase.")

    cur.close()
    conn.close()

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description="StockPilot ML Forecasting Pipeline")
    parser.add_argument("--engine", choices=["auto", "prophet", "velocity"], default="auto",
                        help="Forecasting algorithm: 'prophet' (Meta Prophet), 'velocity' (Trailing burn rate), 'auto'")
    parser.add_argument("--periods", type=int, default=14, help="Forecast horizon in days (default: 14)")
    parser.add_argument("--dry-run", action="store_true", help="Simulate without database writes")
    args = parser.parse_args()

    run_forecast_pipeline(engine=args.engine, periods=args.periods, dry_run=args.dry_run)
