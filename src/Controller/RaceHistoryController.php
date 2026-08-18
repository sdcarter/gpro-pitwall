<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Repository\RaceDetailRepository;
use App\Repository\RaceObservationRepository;
use App\Repository\RaceSetupRepository;

/**
 * Read-only Race History tab: a filterable list of every race captured in
 * race_observations, drilling down into the full per-race detail (setups,
 * laps, pits, car parts, financials) captured alongside it.
 */
class RaceHistoryController
{
    public function __construct(
        private readonly RaceObservationRepository $observations,
        private readonly RaceSetupRepository $setups,
        private readonly RaceDetailRepository $detail,
    ) {
    }

    /** @return array<string, mixed> */
    public function buildViewData(Request $request): array
    {
        $season = (string) $request->get('hist_race_season', '');
        $race   = (string) $request->get('hist_race_number', '');

        if ($season !== '' && $race !== '') {
            return $this->buildDetail((int) $season, (int) $race);
        }

        return $this->buildList($request);
    }

    /** @return array<string, mixed> */
    private function buildList(Request $request): array
    {
        $filters = [
            'season'           => (string) $request->get('hist_season', ''),
            'track_name'       => (string) $request->get('hist_track', ''),
            'weather'          => (string) $request->get('hist_weather', ''),
            'tyre_supplier'    => (string) $request->get('hist_supplier', ''),
            'overtaking'       => (string) $request->get('hist_overtaking', ''),
            'grip'             => (string) $request->get('hist_grip', ''),
            'tyre_wear'        => (string) $request->get('hist_tyre_wear', ''),
            'fuel_consumption' => (string) $request->get('hist_fuel_consumption', ''),
        ];
        $filters = array_filter($filters, fn (string $v): bool => $v !== '');

        return [
            'history_view'    => 'list',
            'history_races'   => $this->observations->findAll($filters),
            'history_filters' => $filters,
            'history_options' => [
                'seasons'          => $this->observations->distinctSeasons(),
                'tracks'           => $this->observations->distinctTracks(),
                'tyre_suppliers'   => $this->observations->distinctTyreSuppliers(),
                'overtaking'       => $this->observations->distinctTrackAttr('overtaking'),
                'grip'             => $this->observations->distinctTrackAttr('grip'),
                'tyre_wear'        => $this->observations->distinctTrackAttr('tyre_wear'),
                'fuel_consumption' => $this->observations->distinctTrackAttr('fuel_consumption'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function buildDetail(int $season, int $race): array
    {
        $observation = $this->observations->findOne($season, $race);
        if ($observation === null) {
            return ['history_view' => 'list', 'history_races' => [], 'history_filters' => [], 'history_options' => []];
        }

        // Twig has no json_decode filter — decode here for the view.
        $observation['technical_problems'] = !empty($observation['technical_problems'])
            ? json_decode((string) $observation['technical_problems'], true)
            : [];

        return [
            'history_view'          => 'detail',
            'history_race'          => $observation,
            'history_setups'        => $this->setups->findForRace($season, $race),
            'history_laps'          => $this->detail->getLaps($season, $race),
            'history_pits'          => $this->detail->getPits($season, $race),
            'history_car_parts'     => $this->detail->getCarParts($season, $race),
            'history_transactions'  => $this->detail->getTransactions($season, $race),
            'history_practice_laps' => $this->detail->getPracticeLaps($season, $race),
        ];
    }
}
