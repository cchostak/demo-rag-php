PHP + MySQL RAG Demo (Chat-to-SQL + NLP Product Registration + Prod Assistant)

Quick demo to convert natural language into SQL using OpenAI, run against a MySQL database, and summarize results back to the user. Also supports NLP-driven product registration into a `products` table via safe JSON extraction and parameterized inserts.

Prereqs
- Docker + Docker Compose
- An OpenAI API key with access to `gpt-4o-mini`

Run
1) Set your key in the environment (PowerShell):
   $env:OPENAI_API_KEY="sk-..."

   Or create a `.env` next to docker-compose.yml with:
   Copy `.env.example` to `.env` and update values:
   - `OPENAI_API_KEY=sk-...`
   - Optional: `OPENAI_MODEL=gpt-4o-mini`
   - Optional (prod.php): `MAX_ROWS`, `REQUIRE_WRITE_CONFIRMATION`, `EXPOSED_TABLES_JSON`

2) Build and start:
   docker compose up --build

3) Visit the app:
   http://localhost:9080

   Optional Adminer (DB UI):
   http://localhost:9081
   System: MySQL, Server: mysql, User: root, Password: example, DB: sales_db

Notes
- If you see quota or key errors from OpenAI, you can still demo by pasting SQL directly in the "SQL override" field in the UI. The app supports running manual SQL without an API key.
- Only single-statement SELECT queries are allowed; a default LIMIT 100 is added if missing.
- `seed.sql` initializes the `sales_db` with sample `sales` and `products` tables and rows. The app exposes a second form to register products from natural language (fields: name, sku, price, description, category, stock).
- To rebuild after code changes: docker compose build --no-cache app && docker compose up -d
- `index.php` provides a streamlined, production-leaning assistant with one textbox for both read and write.
- It uses dynamic schema RAG over an allowlist of tables and requires confirmation for writes.
- Configure exposed tables and allowed write columns at the top of `index.php`.
- Access at: http://localhost:9080/index.php

Configuration reference (index.php)
- `OPENAI_MODEL`: override model name (default: `gpt-4o-mini`).
- `MAX_ROWS`: default LIMIT for reads (default: 100).
- `REQUIRE_WRITE_CONFIRMATION`: `1`/`0` to require confirmation (default: 1).
- `EXPOSED_TABLES_JSON`: JSON allowlist for tables and write rules. Example:
  {"sales":{"read":true,"write":{"insert":["item_name","quantity","sold_at"],"update":["quantity","sold_at"],"delete":false}},"products":{"read":true,"write":{"insert":["name","sku","price","description","category","stock"],"update":["price","description","category","stock","name","sku"],"delete":false}}}

Notes for local volumes
- If the MySQL volume existed before `products` was added, `seed.sql` won’t re-run.
- To reset the DB to fresh seed: `docker compose down -v` then `docker compose up --build`.
