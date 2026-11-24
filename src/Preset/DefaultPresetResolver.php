<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Preset;

use Cycle\Database\DatabaseProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves routing preset: explicit lead preset_id or the country_default preset of the vertical, the default vertical when none is given.
 */
final readonly class DefaultPresetResolver
{
    public function __construct(
        private DatabaseProviderInterface $db,
        private LoggerInterface $logger,
    ) {}

    public function resolve(?int $presetId, ?string $countryCode, ?int $verticalId = null): ?ResolvedPreset
    {
        if ($presetId !== null) {
            return $this->resolveById($presetId);
        }

        return $this->resolveByCountry($countryCode, $verticalId);
    }

    private function resolveByCountry(?string $countryCode, ?int $verticalId): ?ResolvedPreset
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        if ($countryCode === '') {
            return null;
        }

        $row = $this->db->database()->query(
            'SELECT id, prefer_cpl
             FROM presets
             WHERE country_code = ? AND country_default = true
               AND vertical_id = COALESCE(CAST(? AS INTEGER), (SELECT id FROM verticals WHERE is_default))
             LIMIT 1',
            [$countryCode, $verticalId],
        )->fetch();

        if (!$row) {
            $this->logger->warning('No default preset for country', ['country' => $countryCode, 'vertical_id' => $verticalId]);

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
