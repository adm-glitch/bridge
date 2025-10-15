<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Insights\ListAiInsightsRequest;
use App\Services\AiInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AiInsightsController extends Controller
{
    private AiInsightsService $service;

    public function __construct(AiInsightsService $service)
    {
        $this->service = $service;
    }

    /**
     * GET /api/v1/ai/insights/{lead_id}
     */
    public function show(ListAiInsightsRequest $request, int $leadId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $period = $validated['period'] ?? '30d';
            $includeHistory = (bool) ($validated['include_history'] ?? false);

            $result = $this->service->getInsights($leadId, $period, $includeHistory);

            $etag = $this->generateEtag([$leadId, $period, $includeHistory, $result['current_insights']['performance_score'] ?? null]);
            if ($request->headers->get('If-None-Match') === $etag) {
                return response()->json(null, 304);
            }

            $response = [
                'success' => true,
                'lead_id' => $leadId,
                'period' => $period,
                'current_insights' => $result['current_insights'] ?? [],
                'historical_insights' => $result['historical_insights'] ?? [],
                'generated_at' => now()->toIso8601String(),
                'cache_ttl_seconds' => $result['cache_ttl_seconds'] ?? 3600,
            ];

            return response()
                ->json($response, 200)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, max-age=3600');
        } catch (\Exception $e) {
            Log::error('Failed to get AI insights', [
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch insights',
                'error_code' => 'INTERNAL_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    private function generateEtag(array $parts): string
    {
        return 'W/"' . md5(json_encode($parts)) . '"';
    }
}
