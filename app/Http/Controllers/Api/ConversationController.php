<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Conversation\ListConversationsRequest;
use App\Http\Requests\Api\Conversation\ListMessagesRequest;
use App\Services\ConversationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ConversationController extends Controller
{
    private ConversationCacheService $cacheService;

    public function __construct(ConversationCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * GET /api/v1/chatwoot/conversations/{lead_id}
     */
    public function listConversations(ListConversationsRequest $request, int $leadId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $status = $validated['status'] ?? null;
            $limit = (int) ($validated['limit'] ?? 20);
            $page = (int) ($validated['page'] ?? 1);
            $sort = $validated['sort'] ?? 'updated_at';
            $order = $validated['order'] ?? 'desc';

            $filters = [];
            if ($status) {
                $filters['status'] = $status;
            }

            $data = $this->cacheService->getConversationsForLead($leadId, $filters, $limit, $page, $sort, $order);

            $etag = $this->generateEtag([$leadId, $filters, $limit, $page, $sort, $order, $data['total'] ?? 0]);
            if ($request->headers->get('If-None-Match') === $etag) {
                return response()->json(null, 304);
            }

            $response = [
                'success' => true,
                'lead_id' => $leadId,
                'conversations' => $data['results'],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_results' => $data['total'] ?? 0,
                    'total_pages' => $this->calcTotalPages($data['total'] ?? 0, $limit),
                    'has_more' => ($page * $limit) < ($data['total'] ?? 0),
                ],
                'filters_applied' => $filters,
            ];

            return response()
                ->json($response, 200)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, max-age=300');
        } catch (\Exception $e) {
            Log::error('Failed to list conversations', [
                'lead_id' => $leadId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch conversations',
                'error_code' => 'INTERNAL_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/chatwoot/messages/{conversation_id}
     */
    public function listMessages(ListMessagesRequest $request, int $conversationId): JsonResponse
    {
        try {
            $validated = $request->validated();
            $limit = (int) ($validated['limit'] ?? 50);
            $beforeId = isset($validated['before_id']) ? (int) $validated['before_id'] : null;
            $afterId = isset($validated['after_id']) ? (int) $validated['after_id'] : null;

            $data = $this->cacheService->getMessagesForConversation($conversationId, $limit, $beforeId, $afterId);

            $etag = $this->generateEtag([$conversationId, $limit, $beforeId, $afterId, $data['total_count'] ?? 0]);
            if ($request->headers->get('If-None-Match') === $etag) {
                return response()->json(null, 304);
            }

            $response = [
                'success' => true,
                'conversation_id' => $conversationId,
                'messages' => $data['results'],
                'pagination' => [
                    'has_more' => $data['has_more'] ?? false,
                    'next_before_id' => $data['next_before_id'] ?? null,
                    'total_count' => $data['total_count'] ?? 0,
                ],
                'response_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ];

            return response()
                ->json($response, 200)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, max-age=300');
        } catch (\Exception $e) {
            Log::error('Failed to list messages', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch messages',
                'error_code' => 'INTERNAL_ERROR',
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    private function calcTotalPages(int $total, int $perPage): int
    {
        return $perPage > 0 ? (int) ceil($total / $perPage) : 0;
    }

    private function generateEtag(array $parts): string
    {
        return 'W/"' . md5(json_encode($parts)) . '"';
    }
}
