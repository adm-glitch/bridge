<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationCacheService
{
    public function getConversationsForLead(int $leadId, array $filters = [], int $limit = 20, int $page = 1, string $sort = 'updated_at', string $order = 'desc')
    {
        $cacheKey = $this->buildConversationsCacheKey($leadId, $filters, $limit, $page, $sort, $order);

        return Cache::tags(['conversations', "lead:{$leadId}"])->remember(
            $cacheKey,
            300,
            function () use ($leadId, $filters, $limit, $page, $sort, $order) {
                $query = DB::table('conversation_mappings')
                    ->where('krayin_lead_id', $leadId)
                    ->when($filters['status'] ?? null, fn($q, $status) => $q->where('status', $status))
                    ->orderBy($sort, $order);

                $total = (clone $query)->count();
                $results = $query->forPage($page, $limit)->get();

                return [
                    'results' => $results,
                    'total' => $total,
                ];
            }
        );
    }

    public function getMessagesForConversation(int $conversationId, int $limit = 50, ?int $beforeId = null, ?int $afterId = null)
    {
        $filters = array_filter(['limit' => $limit, 'before_id' => $beforeId, 'after_id' => $afterId], fn($v) => $v !== null);
        $cacheKey = $this->buildMessagesCacheKey($conversationId, $filters);

        return Cache::tags(['messages', "conversation:{$conversationId}"])->remember(
            $cacheKey,
            300,
            function () use ($conversationId, $limit, $beforeId, $afterId) {
                $query = DB::table('activity_mappings')
                    ->where('chatwoot_conversation_id', $conversationId)
                    ->orderBy('id', 'desc');

                if ($beforeId) {
                    $query->where('id', '<', $beforeId);
                }
                if ($afterId) {
                    $query->where('id', '>', $afterId);
                }

                $results = $query->limit($limit)->get();
                $totalCount = DB::table('activity_mappings')
                    ->where('chatwoot_conversation_id', $conversationId)
                    ->count();

                $hasMore = $totalCount > $results->count();
                $nextBeforeId = $results->last()->id ?? null;

                return [
                    'results' => $results,
                    'has_more' => $hasMore,
                    'next_before_id' => $nextBeforeId,
                    'total_count' => $totalCount,
                ];
            }
        );
    }

    public function invalidateLeadConversations(int $leadId): void
    {
        Cache::tags(["lead:{$leadId}"])->flush();
    }

    private function buildConversationsCacheKey(int $leadId, array $filters, int $limit, int $page, string $sort, string $order): string
    {
        $filterString = http_build_query($filters);
        return "lead:{$leadId}:conversations:{$limit}:{$page}:{$sort}:{$order}:" . md5($filterString);
    }

    private function buildMessagesCacheKey(int $conversationId, array $filters): string
    {
        $filterString = http_build_query($filters);
        return "conversation:{$conversationId}:messages:" . md5($filterString);
    }
}
