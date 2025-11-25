# Transaction Manager Contract

This document defines the behavioral contract between:

- **TransactionManager**
- **TransactionInterface** implementations
- **ExecutionPlanBuilder**
- **ErrorClassifierInterface**

It describes what the Transaction Manager guarantees and what it *expects* from its callers.

---

## 1. Scope and Intent

The Transaction Manager (TM):

- works **only with data-modifying SQL** (INSERT/UPDATE/DELETE/MERGE/…),
- executes **one logical transaction** over a single database connection
- may **retry** the same logical transaction multiple times
- is responsible for:
    - starting and finishing DB transactions
    - applying transaction isolation level
    - retrying on transient/connection errors using a backoff strategy
    - detecting and signalling unknown commit outcome

The TM is **not** responsible for:

- any side effects outside the database (external services, message queues, filesystem, etc.)
- building SQL strings
- choosing statement parameters or types
- classifying DB-specific errors (this is delegated to `ErrorClassifierInterface`)

---

## 2. Core Abstractions

### 2.1 TransactionInterface

A `TransactionInterface` describes **one logical DB operation**.

Contract:

- `build(): Query`
    - must be a **pure function**:
        - no external side effects
        - no hidden mutable state
        - for the same inputs it must always return the same `Query`
    - returns a `Query` object containing:
        - `sql` (string)
        - `params` (array)
        - `types` (array)

- `isIdempotent(): bool`
    - describes **idempotency of the *effect* on the database**, not of the method call itself:
        - `true` — executing this `Query` multiple times in a row leads to the same final DB state as executing it once.
        - `false` — repeated execution may change DB state further.

### 2.2 ExecutionPlan & ExecutionPlanBuilder

`ExecutionPlan` represents a **fixed list of queries** to be executed as a single transaction:

- `queries: Query[]`
- `isIdempotent: bool` — logical AND of all underlying transactions’ `isIdempotent()`.

`ExecutionPlanBuilder::build(iterable|TransactionInterface $txs)`:

- is called **exactly once** per `TransactionManager::run()` call.
- must:
    - call `build()` on each `TransactionInterface` once
    - collect all `Query` objects into an ordered list
    - compute `isIdempotent` as a conjunction of all transaction idempotency flags
    - throw if the iterable is empty or contains non-TransactionInterface items

After the plan is built, **no further `build()` calls occur on retries**.

---

## 3. Transaction Manager Guarantees

Given a `Connection`, an `ExecutionPlan`, and `TxOptions`, the Transaction Manager guarantees:

1. **Single DB transaction per attempt**

    - For each attempt it:
        - begins a DB transaction
        - sets the requested transaction isolation level
        - executes all queries in order using `executeStatement()`
        - calls `commit()` on success

2. **Atomicity per attempt**

    - If any query or `commit()` throws:
        - TM calls `rollBack()` (best effort)
        - the database must not observe a *partial* commit from that attempt (subject to the DB’s own guarantees)

3. **Retry semantics**

    - TM may re-run the **same `ExecutionPlan`** multiple times when:
        - `ErrorClassifierInterface` marks an error as non-fatal
        - `TxOptions.retryPolicy` is configured and the retry limit has not been exhausted
    - On each retry:
        - a fresh physical DB transaction is started
        - the same queries are executed in the same order
        - isolation level is reapplied.
            
4. **Connection Recovery ("Gone Away" handling)**
    
    - On the very first attempt of `run()`, TM performs a proactive connection check.
    - If starting the transaction fails immediately (e.g., "MySQL server has gone away"), TM will:
        - close the connection to reset its state
        - attempt to start the transaction again **without** consuming a retry attempt from `RetryPolicy`.
        - This ensures stability in long-running processes (workers) without wasting retry budget.

5. **Commit-unknown outcome handling**

   If an error is thrown during `commit()`:

    - and the `ExecutionPlan` is **non-idempotent** (`isIdempotent === false`)
      TM throws `UnknownCommitStateException`
      The caller **must** treat the DB state as undefined and resolve it manually
    - and the `ExecutionPlan` is **idempotent** (`isIdempotent === true`)
      TM is allowed to retry according to `ErrorClassifierInterface` and `TxOptions`

6. **Error handling and classification**

    - TM delegates DB-specific error classification to `ErrorClassifierInterface::classify(Throwable): ErrorType`
    - The TM behavior based on `ErrorType`:
        - `Fatal`:
            - error is rethrown; no further retries
        - `Connection`:
            - the DB connection is closed
            - TM may retry if retries are still available (checked against `RetryPolicy`)
        - other non-fatal types:
            - TM may retry according to `RetryPolicy`

7. **Backoff and sleeping**

    - Before each retry TM calls:
        - `Duration delay = retryPolicy->backoffStrategy->delay($attempt);`
        - `sleeper->sleep($delay);`
    - Any errors from backoff or sleeper are treated as regular errors and classified via `ErrorClassifierInterface`.

---

## 4. Responsibilities of Transaction Authors

Authors of `TransactionInterface` implementations and higher-level code **must** follow these rules:

1. **No side effects outside the database**

    - Transactions must not:
        - call external HTTP APIs
        - publish messages
        - write to files
        - mutate global state or objects with a shared mutable state
        - perform any action that cannot be safely retried together with the DB changes
    - All such side effects must be moved:
        - either outside of TM
        - or into separate, explicitly coordinated workflows

2. **Pure `build()` method**

    - `build()` must:
        - not depend on temporary mutable state
        - not have side effects
        - not change any external object state
    - Its only responsibility is to construct a `Query` object describing *what* should be executed

3. **Correct idempotency declaration**

    - `isIdempotent()` must honestly reflect the effect on the DB:
        - `true` — safe to re-execute the query if the previous attempt might have partially succeeded
        - `false` — repeating the query may corrupt or further mutate the state
    - Incorrectly declaring non-idempotent transactions as idempotent is **undefined behavior** and may lead to data corruption on retries

4. **Single-connection usage**

    - The `Connection` passed to TM is assumed to be used **exclusively** (logically) by the TM during `run()`
    - Other parts of the system:
        - must not start/commit/rollback their own transactions on the same connection concurrently
        - must be prepared that TM may call `close()` on the connection in case of connection-level errors

5. **No nesting on the same connection**

    - TM is not designed to run **inside** an already active transaction on the same connection
    - Calling `run()` while a transaction is already open on the connection is **undefined behavior**, regardless of DBAL savepoint settings

---

## 5. ErrorClassifier Contract

An `ErrorClassifierInterface` implementation (e.g. `MysqlErrorClassifier`, `PgErrorClassifier`) must:

1. Understand driver-specific exception types and SQLSTATE codes
2. Classify at minimum:
    - deadlocks and serialization failures as **retryable** (non-fatal)
    - connection drops / network issues as **`ErrorType::Connection`**
    - syntax errors, constraint violations, and logical errors as **`ErrorType::Fatal`**
3. Treat **unknown exception types** (non-DBAL exceptions, logic errors in user code, etc.) as **`Fatal`** by default

Incorrect classification may cause:

- unnecessary retries
- missed retries
- or incorrect `UnknownCommitState` handling

---

## 6. Isolation and Connection Lifetime

1. **Transaction Isolation**

    - TM calls `setTransactionIsolation()` on the provided `Connection` before executing any statements in a transaction
    - Depending on the driver/DBAL, this may:
        - affect only the current transaction, or change the session-level isolation
    - Users must assume that TM may temporarily change the isolation level of the underlying connection for the duration of `run()`

2. **Closing the Connection**

    - On `ErrorType::Connection`, TM calls `$connection->close()` and relies on DBAL to reconnect lazily on the next attempt
    - Any code sharing the same `Connection` instance must be prepared for this behavior

---

## 7. RunResult Semantics

`RunResult` currently represents:

- the total number of rows affected by **all queries** in the **last successful attempt** of the transaction

It does **not** include any effects from failed attempts, which are always rolled back

---

## 8. Summary

By following this contract:

- Transaction authors can rely on the Transaction Manager to:
    - handle retries
    - deal with transient and connection errors
    - provide clear semantics for an unknown commit outcome
- The Transaction Manager can remain:
    - DB-agnostic
    - simple
    - and safe to use in high-load environments

Any violations of the above rules (side effects in transactions, incorrect idempotency, nested transactions on the same connection) lead to **undefined behavior** and are explicitly outside the responsibility of the Transaction Manager.
