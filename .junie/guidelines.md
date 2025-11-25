### Transaction Manager Core — Development Guidelines

This document captures project-specific knowledge to help advanced contributors work efficiently.
It focuses on the actual setup in this repo: Dockerized multi-PHP testing (8.2/8.3/8.4), PHPUnit 11, and Composer PSR-4 namespaces.

#### Build/Configuration

- PHP/tooling versions
  - Runtime: PHP >= 8.2 (tests verified with 8.2, 8.3, 8.4)
  - Test runner: PHPUnit
  - Local host PHP may be older (e.g., 8.0) — do not run `vendor/bin/phpunit` on host; use Docker containers provided here.

- Docker environment
  - Compose file: `docker/docker-compose.yml`
  - Services: `php-cli-8.2`, `php-cli-8.3`, `php-cli-8.4`
  - Each service mounts the project at `/app` and runs as host user (`${USER}` with UID 1000 by default). Ensure `${USER}` is exported in your shell.
  - Build/start all services from project root:
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml up -d --build
    ```
  - Notes:
    - The services include `xdebug` and `zip` extensions.
    - A directory `/opt/phpstorm-coverage` exists inside images for IDE coverage. Configure your IDE to write there if needed.

- Composer dependencies
  - Install inside any PHP 8.2+ container (use 8.2 for baseline):
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.2 composer install
    ```
  - If you change `composer.json`, re-run `composer install` (or `composer update <pkg>`) inside the container(s). Keep `composer.lock` authoritative for CI consistency.
  - You may see a Composer warning about the lock not matching the manifest if `composer.json` changed — either revert or run an appropriate `composer update` to refresh the lock.

#### Testing

- Configuration
  - Root config: `phpunit.xml` (schema 11.5). It boots `tests/bootstrap.php` which sets timezone (`America/Chicago`), turns on `E_ALL`, and requires Composer autoload.
  - Source under test: `src/` with namespace `AEATech\TransactionManager\` (see `composer.json` autoload).
  - Tests: `tests/` with namespace `AEATech\Test\TransactionManager\` mapped to `tests/AEATech/Test/TransactionManager` via `autoload-dev`.

- Running tests per PHP version
  - PHP 8.2
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.2 vendor/bin/phpunit
    ```
  - PHP 8.3
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.3 vendor/bin/phpunit
    ```
  - PHP 8.4
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.4 vendor/bin/phpunit
    ```

- Run across all configured PHP versions
  ```bash
  for v in 8.2 8.3 8.4; do \
      echo "Testing PHP $v..."; \
      docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-$v vendor/bin/phpunit || break; \
  done
  ```

- Targeted runs and filters
  - Single test file:
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.2 vendor/bin/phpunit tests/AEATech/Test/TransactionManager/RetryPolicyTest.php
    ```
  - Filter by test method or class name:
    ```bash
    docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.2 vendor/bin/phpunit --filter buildWithSingleTransaction
    ```

- Adding new tests
  - Put tests under `tests/AEATech/Test/TransactionManager`.
  - Use namespace `AEATech\Test\TransactionManager` and extend `PHPUnit\Framework\TestCase` (or the provided `TransactionManagerTestCase` if relevant).
  - Example skeleton:
    ```php
    <?php
    declare(strict_types=1);
    
    namespace AEATech\Test\TransactionManager;
    
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\TestCase;
    
    class MyFeatureTest extends TestCase // or TransactionManagerTestCase if relevant
     {
        #[Test]
        public function itWorks(): void
        {
            $this->assertTrue(true);
        }
    }
    ```
  - After adding tests, run inside a container as shown above. The suite and the example pattern have been verified in containers for PHP 8.2/8.3/8.4.

- Notes about test bootstrap and autoloading
  - `tests/bootstrap.php` defines `ROOT_PATH`, sets timezone, requires Composer autoload, and enables full error reporting.
  - Autoload mappings are declared in `composer.json` (`autoload` and `autoload-dev`). If you add new PSR-4 roots, run `composer dump-autoload` inside a container.

#### Test naming conventions

- Test methods MUST use lowerCamelCase and MUST NOT contain underscores. Examples:
  - Good: `createWithInvalidMaxRetries`, `buildWithSingleTransaction`, `buildThrowsForEmptyIterable`.
  - Bad: `create_with_invalid_max_retries`, `build_with_single_transaction`.
- Data providers and helper methods should also follow lowerCamelCase.
- When filtering tests, use the camelCase method names, e.g.:
  ```bash
  docker-compose -p aeatech-transaction-manage-core -f docker/docker-compose.yml exec -T php-cli-8.2 vendor/bin/phpunit --filter buildWithSingleTransaction
  ```

#### PHPUnit тесты — стиль и докблоки

- Calls to PHPUnit assert methods are made via `self::`, not `$this->`:
  ```php
  // Right
  self::assertSame($expected, $actual);

  // Wrong
  $this->assertSame($expected, $actual);
  ```
- For test methods that may potentially throw exceptions (for example, when calling methods that declare @throws in their contract), we add a @throws Throwable docblock to prevent the IDE from highlighting it as an issue.
  ```php
  use Throwable;

  /**
   * @throws Throwable
   */
  public function buildThrowsForEmptyIterable(): void
  {
      // ...
  }
  ```

#### Additional Development Information

- Code style and conventions
  - PHP 8.2+ features are available. Most new files use `declare(strict_types=1);` and typed properties/parameters/returns.
  - Namespaces follow PSR-4 as configured in `composer.json`.
  - Keep parity with existing patterns: immutable value objects (`Duration`, `TxOptions`), interfaces for side effects (`SleeperInterface`, `ConnectionInterface`), and small composable services (e.g., `ExponentialBackoff`).

- Transaction manager domain notes (high level)
  - The core focuses on executing a `Query` (plan) within a transaction with retries, error classification, backoff, and commit uncertainty handling (`UnknownCommitStateException`).
  - Backoff strategy abstraction: see `BackoffStrategyInterface` and `ExponentialBackoff`.
  - Error classification abstraction: `ErrorClassifierInterface` and `ErrorType` enums.
  - Time abstractions: `Duration`, `TimeUnit`, and sleeping via `SleeperInterface`/`SystemSleeper`.

- Debugging in containers
  - `xdebug` is installed; remote configuration is not pre-wired. For ad-hoc debugging, configure environment variables or `xdebug.client_host` at runtime and mount your IDE server name.
  - Use `-T` with `docker-compose exec` in non-TTY contexts (CI, scripted loops) to avoid interactive prompts.

- Common pitfalls
  - Running PHPUnit on the host with PHP < 8.2 will fail due to the toolchain requiring 8.2+. Always use the containers.
  - Ensure `${USER}` is set when building images; the Dockerfiles expect to create a matching user with UID 1000. If your UID differs, you may need to adjust to compose args.
  - The volume mapping in `docker/docker-compose.yml` expects the project folder layout as in this repo. If you relocate, keep the `-f` path and project root consistent.
