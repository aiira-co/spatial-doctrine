# Spatial PostgreSQL coroutine driver

A Doctrine DBAL driver that talks to PostgreSQL through OpenSwoole's coroutine
client, so a query suspends the coroutine instead of blocking the worker.

## Do not enable this on OpenSwoole 26.2

It is complete and its own tests pass, but the OpenSwoole client underneath it
cannot support a connection pool, so enabling it will crash workers under load.

A connection may only ever be used by the coroutine that used it first. Hand it
to a second coroutine — which is the entire purpose of a pool — and its epoll
registration fails with `ReactorEpoll::add(): failed to add events ... File
exists`, after which the process segfaults. Measured on OpenSwoole 26.2.0 with
plain `OpenSwoole\Coroutine\PostgreSQL` and no Doctrine or pool code in the
path, so this is the client, not this driver:

| Pattern                                   | Result           |
| ----------------------------------------- | ---------------- |
| One connection per coroutine, no handoff  | 8242 q/s, 0 errors |
| Connections shared between coroutines     | segfault (139)   |
| `pdo_pgsql`, for reference                | 2400 q/s         |

The upside is real — roughly 3.4x `pdo_pgsql`, without blocking the worker — so
this is worth revisiting when upstream fixes connection reuse. Until then the
only safe shape would be one connection per coroutine with no pool at all,
which makes the connection count track in-flight requests rather than a budget,
and that is the opposite of what these services need.

Services should stay on `pdo_pgsql`, accept that concurrency is `worker_num`,
and size `poolSize` for what a worker can actually hold at once.

## Why this exists

OpenSwoole publishes no PDO PostgreSQL coroutine hook — there is no
`Runtime::HOOK_PDO_PGSQL`. With a plain `pdo_pgsql` driver every query blocks
its entire worker: coroutines buy no database concurrency, a worker serves one
query at a time, and a connection pool larger than one is decorative. This
driver restores the concurrency the rest of the framework assumes.

## Attribution

Derived from [opsway/doctrine-dbal-swoole-pgsql-driver][opsway] 4.0.0, MIT
licensed — see `LICENSE-opsway`. The original design is preserved: the pooled
connection is bound to the *coroutine context* rather than to the DBAL
connection object, and a `defer()` hook returns it when the coroutine ends, so
connections follow the request without any explicit release.

It was forked rather than depended upon because upstream has had no commit
since June 2024, ships no tests, is pinned to `doctrine/dbal ^3.2`, and — most
importantly — targets an OpenSwoole PostgreSQL API that no longer exists.

## What changed from upstream

Upstream is written against the old resource-based extension API and does not
work on OpenSwoole 26. These were rewritten:

- **`Statement`** — `prepare($name, $sql)` and `$connection->execute($name, ...)`
  are gone. OpenSwoole 26 returns a `PostgreSQLStatement` from `prepare($sql)`
  which executes itself. As a side effect the old per-query `uniqid()` naming
  disappeared, which used to leave an un-deallocated prepared statement on the
  server for every query.
- **`Result`** — the fetch, `affectedRows`, `numRows` and `fieldCount` methods
  live on the statement, not the connection. Rows are addressed by index rather
  than by an advancing cursor, and reading past the end raises a PHP warning, so
  `Result` keeps its own cursor bounded by the row count.
- **`Connection::query()` / `exec()`** — both gated success on `is_resource()`,
  which the object form never satisfies, so every successful query was reported
  as a connection failure.

Bugs fixed while porting:

- **`ConnectionPool::make()` published before registering.** The connection was
  pushed to the channel before its stats were written to the `WeakMap`. A push
  hands the connection straight to a coroutine already blocked in `pop()`, which
  then read the map before the producer wrote to it and failed with "Connection
  stats could not be empty". Only reproducible under contention.
- **`ConnectionPool::make()` could exceed `poolSize`.** Connecting yields, so two
  coroutines could both observe spare capacity and both open a connection. Slots
  are now reserved before connecting.
- **Transaction control never checked its result.** `beginTransaction`, `commit`
  and `rollBack` returned `true` unconditionally, so a rejected `COMMIT` looked
  like success and the caller believed its writes were durable.
- **`useConnectionPool` defaulted to off.** Read with a bare array access, a
  config that merely omitted the key raised a warning and fell through to
  `false`, silently opening an unbounded connection per coroutine.
- **Pool exhaustion was indistinguishable from a broken server.** Saturation now
  raises `PoolExhaustedException`, which carries HTTP 503 and a `Retry-After`,
  rather than a generic `DriverException` that reads as a 500.

## Known limitations

- Only positional `?` placeholders are rewritten to `$1, $2`. Named parameters
  in raw DBAL SQL are not supported.
- Binary and large-object parameters are rejected rather than silently
  corrupted; encode them before binding.
- The extension returns native PHP types (`int`, `bool`) where PDO returns
  strings. Doctrine's type layer handles both, but code comparing raw driver
  output with `===` may notice.

## Testing

`tests/driver-conformance.php` exercises this against a real database, including
transactions, SQLSTATE mapping, concurrency and the exhaustion path. It needs
the openswoole extension, so run it inside a service container.

[opsway]: https://github.com/opsway/doctrine-dbal-swoole-pgsql-driver
