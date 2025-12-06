<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

/**
 * Defines driver-specific classification rules for SQL/database errors.
 *
 * This interface is used by {@see GenericErrorClassifier} to determine
 * whether a particular exception should be interpreted as:
 *
 *  - A **connection-level failure** (ErrorType::Connection),
 *  - a **transient / retryable failure** (ErrorType::Transient),
 *  - or a **fatal, non-retryable failure** (handled as a fallback).
 *
 * Implementations of this interface encapsulate all database-driver-specific
 * knowledge (SQLSTATE mappings, vendor error codes, message patterns, etc.)
 * while keeping the core classification algorithm driver-agnostic.
 *
 * Notes for implementors:
 *  - `isConnectionIssue()` MUST return `true` only for errors where the current
 *    connection is already broken, unusable, or guaranteed to be reset by the
 *    server. These errors have the **highest priority**, because retrying on
 *    a dead connection is pointless.
 *
 *  - `isTransientIssue()` MUST return `true` only for errors that are *safe*
 *    to retry within the same logical transaction, e.g. serialization failures,
 *    deadlocks, temporary resource exhaustion, lock timeouts, etc.
 *
 *  - If both methods return `false`, the error is considered **fatal**.
 *
 *  - Implementations SHOULD be deterministic and MUST NOT throw exceptions.
 */
interface DatabaseErrorHeuristicsInterface
{
    /**
     * Determines whether the error should be classified as a **connection issue**.
     *
     * A connection issue indicates that the database connection is no longer valid:
     *   - server-side disconnects,
     *   - TCP failures,
     *   - protocol violations,
     *   - SQLSTATE classes that imply connection loss (e.g. "08..." in SQL standard),
     *   - vendor-specific driver error codes indicating dropped/broken connection,
     *   - message patterns signaling that the transaction cannot continue
     *     because the session is unusable.
     *
     * When this method returns `true`, the classifier will treat the error as
     * `ErrorType::Connection` and abort further retry attempts.
     *
     * @param string|null $sqlState
     *        Extracted SQLSTATE code (e.g. "08006", "40001"), or null if not available.
     *
     * @param int|null $driverCode
     *        Vendor-specific error code (e.g. MySQL numeric codes), or null.
     *
     * @param string $message
     *        Exception message extracted from the Throwable.
     *
     * @return bool
     *         `true` if the error represents a broken or unusable connection.
     */
    public function isConnectionIssue(?string $sqlState, ?int $driverCode, string $message): bool;

    /**
     * Determines whether the error should be classified as a **transient issue**
     * (i.e., retryable within the same logical transaction).
     *
     * Transient issues are typically temporary and caused by:
     *   - serialization failures,
     *   - deadlocks,
     *   - lock timeouts,
     *   - concurrent modification conflicts,
     *   - resource limitations that can succeed upon retry,
     *   - SQLSTATE codes indicating retryable conditions (e.g. "40001", "40P01").
     *
     * Returning `true` from this method instructs {@see GenericErrorClassifier}
     * to classify the error as `ErrorType::Transient`, allowing the transaction
     * manager to retry, according to its retry/backoff strategy.
     *
     * @param string|null $sqlState
     *        Extracted SQLSTATE code, if available.
     *
     * @param int|null $driverCode
     *        Vendor-specific numeric code extracted from the error, if any.
     *
     * @param string $message
     *        Exception message extracted from the Throwable.
     *
     * @return bool
     *         `true` if the error is considered temporary and safe to retry.
     */
    public function isTransientIssue(?string $sqlState, ?int $driverCode, string $message): bool;
}
