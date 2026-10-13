<?php
declare(strict_types=1);

// Run state shared between the API process and the worker, kept in a JSON file guarded by a lock.
final class RunState
{
    // A run whose worker has not written for this long is considered dead.
    private const STALE_AFTER = 60;

    private string $file;
    private string $lockFile;

    public function __construct(string $dir)
    {
        $this->file = $dir . '/state.json';
        $this->lockFile = $dir . '/state.lock';
    }

    public function read(): ?array
    {
        return $this->locked(false, function (): ?array {
            $state = $this->load();
            // Surface a crashed worker instead of reporting "running" forever.
            if ($state !== null && $this->isActive($state, false) && time() - $state['updated_at'] > self::STALE_AFTER) {
                $state['status'] = 'failed';
                $state['error'] = 'Worker stopped responding';
            }
            return $state;
        });
    }

    /** Creates a new run unless one is active. Returns null when a run is already in progress. */
    public function tryStart(string $runId, int $target): ?array
    {
        return $this->locked(true, function () use ($runId, $target): ?array {
            $current = $this->load();
            if ($current !== null && $this->isActive($current, true)) {
                return null;
            }
            $now = time();
            $state = [
                'id' => $runId,
                'status' => 'starting',
                'phase' => 'queued',
                'target' => $target,
                'requests' => 0,
                'valid' => 0,
                'discarded' => 0,
                'leader_node' => null,
                'leader_count' => 0,
                'error' => null,
                'server_csv_match' => null,
                'started_at' => $now,
                'finished_at' => null,
                'updated_at' => $now,
            ];
            $this->save($state);
            return $state;
        });
    }

    /** Merges a patch into the run; ignored if another run has taken over. */
    public function update(string $runId, array $patch): void
    {
        $this->locked(true, function () use ($runId, $patch): void {
            $state = $this->load();
            if ($state === null || $state['id'] !== $runId) {
                return;
            }
            $this->save(array_replace($state, $patch, ['updated_at' => time()]));
        });
    }

    private function isActive(array $state, bool $checkHeartbeat): bool
    {
        $active = in_array($state['status'], ['starting', 'running'], true);
        return $active && (!$checkHeartbeat || time() - $state['updated_at'] <= self::STALE_AFTER);
    }

    private function load(): ?array
    {
        if (!is_file($this->file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->file), true);
        return is_array($data) ? $data : null;
    }

    private function save(array $state): void
    {
        file_put_contents($this->file, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function locked(bool $exclusive, callable $fn): mixed
    {
        $handle = fopen($this->lockFile, 'c');
        flock($handle, $exclusive ? LOCK_EX : LOCK_SH);
        try {
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
