<?php
declare(strict_types=1);

namespace AEATech\TransactionManager;

use PDOException;
use Throwable;

class GenericErrorClassifier implements ErrorClassifierInterface
{
    public function __construct(
        private readonly DatabaseErrorHeuristicsInterface $errorHeuristics
    ) {
    }

    public function classify(Throwable $e): ErrorType
    {
        // Walk the exception chain from the original to the root cause
        $chain = [];
        $cur = $e;

        while ($cur) {
            $chain[] = $cur;
            $cur = $cur->getPrevious();
        }

        // Inspect from the deepest cause first
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $ex = $chain[$i];

            [$sqlState, $driverCode, $message] = $this->extractErrorInfo($ex);

            // 1) Connection-level issues
            if ($this->errorHeuristics->isConnectionIssue($sqlState, $driverCode, $message)) {
                return ErrorType::Connection;
            }

            // 2) Transient (retryable) issues
            if ($this->errorHeuristics->isTransientIssue($sqlState, $driverCode, $message)) {
                return ErrorType::Transient;
            }
        }

        // 3) Fallback: treat as fatal
        return ErrorType::Fatal;
    }

    /**
     * @return array{
     *     0: ?string, // SQLSTATE (if available)
     *     1: ?int,    // driver-specific code (if available)
     *     2: string   // error message
     * }
     */
    private function extractErrorInfo(Throwable $e): array
    {
        // Try to read SQLSTATE and driver code from PDOException if available
        $sqlState = null;
        $driverCode = null;

        // PDOException exposes public $errorInfo = [sqlstate, driverCode, driverMessage]
        if ($e instanceof PDOException) {
            $errorInfo = $e->errorInfo ?? null;

            if (is_array($errorInfo) && isset($errorInfo[0])) {
                $sqlState = is_string($errorInfo[0]) ? $errorInfo[0] : null;
            }

            if (is_array($errorInfo) && isset($errorInfo[1]) && is_numeric($errorInfo[1])) {
                $driverCode = (int)$errorInfo[1];
            }
        }

        // Fall back to Exception code if helpful
        $code = $e->getCode();

        // Only promote a non-zero integer code to driver code. In PHP exceptions, code 0 typically
        // means "no specific code" and should not suppress message-based heuristics that rely on
        // $driverCode being null.
        if ($driverCode === null && is_int($code) && $code !== 0) {
            $driverCode = $code;
        }

        if ($sqlState === null && is_string($code) && strlen($code) >= 5) {
            $sqlState = substr($code, 0, 5);
        }

        // Doctrine DBAL exceptions may provide methods depending on an installed version.
        // Avoid hard dependency: probe reflectively when present.
        if ($sqlState === null && method_exists($e, 'getSQLState')) {
            $state = $e->getSQLState();

            if (is_string($state) && $state !== '') {
                $sqlState = $state;
            }
        }

        return [$sqlState, $driverCode, $e->getMessage()];
    }
}
