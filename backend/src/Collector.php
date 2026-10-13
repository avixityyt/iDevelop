<?php
declare(strict_types=1);

// One collection run: begin, gather readings until a single node has $target valid ones, end, build the CSV.
final class Collector
{
    private const MAX_CONSECUTIVE_FAILURES = 5;

    private array $rows = [];     // accepted CSV rows, in request order
    private array $counts = [];   // node => number of valid readings
    private array $last = [];     // "node|type" => rounded °C of the previous reading
    private int $requests = 0;
    private int $discarded = 0;

    public function __construct(
        private WashPalClient $client,
        private RunState $state,
        private string $runId,
        private int $target,
        private int $maxRequests,
        private int $delayMs,
    ) {
    }

    public function run(): void
    {
        $this->progress(['status' => 'running', 'phase' => 'starting']);
        $error = null;
        $began = false;

        try {
            $this->expectOk($this->client->command('command=begin'), 'begin');
            $began = true;
            $this->progress(['phase' => 'collecting']);
            $this->collect();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        // Once begun, always send end so the shared service is not left with an open task.
        if ($began) {
            try {
                $this->progress(['phase' => 'ending']);
                $this->expectOk($this->client->command('command=end'), 'end');
            } catch (Throwable $e) {
                $error ??= $e->getMessage();
            }
        }

        if ($error !== null) {
            $this->progress(['status' => 'failed', 'phase' => 'finished', 'error' => $error, 'finished_at' => time()]);
            return;
        }

        $csv = CsvBuilder::build($this->rows);
        file_put_contents(RESULT_CSV, $csv);
        $this->progress([
            'status' => 'done',
            'phase' => 'finished',
            'server_csv_match' => $this->compareWithServer($csv),
            'finished_at' => time(),
        ]);
    }

    private function collect(): void
    {
        $failures = 0;

        while (true) {
            if ($this->requests >= $this->maxRequests) {
                throw new RuntimeException("Gave up after {$this->maxRequests} requests without {$this->target} readings for one node");
            }

            // Captured before the request: this exact value is what goes into the CSV.
            $time = gmdate('H:i:s');
            [$status, $body] = $this->client->command("command=getvalue,request=$time");
            $this->requests++;

            if ($status !== 200) {
                if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    throw new RuntimeException("Wash Pal keeps failing (last HTTP $status)");
                }
                usleep($failures * 1_000_000); // back off while the service is struggling
                $this->publish();
                continue;
            }
            $failures = 0;

            $reading = $this->parse($body);
            if ($reading === null) {
                $this->discarded++;
            } else {
                $this->accept($time, $reading);
                if ($this->counts[$reading['node']] >= $this->target) {
                    $this->publish();
                    return;
                }
            }

            $this->publish();
            usleep($this->delayMs * 1000);
        }
    }

    /** Returns the reading, or null if it must be discarded (malformed, "unknown" type, temp outside 0..199 °F). */
    private function parse(string $body): ?array
    {
        $data = json_decode($body, true)['data'] ?? null;
        if (
            !is_array($data)
            || !isset($data['node'], $data['type'], $data['temp'])
            || !is_numeric($data['node'])
            || !is_string($data['type'])
            || !is_numeric($data['temp'])
        ) {
            return null;
        }
        $temp = (float) $data['temp'];
        if ($data['type'] === 'unknown' || $temp < 0 || $temp >= 200) {
            return null;
        }
        return ['node' => (int) $data['node'], 'type' => $data['type'], 'temp' => $temp];
    }

    private function accept(string $time, array $reading): void
    {
        $celsius = round(($reading['temp'] - 32) * 5 / 9, 2);

        // Delta is taken between the rounded values (matches the server's CSV), per node AND type.
        $key = $reading['node'] . '|' . $reading['type'];
        $delta = isset($this->last[$key]) ? round($celsius - $this->last[$key], 2) : null;
        $this->last[$key] = $celsius;

        $this->rows[] = [
            'time' => $time,
            'node' => $reading['node'],
            'type' => $reading['type'],
            'celsius' => $celsius,
            'delta' => $delta,
        ];
        $this->counts[$reading['node']] = ($this->counts[$reading['node']] ?? 0) + 1;
    }

    private function publish(): void
    {
        arsort($this->counts);
        $leader = array_key_first($this->counts);
        $this->progress([
            'requests' => $this->requests,
            'valid' => count($this->rows),
            'discarded' => $this->discarded,
            'leader_node' => $leader,
            'leader_count' => $leader === null ? 0 : $this->counts[$leader],
        ]);
    }

    /** Self-check against the server's own CSV; null when it could not be fetched. */
    private function compareWithServer(string $csv): ?bool
    {
        try {
            [$status, $body] = $this->client->csv();
        } catch (Throwable) {
            return null;
        }
        if ($status !== 200) {
            return null;
        }
        file_put_contents(SERVER_CSV, $body);
        return $body === $csv;
    }

    private function expectOk(array $response, string $what): void
    {
        if ($response[0] !== 200) {
            throw new RuntimeException("Command '$what' failed (HTTP {$response[0]})");
        }
    }

    private function progress(array $patch): void
    {
        $this->state->update($this->runId, $patch);
    }
}
