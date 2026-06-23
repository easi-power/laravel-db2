# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`easi-power/laravel-db2` is a Composer package that adds DB2 connectivity to Laravel by extending Illuminate's database component. It is a library, not an application: there is no app to run, no test suite, no linter, and no CI config in the repo. Consumers install it via Composer and Laravel auto-discovers `Easi\DB2\DB2ServiceProvider`.

Requires PHP `^8.4` and `illuminate/database ^13.0` (Laravel 13). Autoload: `Easi\DB2\` → `src/`.

## Commands

- `composer install` / `composer update` — install dependencies (the only routine command).

Note: `composer.lock` is committed but `composer.json` and the lock are sometimes out of sync during dependency bumps — check git status before assuming the lock is current.

## How a connection is wired up (the big picture)

The flow from config to a usable connection spans several files; understanding this chain is the key to the codebase:

1. **`DB2ServiceProvider::register()`** merges `config/db2.php` connections into Laravel's `database.connections`, then for every connection whose `driver` is one of `db2_ibmi_odbc`, `db2_ibmi_ibm`, `db2_zos_odbc`, `db2_expressc_odbc`, it calls `$this->app['db']->extend()` to register a resolver.
2. The resolver picks a **Connector** by driver and returns a **`DB2Connection`**:
   - `db2_ibmi_odbc` / `db2_expressc_odbc` → `ODBCConnector`
   - `db2_zos_odbc` → `ODBCZOSConnector`
   - `db2_ibmi_ibm` → `IBMConnector`
3. **Connectors** (`src/Database/Connectors/`) build the PDO DSN and open the PDO handle. All extend `DB2Connector` (which extends Illuminate's `Connector`). `ODBCConnector::getDsn()` assembles an `odbc:` DSN and appends every entry from the config's `odbc_keywords` array as `KEY=value` pairs — this is how IBM i ODBC tuning flags reach the driver. After connecting, the connector issues `SET SCHEMA`.
4. **`DB2Connection`** (extends Illuminate `Connection`) is the hub. It selects the grammar/processor trio based on `config['driver']` and wires DB2-specific behavior.

When adding support for a new driver variant, you must touch all of: the driver-name allowlist in `DB2ServiceProvider`, the connector switch, and the grammar/processor selection in `DB2Connection`.

## DB2-specific behavior worth knowing

- **Grammar/processor selection** (`DB2Connection`): query grammar is always `Query\Grammars\DB2Grammar`; schema grammar is `Schema\Grammars\DB2ExpressCGrammar` for Express-C else `Schema\Grammars\DB2Grammar`; post-processor is `DB2ZOSProcessor` for zOS else `DB2Processor`.
- **Encoding round-trip**: when `from_encoding` is set, bind values are `iconv`-converted UTF-8 → target encoding in `DB2Connection::bindValues()`, and results are converted back target → UTF-8 in `DB2Processor::processSelect()` (which also `trim()`s all string columns). Error messages get the same treatment in `TranslatedQueryException`.
- **`offset_compatibility_mode`** (`Query\Grammars\DB2Grammar`, default `true`): legacy DB2 has no `LIMIT/OFFSET`. In compat mode, `LIMIT` becomes `FETCH FIRST n ROWS ONLY` and any `OFFSET` triggers a full ANSI rewrite — the query is wrapped in a `row_number() over (...)` CTE and filtered by `row_num`. Disabling the flag falls back to Illuminate's standard offset compilation. Touching offset/limit/select compilation means reasoning about both modes.
- **CTE support**: `DB2ServiceProvider::boot()` registers a `withExpression(name, subquery)` macro on the query Builder; `DB2Grammar::compileSelect()` prepends a `WITH ... AS (...)` clause when expressions are present.
- **`insertGetId`** (`DB2Processor`): wraps the insert as `select <seq> from new table (<insert>)`, DB2's syntax for returning generated keys.
- **Upsert** (`DB2Grammar::compileUpsert`): compiled as a `MERGE INTO ... USING (VALUES ...)` statement, with values cast (`cast(? as INT)` / `cast(? as CLOB)`).
- **`DB2Connection` extras**: `setCurrentSchema()` / `resetCurrentSchema()` issue `SET SCHEMA`; `executeCommand()` runs IBM i CL commands via `CALL QSYS2.QCMDEXC(?)`.
- **Schema introspection** (`Schema\Builder`): `hasTable`/`getColumnListing` accept `schema.table` and split it, falling back to the connection's default schema. The schema grammar adds DB2 column modifiers like `ForColumn`, `Generated`, `StartWith`, `ImplicitlyHidden`.

## Queue driver

`src/Queue/` provides a DB2-backed queue. `DB2ServiceProvider::register()` extends Laravel's `QueueManager` with a `db2_odbc` connector (`Queue\DB2Connector` → `Queue\DB2Queue`). Activate by setting a queue connection's driver to `db2_odbc` in `config/queue.php`.

## Configuration reference

`src/config/db2.php` is the publishable config (`php artisan vendor:publish`). The README documents every connection key — driver names, `driverName` (the ODBC driver string), `schema`, `date_format`, `from_encoding`, the large `odbc_keywords` map, and PDO `options`. Consult the README before changing config-shape assumptions.
