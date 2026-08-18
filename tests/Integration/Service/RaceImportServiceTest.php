<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Database\DatabaseSeeder;
use App\Repository\RaceDetailRepository;
use App\Repository\RaceObservationRepository;
use App\Repository\RaceSetupRepository;
use App\Security\ApiTokenCrypto;
use App\Service\GproApiClient;
use App\Service\GproApiFetcher;
use App\Service\RaceImportService;
use App\Tests\Support\ArrayCache;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(RaceImportService::class)]
final class RaceImportServiceTest extends TestCase
{
    private PDO $db;
    private RaceImportService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseSeeder(
            $this->db,
            ['Concentration' => 'concentration'],
            ['Rookie'],
            [],
            new ApiTokenCrypto('race-import-fixture-secret'),
        ))->migrate();

        $this->db->prepare('INSERT INTO tracks (name, lap_length, fuel_per_lap) VALUES (?, ?, ?)')
            ->execute(['Fixture Ring', 4.0, 2.5]);

        $this->service = new RaceImportService(
            new GproApiClient(
                new GproApiFetcher(['base_url' => 'http://127.0.0.1:9', 'version' => 'test']),
                new ArrayCache(),
            ),
            new RaceObservationRepository($this->db),
            new RaceSetupRepository($this->db),
            new RaceDetailRepository($this->db),
            $this->db,
            new ArrayCache(),
        );
    }

    public function testCompleteFixtureIsRetainedAndNormalized(): void
    {
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/race-analysis-complete.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $method = new ReflectionMethod($this->service, 'importFromPayload');
        $this->assertTrue($method->invoke($this->service, $data, 999, 12));

        $observation = $this->db->query(
            'SELECT * FROM race_observations WHERE season = 999 AND race_number = 12'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($observation);
        $this->assertSame('Fixture Ring', $observation['track_name']);
        $this->assertNotEmpty($observation['raw_payload']);
        $this->assertEqualsWithDelta(2.6667, (float) $observation['fuel_per_km'], 0.0001);
        $this->assertSame(4, (int) $observation['q1_pos']);
        $this->assertSame(2, (int) $observation['overtakes']);
        $this->assertSame(22.0, (float) $observation['car_power']);

        $this->assertSame(3, $this->countRows('race_setups'));
        $this->assertSame(3, $this->countRows('race_laps'));
        $this->assertSame(1, $this->countRows('race_pits'));
        $this->assertSame(11, $this->countRows('race_car_parts'));
        $this->assertSame(2, $this->countRows('race_transactions'));
        $this->assertSame(2, $this->countRows('race_practice_laps'));

        $event = $this->db->query("SELECT events FROM race_laps WHERE season = 999 AND lap_idx = 1")
            ->fetchColumn();
        $this->assertJsonStringEqualsJsonString('[{"event":"Pit","eventColor":"yellow"}]', (string) $event);

        $feedback = $this->db->query(
            'SELECT driver_comments FROM race_practice_laps WHERE season = 999 AND lap_idx = 1'
        )->fetchColumn();
        $this->assertJsonStringEqualsJsonString('[{"part":"Wings","text":"Synthetic feedback"}]', (string) $feedback);
    }

    private function countRows(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$table} WHERE season = 999 AND race_number = 12")
            ->fetchColumn();
    }
}