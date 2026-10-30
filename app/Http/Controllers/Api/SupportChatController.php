<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class SupportChatController extends Controller
{
    private function admin(Request $r): object
    {
        $u = $r->bearerToken() ? app(\App\Services\ApiAccess::class)->user($r) : null;
        abort_unless($u, 401);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin','super_admin'], true), 403);
        return $u;
    }
    private function conversation(Request $r, string $id, bool $admin = false, bool $lock = false): object
    {
        if ($admin) {
            $this->admin($r);
        }
        $q = DB::table('support_conversations')->where('id', $id);
        if ($lock) {
            $q->lockForUpdate();
        }$c = $q->first();
        abort_unless($c, 404);
        if (!$admin) {
            $token = (string)$r->header('X-Chat-Token');
            abort_unless(strlen($token) === 64 && hash_equals($c->visitor_token_hash, hash('sha256', $token)) && Carbon::parse($c->token_expires_at)->isFuture(), 404);
        }
        return $c;
    }
    private function timestamp(?string $value): ?string
    {
        return $value ? Carbon::parse($value, config('app.timezone'))->toIso8601String() : null;
    }
    private function messageRow(object $m): array
    {
        return [...array_intersect_key((array)$m, array_flip(['id','request_id','sender_type','body'])),'created_at' => $this->timestamp($m->created_at)];
    }
    private function publicRow(object $c): array
    {
        return [...array_intersect_key((array)$c, array_flip(['id','visitor_name','subject','status','created_at','last_message_at'])),'created_at' => $this->timestamp($c->created_at),'last_message_at' => $this->timestamp($c->last_message_at),'unread_count' => DB::table('support_messages')->where('conversation_id', $c->id)->where('sender_type', 'agent')->where('id', '>', $c->visitor_read_id)->count()];
    }
    private function event(string $id, ?int $actor, string $action, array $details = []): void
    {
        DB::table('support_events')->insert(['conversation_id' => $id,'actor_id' => $actor,'action' => $action,'details' => json_encode($details),'created_at' => now()]);
        if (in_array($action, ['conversation.created','agent.replied','conversation.updated'], true)) {
            $c = DB::table('support_conversations')->where('id', $id)->first();
            if ($action === 'agent.replied' && DB::table('support_messages')->where('conversation_id', $id)->where('sender_type', 'agent')->where('id', '>', $c->visitor_read_id)->count() > 1) {
                return;
            }
            if ($action === 'conversation.updated' && empty($details['status_changed'])) {
                return;
            }
            $title = match($action) {
                'conversation.created' => 'Support request received','agent.replied' => 'Your NUERS support team has replied',default => 'Support request updated'
            };
            app(\App\Services\PlatformNotifications::class)->user($c?->user_id, 'support.'.$action, $id.'|'.($c->revision ?? 1), $title, 'Sign in to NUERS and open Help & Support to view your support conversation. Sensitive messages are available only inside your account.', 'support');
        }
    }
    public function create(Request $r)
    {
        $v = $r->validate(['id' => 'required|uuid','visitor_token' => 'required|string|regex:/^[a-f0-9]{64}$/','request_id' => 'required|uuid','name' => 'nullable|string|max:120','email' => 'nullable|email|max:255','subject' => 'required|string|max:180','message' => 'required|string|max:4000']);
        return DB::transaction(function () use ($r, $v) {
            $existing = DB::table('support_conversations')->where('id', $v['id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless(Carbon::parse($existing->token_expires_at)->isFuture(), 404);
                abort_unless(hash_equals($existing->visitor_token_hash, hash('sha256', $v['visitor_token'])), 404);
                $m = DB::table('support_messages')->where('conversation_id', $existing->id)->orderBy('id')->first();
                abort_unless($m && $m->request_id === $v['request_id'] && $m->body === $v['message'], 409, 'This request has already been submitted with different content.');
                return response()->json(['conversation' => $this->publicRow($existing)], 200);
            }
            $u = $r->bearerToken() ? app(\App\Services\ApiAccess::class)->user($r) : null;
            DB::table('support_conversations')->insert(['id' => $v['id'],'visitor_token_hash' => hash('sha256', $v['visitor_token']),'token_expires_at' => now()->addDays(90),'user_id' => $u?->id,'visitor_name' => trim($v['name'] ?? '') ?: 'Website visitor','visitor_email' => $v['email'] ?? null,'subject' => $v['subject'],'status' => 'open','priority' => 'normal','last_message_at' => now(),'created_at' => now(),'updated_at' => now()]);
            DB::table('support_messages')->insert(['conversation_id' => $v['id'],'request_id' => $v['request_id'],'sender_type' => 'visitor','sender_id' => $u?->id,'body' => $v['message'],'created_at' => now()]);
            $this->event($v['id'], $u?->id, 'conversation.created');
            return response()->json(['conversation' => $this->publicRow(DB::table('support_conversations')->find($v['id']))], 201);
        });
    }
    public function inbox(Request $r)
    {
        $this->admin($r);
        $v = $r->validate(['search' => 'nullable|string|max:150','status' => ['nullable',Rule::in(['open','pending','resolved'])],'priority' => ['nullable',Rule::in(['normal','high','urgent'])],'page' => 'nullable|integer|min:1','unread' => 'nullable|boolean']);
        $q = DB::table('support_conversations as c')->select('c.*')->selectSub(fn ($s) => $s->from('support_messages as m')->selectRaw('COUNT(*)')->whereColumn('m.conversation_id', 'c.id')->where('m.sender_type', 'visitor')->whereColumn('m.id', '>', 'c.admin_read_id'), 'unread_count')->selectSub(fn ($s) => $s->from('support_messages as m')->select('body')->whereColumn('m.conversation_id', 'c.id')->orderByDesc('id')->limit(1), 'preview');
        if (!empty($v['status'])) {
            $q->where('status', $v['status']);
        }if (!empty($v['priority'])) {
            $q->where('priority', $v['priority']);
        }
        if (!empty($v['search'])) {
            $term = '%'.str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $v['search']).'%';
            $q->where(fn ($s) => $s->where('visitor_name', 'like', $term)->orWhere('visitor_email', 'like', $term)->orWhere('subject', 'like', $term)->orWhereExists(fn ($m) => $m->selectRaw('1')->from('support_messages as sm')->whereColumn('sm.conversation_id', 'c.id')->where('sm.body', 'like', $term)));
        }
        if (!empty($v['unread'])) {
            $q->whereExists(fn ($m) => $m->selectRaw('1')->from('support_messages as um')->whereColumn('um.conversation_id', 'c.id')->where('um.sender_type', 'visitor')->whereColumn('um.id', '>', 'c.admin_read_id'));
        }
        $rows = $q->orderByDesc('last_message_at')->paginate(20);
        $data = $rows->items();
        foreach ($data as $row) {
            unset($row->visitor_token_hash,$row->token_expires_at);
            $row->last_message_at = $this->timestamp($row->last_message_at);
            $row->created_at = $this->timestamp($row->created_at);
        }
        return response()->json(['data' => $data,'last_page' => $rows->lastPage(),'total' => $rows->total(),'page' => $rows->currentPage(),'counts' => ['open' => DB::table('support_conversations')->where('status', 'open')->count(),'pending' => DB::table('support_conversations')->where('status', 'pending')->count(),'resolved' => DB::table('support_conversations')->where('status', 'resolved')->count(),'unread' => DB::table('support_messages as m')->join('support_conversations as c', 'c.id', '=', 'm.conversation_id')->where('sender_type', 'visitor')->whereColumn('m.id', '>', 'c.admin_read_id')->count()],'agents' => DB::table('users')->join('profiles', 'profiles.id', '=', 'users.id')->whereIn('profiles.role', ['admin','super_admin'])->get(['users.id','users.name'])]);
    }
    public function messages(Request $r, string $id, bool $admin = false)
    {
        $c = $this->conversation($r, $id, $admin);
        $v = $r->validate(['after' => 'nullable|integer|min:0','before' => 'nullable|integer|min:1']);
        $q = DB::table('support_messages')->where('conversation_id', $id);
        if (!empty($v['after'])) {
            $q->where('id', '>', $v['after'])->orderBy('id');
        } else {
            if (!empty($v['before'])) {
                $q->where('id', '<', $v['before']);
            }$q->orderByDesc('id');
        }
        $messages = $q->limit(101)->get(['id','request_id','sender_type','body','created_at']);
        $hasMore = $messages->count() > 100;
        $messages = $messages->take(100);
        if (empty($v['after'])) {
            $messages = $messages->reverse();
        }
        $conversation = $admin ? (array)$c : $this->publicRow($c);
        unset($conversation['visitor_token_hash'],$conversation['token_expires_at']);
        return response()->json(['conversation' => $conversation,'messages' => $messages->map(fn ($m) => $this->messageRow($m))->values(),'has_more' => $hasMore]);
    }
    public function send(Request $r, string $id, bool $admin = false)
    {
        $actor = $admin ? $this->admin($r) : null;
        $v = $r->validate(['request_id' => 'required|uuid','body' => 'required|string|max:4000']);
        return DB::transaction(function () use ($r, $id, $admin, $actor, $v) {
            $c = $this->conversation($r, $id, $admin, true);
            $old = DB::table('support_messages')->where('conversation_id', $id)->where('request_id', $v['request_id'])->first();
            if ($old) {
                abort_unless($old->body === $v['body'] && $old->sender_type === ($admin ? 'agent' : 'visitor'), 409);
                return response()->json(['message' => $this->messageRow($old)]);
            }
            $messageId = DB::table('support_messages')->insertGetId(['conversation_id' => $id,'request_id' => $v['request_id'],'sender_type' => $admin ? 'agent' : 'visitor','sender_id' => $actor?->id,'body' => $v['body'],'created_at' => now()]);
            DB::table('support_conversations')->where('id', $id)->update(['status' => $admin ? 'pending' : 'open','last_message_at' => now(),'revision' => $c->revision + 1,'updated_at' => now()]);
            $this->event($id, $actor?->id, $admin ? 'agent.replied' : 'visitor.replied');
            return response()->json(['message' => $this->messageRow(DB::table('support_messages')->where('id', $messageId)->first())], 201);
        });
    }
    public function read(Request $r, string $id, bool $admin = false)
    {
        $c = $this->conversation($r, $id, $admin);
        $v = $r->validate(['message_id' => 'required|integer|min:1']);
        abort_unless(DB::table('support_messages')->where('conversation_id', $id)->where('id', $v['message_id'])->exists(), 422);
        $col = $admin ? 'admin_read_id' : 'visitor_read_id';
        DB::table('support_conversations')->where('id', $id)->where($col, '<', $v['message_id'])->update([$col => $v['message_id']]);
        return response()->json(['ok' => true]);
    }
    public function update(Request $r, string $id)
    {
        $actor = $this->admin($r);
        $v = $r->validate(['revision' => 'required|integer|min:1','status' => ['required',Rule::in(['open','pending','resolved'])],'priority' => ['required',Rule::in(['normal','high','urgent'])],'assigned_to' => 'nullable|integer']);
        if (!empty($v['assigned_to'])) {
            abort_unless(DB::table('profiles')->where('id', $v['assigned_to'])->whereIn('role', ['admin','super_admin'])->exists(), 422, 'Assign to an administrator.');
        }
        return DB::transaction(function () use ($r, $id, $actor, $v) {
            $c = $this->conversation($r, $id, true, true);
            abort_unless($c->revision === $v['revision'], 409, 'Another administrator updated this conversation. Reload and retry.');
            DB::table('support_conversations')->where('id', $id)->update([...$v,'revision' => $c->revision + 1,'updated_at' => now()]);
            $this->event($id, $actor->id, 'conversation.updated', ['status_changed' => $c->status !== $v['status'],'status' => $v['status'],'priority' => $v['priority'],'assigned_to' => $v['assigned_to'] ?? null]);
            return response()->json(['ok' => true]);
        });
    }
    public function adminMessages(Request $r,string $id)
    {
        return $this->messages($r,$id,true);
    }public function adminSend(Request $r,string $id)
    {
        return $this->send($r,$id,true);
    }public function adminRead(Request $r,string $id)
    {
        return $this->read($r,$id,true);
    }
}
