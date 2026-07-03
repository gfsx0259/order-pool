<?php

declare(strict_types=1);

namespace Enthusiast\OrderPool\Preset;

use Cycle\Database\DatabaseProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves routing preset: explicit lead preset_id or default preset for country.
 */
final readonly class DefaultPresetResolver
{
    public function __construct(
        private DatabaseProviderInterface $db,
        private LoggerInterface $logger,
    ) {}

    public function resolve(?int $presetId, ?string $countryCode): ?int
    {
        if ($presetId !== null && $presetId > 0) {
            return $presetId;
        }

        return $this->resolveByCountry($countryCode);
    }

    public function resolveByCountry(?string $countryCode): ?int
    {
        $countryCode = strtoupper(trim((string) $countryCode));
        if ($countryCode === '') {
            return null;
        }

        $row = $this->db->database()->query(
            'SELECT id FROM presets WHERE country_code = ? ORDER BY id ASC LIMIT 1',
            [$countryCode],
        )->fetch();

        if ($row === null) {
            $this->logger->warning('No default preset for country', ['country' => $countryCode]);

            return null;
        }

        return (int) $row['id'];
    }
}
