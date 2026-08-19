<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Preset;

use Cycle\Database\DatabaseProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves routing preset: explicit lead preset_id or the country_default preset.
 */
final readonly class DefaultPresetResolver
{
    public function __construct(
        private DatabaseProviderInterface $db,
        private LoggerInterface $logger,
    ) {}

    public function resolve(?int $presetId, ?string $countryCode): ?ResolvedPreset
    {
        if ($presetId !== null) {
            return $this->resolveById($presetId);
        }

        return $this->resolveByCountry($countryCode);
    }

    private function resolveByCountry(?string $countryCode): ?ResolvedPreset
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        if ($countryCode === '') {
            return null;
        }

        $row = $this->db->database()->query(
            'SELECT id, prefer_cpl
             FROM presets
             WHERE country_code = ? AND country_default = true
             LIMIT 1',
            [$countryCode],
        )->fetch();

        if (!$row) {
            $this->logger->warning('No default preset for country', ['country' => $countryCode]);

            return null;
        }

        return $this->mapRow($row);
    }

    private function resolveById(int $presetId): ?ResolvedPreset
    {
        $row = $this->db->database()->query(
            'SELECT id, prefer_cpl FROM presets WHERE id = ?',
            [$presetId],
        )->fetch();

        if (!$row) {
            $this->logger->warning('Preset not found', ['preset_id' => $presetId]);

            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ResolvedPreset
    {
        return new ResolvedPreset(
            id: (int) $row['id'],
            preferCpl: (bool) ($row['prefer_cpl'] ?? true),
        );
    }
}
