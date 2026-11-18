<?php

namespace App\Console\Commands;

use App\Exceptions\Delivery\DeliveryException;
use App\Services\Delivery\CourierAssignmentService;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase 6 – watch race conditions happen (and get prevented).
 *
 *   php artisan delivery:race-demo naive        # check-then-act race, caught by the unique index
 *   php artisan delivery:race-demo for-update   # plain FOR UPDATE: second tx BLOCKS
 *   php artisan delivery:race-demo skip-locked  # FOR UPDATE SKIP LOCKED: second tx takes next courier
 *   php artisan delivery:race-demo stress --processes=30 --couriers=10
 *                                               # real parallel processes (pcntl_fork)
 *
 * The first three scenarios use TWO database connections (= two sessions,
 * like two PHP-FPM workers) from one process and interleave their statements
 * step by step, so the race is deterministic and readable.
 *
 * All demo data lives in an isolated "sandbox" around (lat 10, lng 10), far
 * from the Baku dataset, and is deleted at the end.
 */
class DeliveryRaceDemoCommand extends Command
{
    protected $signature = 'delivery:race-demo
        {scenario : naive|for-update|skip-locked|stress}
        {--processes=20 : stress: number of parallel processes}
        {--couriers=5 : stress: number of available couriers}';

    protected $description = 'Demonstrate concurrent courier assignment races (Task02 phase 6)';

    private const LNG = 10.0;

    private const LAT = 10.0;

    private Connection $a;

    private Connection $b;

    public function handle(): int
    {
        config(['database.connections.race_a' => config('database.connections.pgsql')]);
        config(['database.connections.race_b' => config('database.connections.pgsql')]);
        $this->a = DB::connection('race_a');
        $this->b = DB::connection('race_b');

        $this->cleanup();

        try {
            match ($this->argument('scenario')) {
                'naive'       => $this->naive(),
                'for-update'  => $this->forUpdate(),
                'skip-locked' => $this->skipLocked(),
                'stress'      => $this->stress((int) $this->option('processes'), (int) $this->option('couriers')),
                default       => $this->error('Unknown scenario'),
            };
        } finally {
            foreach ([$this->a, $this->b] as $conn) {
                while ($conn->transactionLevel() > 0) {
                    $conn->rollBack();
                }
            }
            $this->cleanup();
        }

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------
    // Scenario 1: no locks. Both sessions read "courier X is available".
    // ---------------------------------------------------------------------
    private function naive(): void
    {
        [$o1, $o2] = $this->setupSandbox(2, [0.001, 0.002]);
        $nearestSql = "SELECT c.id FROM couriers c JOIN delivery_orders o ON o.id = ?
                        WHERE c.status = 'available' AND ST_DWithin(c.current_location, o.pickup_location, 5000)
                        ORDER BY ST_Distance(c.current_location, o.pickup_location) LIMIT 1";

        $this->a->beginTransaction();
        $this->b->beginTransaction();

        $x = $this->a->selectOne($nearestSql, [$o1])->id;
        $this->step('A', "SELECT nearest available (no lock)          -> courier {$x}");
        $y = $this->b->selectOne($nearestSql, [$o2])->id;
        $this->step('B', "SELECT nearest available (no lock)          -> courier {$y}  <-- same courier!");

        $this->assign($this->a, $o1, $x);
        $this->step('A', "UPDATE courier busy + order {$o1} assigned; COMMIT");
        $this->a->commit();

        try {
            $this->assign($this->b, $o2, $y);
            $this->b->commit();
            $this->error('B committed: courier double-assigned (this would happen WITHOUT the partial unique index)');
        } catch (QueryException $e) {
            $this->b->rollBack();
            $this->step('B', "UPDATE order {$o2} -> courier {$y}  FAILED: SQLSTATE {$e->getCode()}");
            $this->warn('   '.strtok($e->getPrevious()?->getMessage() ?? $e->getMessage(), "\n"));
            $this->info('The partial UNIQUE index (one active order per courier) saved us.');
            $this->line('But B got an error instead of a courier: correct, yet bad UX. Locks fix that.');
        }
    }

    // ---------------------------------------------------------------------
    // Scenario 2: FOR UPDATE without SKIP LOCKED -> B waits for A.
    // ---------------------------------------------------------------------
    private function forUpdate(): void
    {
        [$o1, $o2] = $this->setupSandbox(2, [0.001, 0.002]);
        $sql = "SELECT c.id FROM couriers c JOIN delivery_orders o ON o.id = ?
                 WHERE c.status = 'available' AND ST_DWithin(c.current_location, o.pickup_location, 5000)
                 ORDER BY ST_Distance(c.current_location, o.pickup_location) LIMIT 1
                   FOR UPDATE OF c";

        $this->a->beginTransaction();
        $x = $this->a->selectOne($sql, [$o1])->id;
        $this->step('A', "SELECT ... FOR UPDATE                       -> locks courier {$x}");

        $this->b->beginTransaction();
        // A real second request would simply hang here until A commits.
        // lock_timeout turns the wait into an error so the demo can continue.
        $this->b->statement("SET LOCAL lock_timeout = '2s'");
        $this->step('B', 'SELECT ... FOR UPDATE                       -> waiting for A\'s row lock ...');
        $t = microtime(true);
        try {
            $this->b->selectOne($sql, [$o2]);
        } catch (QueryException $e) {
            $this->step('B', sprintf('gave up after %.1fs: %s', microtime(true) - $t, $e->getCode() === '55P03' ? 'lock_not_available (55P03)' : $e->getCode()));
        }
        $this->b->rollBack();
        $this->a->rollBack();

        $this->line('');
        $this->line('Without lock_timeout B would wait until A commits; then PostgreSQL re-checks B\'s WHERE');
        $this->line('on the new row version (status = busy), skips it and locks the next candidate.');
        $this->line('Correct, but every request that wants the same nearest courier queues behind the');
        $this->line('previous one: latency grows with load and long transactions block everyone.');
    }

    // ---------------------------------------------------------------------
    // Scenario 3: the production pattern.
    // ---------------------------------------------------------------------
    private function skipLocked(): void
    {
        [$o1, $o2] = $this->setupSandbox(2, [0.001, 0.002]);
        $sql = "SELECT c.id FROM couriers c JOIN delivery_orders o ON o.id = ?
                 WHERE c.status = 'available' AND ST_DWithin(c.current_location, o.pickup_location, 5000)
                 ORDER BY ST_Distance(c.current_location, o.pickup_location) LIMIT 1
                   FOR UPDATE OF c SKIP LOCKED";

        $this->a->beginTransaction();
        $this->b->beginTransaction();

        $x = $this->a->selectOne($sql, [$o1])->id;
        $this->step('A', "SELECT ... FOR UPDATE SKIP LOCKED           -> locks courier {$x}");
        $y = $this->b->selectOne($sql, [$o2])->id;
        $this->step('B', "SELECT ... FOR UPDATE SKIP LOCKED           -> skips {$x}, locks courier {$y}");

        $this->assign($this->a, $o1, $x);
        $this->assign($this->b, $o2, $y);
        $this->a->commit();
        $this->b->commit();
        $this->step('A', "order {$o1} -> courier {$x}; COMMIT");
        $this->step('B', "order {$o2} -> courier {$y}; COMMIT");
        $this->info('No waiting, no errors, no double assignment.');
    }

    // ---------------------------------------------------------------------
    // Scenario 4: N real OS processes call the real service at the same time.
    // ---------------------------------------------------------------------
    private function stress(int $processes, int $couriers): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->error('pcntl extension required (run inside the Docker app container).');

            return;
        }

        $orderIds = $this->setupSandbox($processes, array_map(fn ($i) => 0.0005 * $i, range(1, $couriers)));
        $this->info("{$processes} processes will assign {$processes} orders to {$couriers} couriers simultaneously ...");

        DB::disconnect(); // never share a socket across fork()
        $this->a->disconnect();
        $this->b->disconnect();

        $pids = [];
        foreach ($orderIds as $orderId) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // Child: fresh connection, call the REAL service.
                DB::reconnect();
                try {
                    app(CourierAssignmentService::class)->assignNearest($orderId);
                    $code = 0;
                } catch (DeliveryException) {
                    $code = 1; // expected: no courier left
                } catch (Throwable) {
                    $code = 2; // unexpected
                }
                DB::disconnect();
                exit($code);
            }
            $pids[] = $pid;
        }

        $results = [0 => 0, 1 => 0, 2 => 0];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $results[pcntl_wexitstatus($status)] = ($results[pcntl_wexitstatus($status)] ?? 0) + 1;
        }

        DB::reconnect();
        $check = DB::selectOne(
            "SELECT count(*) AS assigned, count(DISTINCT courier_id) AS distinct_couriers
               FROM delivery_orders WHERE id = ANY(?::bigint[]) AND status = 'assigned'",
            ['{'.implode(',', $orderIds).'}'],
        );

        $this->table(['assigned OK', 'no courier (409)', 'unexpected errors', 'assigned orders', 'distinct couriers'], [[
            $results[0], $results[1], $results[2], $check->assigned, $check->distinct_couriers,
        ]]);

        $check->assigned === $check->distinct_couriers && $results[2] === 0
            ? $this->info('OK: every courier got at most one order.')
            : $this->error('RACE DETECTED: a courier got more than one order!');
    }

    // ---------------------------------------------------------------------

    /**
     * @param  list<float>  $courierOffsets  degrees east of the sandbox centre
     * @return list<int> created order ids
     */
    private function setupSandbox(int $orders, array $courierOffsets): array
    {
        $customerId = DB::selectOne("INSERT INTO customers (name, email) VALUES ('Race demo', 'race-demo@example.test') RETURNING id")->id;

        foreach ($courierOffsets as $i => $offset) {
            DB::insert(
                "INSERT INTO couriers (name, status, current_location, last_location_update)
                 VALUES (?, 'available', ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, now())",
                ['Race courier '.($i + 1), self::LNG + $offset, self::LAT],
            );
        }

        $ids = [];
        for ($i = 0; $i < $orders; $i++) {
            $ids[] = DB::selectOne(
                "INSERT INTO delivery_orders (customer_id, status, pickup_location, delivery_location, delivery_fee)
                 VALUES (?, 'pending', ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                         ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 5)
                 RETURNING id",
                [$customerId, self::LNG, self::LAT, self::LNG, self::LAT + 0.01],
            )->id;
        }

        return $ids;
    }

    private function assign(Connection $conn, int $orderId, int $courierId): void
    {
        $conn->update("UPDATE couriers SET status = 'busy' WHERE id = ?", [$courierId]);
        $conn->update("UPDATE delivery_orders SET status = 'assigned', courier_id = ? WHERE id = ?", [$courierId, $orderId]);
    }

    private function cleanup(): void
    {
        DB::delete("DELETE FROM delivery_orders WHERE customer_id IN (SELECT id FROM customers WHERE email = 'race-demo@example.test')");
        DB::delete("DELETE FROM customers WHERE email = 'race-demo@example.test'");
        DB::delete("DELETE FROM couriers WHERE name LIKE 'Race courier %'");
    }

    private function step(string $who, string $text): void
    {
        $this->line(sprintf('  <fg=%s>[%s]</> %s', $who === 'A' ? 'cyan' : 'magenta', $who, $text));
    }
}
