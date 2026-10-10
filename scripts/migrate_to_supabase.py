#!/usr/bin/env python3
import os
import re
import sys
import subprocess

def get_target_columns():
    """Query PostgreSQL schema for column types and nullable flags."""
    cmd = [
        'psql',
        '-h', 'aws-0-ap-southeast-1.pooler.supabase.com',
        '-p', '5432',
        '-U', 'postgres.gpbutuibewyrfgcjhjqr',
        '-d', 'postgres',
        '-t', '-A', '-F', '|',
        '-c', "SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema = 'public';"
    ]
    env = dict(os.environ, PGPASSWORD='prottoybainchod')
    res = subprocess.run(cmd, env=env, capture_output=True, text=True, check=True)
    
    col_map = {}
    for line in res.stdout.strip().splitlines():
        if not line or '|' not in line:
            continue
        parts = line.split('|')
        if len(parts) >= 3:
            tbl, col, dtype = parts[0], parts[1], parts[2]
            if tbl not in col_map:
                col_map[tbl] = {}
            col_map[tbl][col] = dtype
    return col_map

def unescape_mysql_string(val):
    """Unescape MySQL string contents between single quotes."""
    if val.startswith("'") and val.endswith("'"):
        inner = val[1:-1]
        res = []
        i = 0
        n = len(inner)
        while i < n:
            if inner[i] == '\\' and i + 1 < n:
                nxt = inner[i+1]
                if nxt == "'":
                    res.append("'")
                elif nxt == '"':
                    res.append('"')
                elif nxt == '\\':
                    res.append('\\')
                elif nxt == 'n':
                    res.append('\n')
                elif nxt == 'r':
                    res.append('\r')
                elif nxt == 't':
                    res.append('\t')
                elif nxt == '0':
                    res.append('')
                elif nxt == 'Z':
                    res.append('\x1a')
                else:
                    res.append(nxt)
                i += 2
            elif inner[i] == "'" and i + 1 < n and inner[i+1] == "'":
                res.append("'")
                i += 2
            else:
                res.append(inner[i])
                i += 1
        return ''.join(res)
    return val

def format_pg_literal(val, col_type):
    """Format parsed token as valid PostgreSQL literal according to column type."""
    val_strip = val.strip()
    if val_strip.upper() == 'NULL' or val_strip == '':
        return 'NULL'

    if col_type == 'boolean':
        if val_strip in ('1', "'1'", 'true', "'true'", 't', 'TRUE'):
            return 'TRUE'
        elif val_strip in ('0', "'0'", 'false', "'false'", 'f', 'FALSE'):
            return 'FALSE'
        return 'NULL'

    if val_strip.startswith("'") and val_strip.endswith("'"):
        unescaped = unescape_mysql_string(val_strip)
        escaped = unescaped.replace("'", "''")
        return f"'{escaped}'"

    if col_type in ('numeric', 'decimal', 'double precision', 'real', 'integer', 'bigint', 'smallint'):
        # In case a numeric is enclosed in quotes
        if val_strip.startswith("'") and val_strip.endswith("'"):
            num_val = val_strip[1:-1].strip()
            return num_val if num_val else 'NULL'
        return val_strip

    return val_strip

def parse_tuple_values(values_str):
    """Tokenize the comma-separated tuples in an INSERT VALUES block."""
    rows = []
    i = 0
    n = len(values_str)
    
    while i < n:
        while i < n and values_str[i] != '(':
            i += 1
        if i >= n:
            break
        i += 1
        
        current_row = []
        val = []
        in_quote = False
        escape = False
        
        while i < n:
            ch = values_str[i]
            if escape:
                val.append(ch)
                escape = False
                i += 1
                continue
                
            if ch == '\\':
                escape = True
                val.append(ch)
                i += 1
                continue
                
            if ch == "'":
                in_quote = not in_quote
                val.append(ch)
                i += 1
                continue
                
            if not in_quote:
                if ch == ',':
                    current_row.append(''.join(val).strip())
                    val = []
                    i += 1
                    continue
                elif ch == ')':
                    current_row.append(''.join(val).strip())
                    rows.append(current_row)
                    i += 1
                    break
            val.append(ch)
            i += 1
            
    return rows

def main():
    print("Fetching PostgreSQL schema from Supabase...")
    target_cols = get_target_columns()
    print(f"Target schema contains {len(target_cols)} tables.")

    sql_dump_path = os.path.join(os.path.dirname(__file__), '..', 'hidehave_spdb.sql')
    print(f"Reading SQL dump from {sql_dump_path}...")
    with open(sql_dump_path, 'r', encoding='utf-8', errors='replace') as f:
        content = f.read()

    # Tables to ignore (framework transient tables)
    ignore_tables = {'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'sessions', 'migrations'}

    # Dependency order for inserting records cleanly
    table_order = [
        'sp_organizations',
        'sp_users',
        'sp_store_connections',
        'sp_categories',
        'sp_items',
        'sp_products',
        'sp_bom_lines',
        'sp_orders',
        'sp_order_items',
        'sp_order_item_components',
        'sp_order_status_events',
        'sp_item_movements',
        'sp_stock_adjustments',
        'sp_stock_warnings',
        'sp_settings',
        'sp_webhook_events',
        'sp_asset_snapshots',
        'sp_audit_log',
        'personal_access_tokens'
    ]

    pattern = re.compile(r'INSERT INTO \`([^\`]+)\` \(([^\)]+)\) VALUES\s*(.*?);', re.DOTALL)
    parsed_data = {}

    for tbl, cols_str, vals_str in pattern.findall(content):
        if tbl in ignore_tables:
            continue
        cols = [c.strip().strip('`').strip('"') for c in cols_str.split(',')]
        rows = parse_tuple_values(vals_str)
        if tbl not in parsed_data:
            parsed_data[tbl] = {'cols': cols, 'rows': []}
        parsed_data[tbl]['rows'].extend(rows)

    print("\n--- Summary of Source Data in hidehave_spdb.sql ---")
    total_records = 0
    for tbl in table_order:
        cnt = len(parsed_data[tbl]['rows']) if tbl in parsed_data else 0
        total_records += cnt
        print(f"  {tbl:<28}: {cnt:>5} rows")
    print(f"Total business records to migrate: {total_records}")

    out_file = os.path.join(os.path.dirname(__file__), '..', 'scratch', 'supabase_import.sql')
    os.makedirs(os.path.dirname(out_file), exist_ok=True)

    print(f"\nGenerating PostgreSQL import script: {out_file}...")
    with open(out_file, 'w', encoding='utf-8') as out:
        out.write("-- StockPilot Zero-Loss Migration to Supabase PostgreSQL\n")
        out.write("BEGIN;\n\n")
        out.write("SET session_replication_role = 'replica';\n\n")

        for tbl in table_order:
            if tbl not in parsed_data or not parsed_data[tbl]['rows']:
                continue
            cols = parsed_data[tbl]['cols']
            rows = parsed_data[tbl]['rows']
            
            # Map column names and check types
            col_types = target_cols.get(tbl, {})
            quoted_cols = [f'"{c}"' for c in cols]
            cols_clause = ", ".join(quoted_cols)

            out.write(f"-- Table: {tbl} ({len(rows)} rows)\n")
            
            # Chunk inserts by 200 rows
            chunk_size = 200
            for start_idx in range(0, len(rows), chunk_size):
                chunk = rows[start_idx:start_idx + chunk_size]
                out.write(f'INSERT INTO "{tbl}" ({cols_clause}) VALUES\n')
                
                row_lines = []
                for r in chunk:
                    formatted_vals = []
                    for val, col_name in zip(r, cols):
                        dtype = col_types.get(col_name, 'text')
                        formatted_vals.append(format_pg_literal(val, dtype))
                    row_lines.append(f"  ({', '.join(formatted_vals)})")
                
                out.write(",\n".join(row_lines))
                out.write(";\n\n")

        out.write("SET session_replication_role = 'origin';\n\n")
        out.write("-- Sequence resynchronization\n")
        for tbl in table_order:
            if tbl in target_cols and 'id' in target_cols[tbl]:
                out.write(f"SELECT setval(pg_get_serial_sequence('\"{tbl}\"', 'id'), COALESCE((SELECT MAX(id) FROM \"{tbl}\"), 1), (SELECT MAX(id) FROM \"{tbl}\") IS NOT NULL);\n")

        out.write("\nCOMMIT;\n")

    print("SQL generation complete.")
    file_size_mb = os.path.getsize(out_file) / (1024 * 1024)
    print(f"Generated SQL file size: {file_size_mb:.2f} MB")

if __name__ == '__main__':
    main()
