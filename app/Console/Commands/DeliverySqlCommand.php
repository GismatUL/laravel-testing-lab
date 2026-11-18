<?php

namespace App\Console\Commands;

use App\Services\Delivery\Sql\QueryCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Learning tool: run or EXPLAIN the queries from QueryCatalog.
 *
 *   php artisan delivery:sql                                  # list queries
 *   php artisan delivery:sql top_customers                    # run + show rows
 *   php artisan delivery:sql nearest_courier_dwithin --explain
 *   php artisan delivery:sql --group=optimization --explain --markdown > docs/delivery/explain-after.md
 */
class DeliverySqlCommand extends Command
{
    protected $signature = 'delivery:sql
        {query? : Key from QueryCatalog (omit to list all)}
        {--group= : Run every query of a group: advanced|optimization}
        {--e|explain : Show EXPLAIN (ANALYZE, BUFFERS) instead of rows}
        {--markdown : Print as Markdown (for saving into docs/)}';

    protected $description = 'Run / EXPLAIN ANALYZE the Task02 learning queries';

    public function handle(): int
    {
        $keys = $this->selectedKeys();

        if ($keys === null) {
            $this->table(['key', 'group', 'title'], collect(QueryCatalog::all())
                ->map(fn ($q, $key) => [$key, $q['group'], $q['title']])->values());

            return self::SUCCESS;
        }

        foreach ($keys as $key) {
            $query = QueryCatalog::get($key);

            if ($query === null) {
                $this->error("Unknown query '{$key}'. Run without arguments to list keys.");

                return self::FAILURE;
            }

            $this->option('explain') ? $this->explain($key, $query) : $this->run_($key, $query);
        }

        return self::SUCCESS;
    }

    /** @return list<string>|null */
    private function selectedKeys(): ?array
    {
        if ($group = $this->option('group')) {
            return array_keys(array_filter(QueryCatalog::all(), fn ($q) => $q['group'] === $group));
        }

        return $this->argument('query') ? [$this->argument('query')] : null;
    }

    /** @param array{title: string, sql: string} $query */
    private function run_(string $key, array $query): void
    {
        $rows = array_map(fn ($r) => (array) $r, DB::select($query['sql']));

        $this->header($key, $query);
        if ($rows === []) {
            $this->line('(no rows)');

            return;
        }
        $this->table(array_keys($rows[0]), $rows);
    }

    /** @param array{title: string, sql: string} $query */
    private function explain(string $key, array $query): void
    {
        // ANALYZE actually EXECUTES the query (careful with INSERT/UPDATE/DELETE:
        // wrap those in BEGIN ... ROLLBACK). BUFFERS shows how many 8 KB pages
        // came from shared memory (hit) vs. the OS / disk (read).
        $plan = collect(DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$query['sql']))
            ->map(fn ($r) => $r->{'QUERY PLAN'})
            ->implode("\n");

        $this->header($key, $query);
        $this->output->writeln($this->option('markdown') ? "```\n{$plan}\n```\n" : $plan);
    }

    /** @param array{title: string, sql: string} $query */
    private function header(string $key, array $query): void
    {
        if ($this->option('markdown')) {
            $this->output->writeln("### `{$key}` - {$query['title']}\n\n```sql\n{$query['sql']}\n```\n");

            return;
        }

        $this->newLine();
        $this->info("== {$key}: {$query['title']}");
        $this->line($query['sql']);
        $this->newLine();
    }
}
