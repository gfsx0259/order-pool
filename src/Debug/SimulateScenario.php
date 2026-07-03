<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Debug;

final readonly class SimulateScenario
{
    /**
     * @param list<SimulateOrder> $orders
     */
    public function __construct(
        public string $name,
        public int $presetId,
        public int $leads,
        public array $orders,
        public float $rateExponent = 1.0,
        public string $prefix = 'dbg:',
        public string $output = 'simulate-report.html',
    ) {}

    public static function fromJsonFile(string $path): self
    {
        $path = self::resolveScenarioPath($path);
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException("Cannot read scenario file: {$path}");
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Scenario must be a JSON object');
        }

        $orders = [];
        foreach ($data['orders'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orders[] = new SimulateOrder(
                id: (string) ($row['id'] ?? ''),
                source: (string) ($row['source'] ?? 'irev'),
                partnerId: (string) ($row['partner_id'] ?? ''),
                rate: (int) ($row['rate'] ?? 0),
                capacity: (int) ($row['capacity'] ?? 0),
                label: (string) ($row['label'] ?? $row['partner_name'] ?? ''),
                schedule: (string) ($row['schedule'] ?? ''),
                scheduleTz: (string) ($row['schedule_tz'] ?? '+0000'),
                dailyTzOffset: isset($row['daily_tz_offset']) ? (int) $row['daily_tz_offset'] : 0,
            );
        }

        if ($orders === []) {
            throw new \InvalidArgumentException('Scenario must define at least one order');
        }

        $dir = dirname($path);

        return new self(
            name: (string) ($data['name'] ?? basename($path, '.json')),
            presetId: (int) ($data['preset_id'] ?? 0),
            leads: (int) ($data['leads'] ?? 100),
            orders: $orders,
            rateExponent: (float) ($data['rate_exponent'] ?? 1.0),
            prefix: (string) ($data['prefix'] ?? 'dbg:'),
            output: self::resolveOutputPath((string) ($data['output'] ?? 'simulate-report.html'), $dir),
        );
    }

    private static function resolveScenarioPath(string $path): string
    {
        if (is_file($path)) {
            return $path;
        }

        $name = basename(str_replace('\\', '/', $path));
        $packagePath = __DIR__ . '/scenarios/' . $name;
        if (is_file($packagePath)) {
            return $packagePath;
        }

        throw new \InvalidArgumentException(
            "Scenario file not found: {$path} (also tried {$packagePath})",
        );
    }

    private static function resolveOutputPath(string $output, string $scenarioDir): string
    {
        if ($output === '') {
            $output = 'simulate-report.html';
        }

        if (str_starts_with($output, '/')) {
            return $output;
        }

        $base = getcwd() ?: $scenarioDir;

        return $base . '/' . $output;
    }
}

final readonly class SimulateOrder
{
    public function __construct(
        public string $id,
        public string $source,
        public string $partnerId,
        public int $rate,
        public int $capacity,
        public string $label = '',
        public string $schedule = '',
        public string $scheduleTz = '+0000',
        public int $dailyTzOffset = 0,
    ) {}

    public function displayLabel(): string
    {
        if ($this->label !== '') {
            return $this->label;
        }

        if ($this->source === 'irev') {
            $tail = substr($this->partnerId, -5);

            return 'IREV:' . $tail;
        }

        return 'LM:' . $this->id;
    }
}
