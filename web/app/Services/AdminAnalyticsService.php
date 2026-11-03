<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;

class AdminAnalyticsService
{
    private const MODEL_LABELS = [
        'completion_rate_only' => 'Completion Rate Baseline',
        'logistic_regression' => 'Logistic Regression',
        'random_forest' => 'Random Forest',
    ];

    /** @return array{available: bool, message: ?string, cross_validation_f1: ?float, validation_f1: ?float, hit_rate_at_3: ?float, mae_hours: ?float, models: array} */
    public function modelMetrics(): array
    {
        $cardPath = config('services.slot_recommender.model_card_path');
        $baselinesPath = config('services.slot_recommender.baselines_path');

        if (! is_file($cardPath) || ! is_readable($cardPath) || ! is_file($baselinesPath) || ! is_readable($baselinesPath)) {
            return $this->unavailableMetrics();
        }

        $modelCardJson = file_get_contents($cardPath);
        $baselinesJson = file_get_contents($baselinesPath);
        if ($modelCardJson === false || $baselinesJson === false) {
            return $this->unavailableMetrics();
        }

        try {
            $modelCard = json_decode($modelCardJson, true, flags: JSON_THROW_ON_ERROR);
            $baselines = json_decode($baselinesJson, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unavailableMetrics();
        }

        if (! is_array($modelCard) || ! is_array($baselines) || ! is_numeric($modelCard['cv_f1'] ?? null)) {
            return $this->unavailableMetrics();
        }

        foreach (self::MODEL_LABELS as $key => $label) {
            $row = $baselines[$key] ?? null;
            if (! is_array($row)
                || ! is_numeric($row['f1'] ?? null)
                || ! is_numeric($row['hit_rate_at_3'] ?? null)
                || ! is_numeric($row['mae_hours'] ?? null)) {
                return $this->unavailableMetrics();
            }
        }

        $models = [];
        foreach (self::MODEL_LABELS as $key => $label) {
            $models[] = [
                'label' => $label,
                'f1' => (float) $baselines[$key]['f1'],
                'hit_rate_at_3' => (float) $baselines[$key]['hit_rate_at_3'],
                'mae_hours' => (float) $baselines[$key]['mae_hours'],
            ];
        }

        $randomForest = $baselines['random_forest'];

        return [
            'available' => true,
            'message' => null,
            'cross_validation_f1' => (float) $modelCard['cv_f1'],
            'validation_f1' => (float) $randomForest['f1'],
            'hit_rate_at_3' => (float) $randomForest['hit_rate_at_3'],
            'mae_hours' => (float) $randomForest['mae_hours'],
            'models' => $models,
        ];
    }

    /** @return array{reachable: bool, http_status: ?int, model_loaded: ?bool} */
    public function recommenderHealth(): array
    {
        try {
            $response = Http::timeout(2)->get(rtrim(config('services.slot_recommender.url'), '/').'/health');

            return [
                'reachable' => true,
                'http_status' => $response->status(),
                'model_loaded' => is_bool($response->json('model_loaded')) ? $response->json('model_loaded') : null,
            ];
        } catch (ConnectionException) {
            return [
                'reachable' => false,
                'http_status' => null,
                'model_loaded' => null,
            ];
        }
    }

    /** @return array{available: false, message: string, cross_validation_f1: null, validation_f1: null, hit_rate_at_3: null, mae_hours: null, models: array} */
    private function unavailableMetrics(): array
    {
        return [
            'available' => false,
            'message' => 'Model metrics are unavailable because the model card or baseline results are missing or invalid.',
            'cross_validation_f1' => null,
            'validation_f1' => null,
            'hit_rate_at_3' => null,
            'mae_hours' => null,
            'models' => [],
        ];
    }
}
