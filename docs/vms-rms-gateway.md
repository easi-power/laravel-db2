# VMS RMS gateway protocol

The `vms_rms_gateway` driver lets Eloquent read and write OpenVMS RMS indexed files through a
small COBOL program on the VMS node. Laravel never sees SQL: `GatewayGrammar` compiles each
query to a JSON descriptor (what `->toSql()` and the query log show), `GatewayCodec` turns that
into the frame below, and a transport carries it over TCP.

`Transport\FakeTransport` is the reference implementation of this page, except that it knows no
FDs: it does not validate file or column names. Elsewhere, when this page and the fake
disagree, the fake is right and this page has a bug.

## Connection

```php
'ibmi' => [
    'driver'    => 'vms_rms_gateway',
    'host'      => env('DB2_HOST'),
    'port'      => env('DB2_PORT'),
    'timeout'   => 10,        // seconds, connect and read
    'transport' => 'socket',  // or 'fake' for tests
    'encoding'  => 'ISO-8859-1', // the files' text encoding; omit to pass bytes through
],
```

One TCP connection per connection object; frames go back to back on it until it is lost. Under
Octane a worker keeps it across requests. The gateway runs under the TCP/IP Services auxiliary
server, one VMS process per connection, reading and writing the connection (`SYS$NET`).

With `encoding` set, every string sent is converted from UTF-8 to it and every value read back to
UTF-8. A character the encoding cannot hold is refused with `22021`, before anything is sent.

A lost connection (`Lost the RMS gateway …`, `Cannot reach the RMS gateway …`) reconnects on the
next query. A select is retried once at once; a write only when it was lost while sending,
because one lost while reading may already be done.

## Framing

```
<8 ASCII digits: payload length in bytes><payload>
```

The payload is records separated by RS (`0x1E`); each record is fields separated by US (`0x1F`).
The first field of a record is its tag. No field may contain RS or US — the client refuses to
send one. All values are strings.

## Request

| Tag | Fields | Present for |
|---|---|---|
| `H` | op, file, limit (empty = none), offset, count flag (`1`/`0`) | always, first |
| `C` | column names | select (empty = every field), insert |
| `O` | column, `asc`/`desc`, column, `asc`/`desc`, ... | select, optional |
| `V` | one value per `C` column | insert, one record per row |
| `S` | column, value | update, one record per assignment |
| `W` | group, column, operator, value, value, ... | select, update, delete |

`op` is `select`, `insert`, `update` or `delete`. `file` is the logical file name (`TOFHEAD`);
the gateway adds the company prefix and `.DAT`.

### Conditions (`W`)

A record matches when **every group-0 condition** holds **and each other group has at least
one** condition that holds. That is the only boolean shape the client sends.

| Operator | Values | Meaning |
|---|---|---|
| `=` `!=` `<` `<=` `>` `>=` | 1 | compare in the field's PIC type: numeric fields as numbers |
| `like` `notlike` | 1 | case-insensitive; `%` any run of characters, `_` exactly one |
| `in` `notin` | 0..n | equal to one of / none of the values; an empty `in` matches nothing |

The gateway picks the index itself from the leading equality/range conditions; Laravel never
names one. Conditions it cannot serve from an index are applied to each record read.

### Select

Read in `O` order (key order if none), skip `offset`, return at most `limit` records
(`limit` 0 returns none). With the count flag set, `count` in the response is the number of
**matched** records regardless of limit; without it the gateway may stop reading once it has
`limit` records, and `count` is just the number returned.

There are no aggregates on the wire: MAX/MIN arrive as an ordered read with limit 1, COUNT as
limit 0 with the count flag, `exists()` as limit 1.

## Response

| Tag | Fields |
|---|---|
| `OK` | count, truncated (`1`/`0`), affected |
| `C` | column names |
| `R` | one value per column, one record per row |

or, on failure, a single record:

| Tag | Fields |
|---|---|
| `ER` | SQLSTATE, message |

`truncated` means the gateway stopped at its own scan cap: the rows are a floor, not the truth.
`affected` is the number of records inserted, rewritten or deleted. Fields may keep their
space padding; the client strips trailing spaces.

### Values

- No NULL: an empty field is an empty string. `whereNotNull()` is dropped by the client.
- Numbers use `.` as the decimal point and a leading `-` for negatives, whatever
  `DECIMAL-POINT IS COMMA` says on the COBOL side. Leading zeros are allowed.
- Text is passed through byte for byte.

### SQLSTATE codes

| Code | When |
|---|---|
| `23505` | insert of a key that exists — the app retries on class `23` |
| `02000` | update/delete/select with no match is **not** an error: `OK` with affected/count 0 |
| `42S02` | unknown file |
| `42S22` | unknown column |
| `22001` | text longer than its field (trailing spaces aside), on insert or update; nothing written |
| `22003` | a number over its field's digits, or negative into an unsigned field |
| `22018` | a value that is not a number, for a number field |
| `22021` | client side: text the connection's `encoding` cannot hold; nothing sent |
| `08P01` | a malformed frame (prefix, tags, field counts, an update without an assignment) |
| `0A000` | not served: an order no key of the file can give, a write on a read-only service or file, a prime-key change |
| `40001` | the record stays locked by another process (the gateway's retries ran out, within ms): retry later |
| `54000` | too large: over 2000 values in a request, over 2000 records to update/delete, a response over the gateway's buffer, over 50 conditions or 10 order columns |
| `57014` | the request read for over 8 s and was stopped (never a write half-way); the connection stays usable |
| `HY000` | anything else, with the RMS status in the message |

`FakeTransport` reproduces `23505` only; the others depend on the files' layouts and the
gateway's limits, which the fake does not know.

## Not supported

Joins, subqueries (`whereExists`, `whereHas`), `groupBy`/`having`, unions, raw SQL, `sum`/`avg`,
`increment`, `insertOrIgnore`, `upsert`, `insertGetId`, locks and schema changes all throw
`UnsupportedGatewayQuery` when the query is compiled — before anything is sent.

`transaction()` runs its callback and fires its events but rolls nothing back: RMS has no
transactions. Order writes so a partial failure is safe to retry (children before parents).
