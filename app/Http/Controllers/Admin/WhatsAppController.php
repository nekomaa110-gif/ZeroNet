<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\CustomerContact;
use App\Models\RadCheck;
use App\Models\WaBroadcast;
use App\Services\ActivityLogService;
use App\Services\WaSendTracker;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WhatsAppController extends Controller
{
    private ?array $cacheKontak = null;

    public function index(Request $r, WhatsAppService $wa)
    {
        $contacts = CustomerContact::query()
            ->orderBy('username')
            ->get();

        $availableUsernames = RadCheck::where('attribute', 'Cleartext-Password')
            ->whereNotIn('username', $contacts->pluck('username'))
            ->orderBy('username')
            ->pluck('username');

        $broadcast = WaBroadcast::where('status', 'running')->latest('id')->first()
            ?? WaBroadcast::where('finished_at', '>=', now()->subHours(6))->latest('id')->first();

        return view('whatsapp.index', [
            'status'             => $wa->status(),
            'contacts'           => $contacts,
            'availableUsernames' => $availableUsernames,
            'broadcast'          => $broadcast ? $this->ringkasanBroadcast($broadcast) : null,
            'bcCounts'           => $this->hitungTarget(),
        ]);
    }

    public function gatewayState(WhatsAppService $wa)
    {
        return response()->json($wa->status(withQr: true));
    }

    public function gatewayReconnect(WhatsAppService $wa)
    {
        $res = $wa->reconnect();

        ActivityLogService::log(
            action: 'wa_gateway_reconnect',
            description: 'Reconnect WhatsApp gateway dari panel',
            subjectType: 'whatsapp',
            subjectId: 'gateway',
            properties: ['ok' => $res['ok'] ?? false],
        );

        return response()->json($res);
    }

    public function gatewayReset(WhatsAppService $wa)
    {
        $res = $wa->reset();

        ActivityLogService::log(
            action: 'wa_gateway_reset',
            description: 'Putus sesi WhatsApp & minta QR baru dari panel',
            subjectType: 'whatsapp',
            subjectId: 'gateway',
            properties: ['ok' => $res['ok'] ?? false],
        );

        return response()->json($res);
    }

    public function send(Request $r)
    {
        $data = $r->validate([
            'contact_id' => ['required', 'integer', 'exists:customer_contacts,id'],
            'message'    => ['required', 'string', 'max:1500'],
        ], [
            'contact_id.required' => 'Pilih dulu kontak tujuannya.',
            'contact_id.exists'   => 'Kontak tujuan sudah tidak ada.',
        ]);

        $contact = CustomerContact::findOrFail($data['contact_id']);
        $number  = WhatsAppService::normalizePhone($contact->phone);

        $trackId = (string) Str::uuid();
        WaSendTracker::start($trackId, $number, $contact->name ?: $contact->username);

        SendWhatsAppMessage::dispatch($number, $data['message'], $contact->id, auth()->id(), false, $trackId);

        ActivityLogService::log(
            action: 'wa_send',
            description: "Kirim WA manual ke: {$contact->username}",
            subjectType: 'whatsapp',
            subjectId: $contact->username,
            properties: ['len' => mb_strlen($data['message']), 'phone' => $number],
        );

        return back()->with('wa_track', $trackId);
    }

    public function sendStatus(string $track)
    {
        $state = WaSendTracker::get($track);

        if (! $state) {
            return response()->json(['status' => 'unknown', 'done' => true]);
        }

        if (isset($state['number'])) {
            $state['number'] = self::maskPhone($state['number']);
        }

        return response()->json($state);
    }

    public function searchContacts(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['query' => $q, 'results' => [], 'too_short' => true]);
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';

        $rows = CustomerContact::query()
            ->where(fn ($w) => $w->where('username', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('notes', 'like', $like))
            ->orderBy('username')
            ->limit(25)
            ->get();

        return response()->json([
            'query'   => $q,
            'results' => $rows->map(fn (CustomerContact $c) => self::contactPayload($c))->all(),
        ]);
    }

    private static function contactPayload(CustomerContact $c): array
    {
        return [
            'id'       => $c->id,
            'username' => $c->username,
            'name'     => $c->name,
            'phone'    => self::maskPhone($c->phone),
            'notes'    => $c->notes,
        ];
    }

    private static function maskPhone(?string $phone): string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return '';
        }

        $tail = mb_substr($phone, -4);

        return str_repeat('•', max(1, mb_strlen($phone) - 4)) . $tail;
    }

    public function storeContact(Request $r)
    {
        $data = $r->validate([
            'username' => ['required', 'string', 'max:64', 'unique:customer_contacts,username'],
            'phone'    => ['required', 'string', 'regex:/^(62|\+62|0)[0-9]{9,13}$/'],
            'name'     => ['nullable', 'string', 'max:100'],
            'notes'    => ['nullable', 'string', 'max:255'],
        ], [
            'phone.regex' => 'Nomor harus format Indonesia: 0812..., 62812..., atau +62812...',
        ]);
        $data['phone'] = WhatsAppService::normalizePhone($data['phone']);
        CustomerContact::create($data);

        ActivityLogService::log(
            action: 'wa_contact_create',
            description: "Tambah kontak WA: {$data['username']} → {$data['phone']}",
            subjectType: 'wa_contact',
            subjectId: $data['username'],
            properties: ['phone' => $data['phone']],
        );

        return back()->with('ok', "Kontak {$data['username']} ditambahkan.");
    }

    public function updateContact(Request $r, CustomerContact $contact)
    {
        $data = $r->validate([
            'phone' => ['nullable', 'string', 'regex:/^(62|\+62|0)[0-9]{9,13}$/'],
            'name'  => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'phone.regex' => 'Nomor harus format Indonesia: 0812..., 62812..., atau +62812...',
        ]);
        $oldPhone = $contact->getOriginal('phone');

        if (filled($data['phone'] ?? null)) {
            $data['phone'] = WhatsAppService::normalizePhone($data['phone']);
        } else {
            unset($data['phone']);
        }

        $contact->update($data);

        ActivityLogService::log(
            action: 'wa_contact_update',
            description: "Update kontak WA: {$contact->username}",
            subjectType: 'wa_contact',
            subjectId: $contact->username,
            properties: ['phone_old' => $oldPhone, 'phone_new' => $contact->phone],
        );

        if ($r->expectsJson()) {
            return response()->json([
                'ok'      => true,
                'message' => "Kontak {$contact->username} diupdate.",
                'contact' => self::contactPayload($contact->refresh()),
            ]);
        }

        return back()->with('ok', "Kontak {$contact->username} diupdate.");
    }

    public function destroyContact(Request $r, CustomerContact $contact)
    {
        $u = $contact->username;
        $oldPhone = $contact->phone;
        $contact->delete();

        ActivityLogService::log(
            action: 'wa_contact_delete',
            description: "Hapus kontak WA: {$u}",
            subjectType: 'wa_contact',
            subjectId: $u,
            properties: ['phone' => $oldPhone],
        );

        if ($r->expectsJson()) {
            return response()->json(['ok' => true, 'message' => "Kontak {$u} dihapus."]);
        }

        return back()->with('ok', "Kontak {$u} dihapus.");
    }

    public function broadcast(Request $r)
    {
        $data = $r->validate([
            'message' => ['required', 'string', 'max:1500'],
            'target'  => ['required', 'string', 'in:all,active,expiring'],
        ]);

        $berjalan = WaBroadcast::where('status', 'running')->first();
        if ($berjalan) {
            return back()->withInput()->withErrors([
                'target' => 'Masih ada broadcast berjalan. Tunggu selesai atau hentikan dulu.',
            ]);
        }

        $contacts = $this->kontakTarget($data['target']);

        if ($contacts->isEmpty()) {
            return back()->withInput()->withErrors(['target' => 'Tidak ada kontak yang cocok dengan filter.']);
        }

        $bc = WaBroadcast::create([
            'user_id' => auth()->id(),
            'target'  => $data['target'],
            'message' => $data['message'],
            'total'   => $contacts->count(),
            'status'  => 'running',
        ]);

        $delaySeconds = 0;

        foreach ($contacts as $c) {
            $phone = WhatsAppService::normalizePhone($c->phone);
            $msg   = strtr($data['message'], [
                '{name}'     => $c->name ?: $c->username,
                '{username}' => $c->username,
            ]);

            $rec = $bc->recipients()->create([
                'contact_id' => $c->id,
                'username'   => $c->username,
                'name'       => $c->name,
                'phone'      => $phone,
                'status'     => 'pending',
            ]);

            SendWhatsAppMessage::dispatch($phone, $msg, $c->id, auth()->id(), false, null, $rec->id)
                ->delay(now()->addSeconds($delaySeconds));

            $delaySeconds += rand(4, 10);
        }

        $estimatedMinutes = max(1, (int) ceil($delaySeconds / 60));

        ActivityLogService::log(
            action: 'wa_broadcast',
            description: "Broadcast WA target={$data['target']} → {$bc->total} kontak",
            subjectType: 'whatsapp',
            subjectId: 'broadcast:' . $bc->id,
            properties: [
                'target'  => $data['target'],
                'count'   => $bc->total,
                'len'     => mb_strlen($data['message']),
                'eta_min' => $estimatedMinutes,
            ],
        );

        return back()->with('ok', "Broadcast {$bc->total} pesan masuk antrean. Estimasi selesai ~{$estimatedMinutes} menit.");
    }

    private function kontakDenganExpiry(): array
    {
        if ($this->cacheKontak !== null) {
            return $this->cacheKontak;
        }

        $contacts = CustomerContact::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->orderBy('username')
            ->get();

        $expiry = \DB::table('radcheck')
            ->where('attribute', 'Expiration')
            ->whereIn('username', $contacts->pluck('username'))
            ->pluck('value', 'username');

        return $this->cacheKontak = [$contacts, $expiry];
    }

    private function kontakTarget(string $target)
    {
        [$contacts, $expiry] = $this->kontakDenganExpiry();

        if ($target === 'all') {
            return $contacts;
        }

        $now = \Carbon\Carbon::now();

        return $contacts->filter(function ($c) use ($expiry, $target, $now) {
            $raw = $expiry[$c->username] ?? null;
            if (! $raw) {
                return false;
            }

            try {
                $exp = \Carbon\Carbon::createFromFormat('d M Y H:i:s', trim($raw));
            } catch (\Throwable) {
                return false;
            }

            if ($target === 'active') {
                return $exp->gt($now);
            }

            return $exp->gt($now) && $exp->lte($now->copy()->addDays(7));
        })->values();
    }

    private function hitungTarget(): array
    {
        return [
            'all'      => $this->kontakTarget('all')->count(),
            'active'   => $this->kontakTarget('active')->count(),
            'expiring' => $this->kontakTarget('expiring')->count(),
        ];
    }

    public function broadcastStatus(WaBroadcast $broadcast)
    {
        return response()->json($this->ringkasanBroadcast($broadcast));
    }

    public function broadcastRetry(WaBroadcast $broadcast)
    {
        $tertinggal = $broadcast->recipients()
            ->where(function ($w) {
                $w->where('status', 'failed')
                  ->orWhere(fn ($x) => $x->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(10)));
            })
            ->get();

        if ($tertinggal->isEmpty()) {
            return response()->json(['ok' => false, 'message' => 'Tidak ada penerima yang perlu diulang.']);
        }

        $broadcast->forceFill(['status' => 'running', 'finished_at' => null])->save();

        $delay = 0;
        foreach ($tertinggal as $rec) {
            $rec->forceFill(['status' => 'pending', 'error' => null, 'attempt' => 0])->save();

            $msg = strtr($broadcast->message, [
                '{name}'     => $rec->name ?: $rec->username,
                '{username}' => $rec->username,
            ]);

            SendWhatsAppMessage::dispatch($rec->phone, $msg, $rec->contact_id, auth()->id(), false, null, $rec->id)
                ->delay(now()->addSeconds($delay));

            $delay += rand(4, 10);
        }

        ActivityLogService::log(
            action: 'wa_broadcast',
            description: "Ulangi {$tertinggal->count()} penerima broadcast #{$broadcast->id}",
            subjectType: 'whatsapp',
            subjectId: 'broadcast:' . $broadcast->id,
            properties: ['retry' => $tertinggal->count()],
        );

        return response()->json([
            'ok'      => true,
            'message' => "{$tertinggal->count()} pesan dicoba ulang.",
        ] + $this->ringkasanBroadcast($broadcast->refresh()));
    }

    public function broadcastCancel(WaBroadcast $broadcast)
    {
        $sisa = $broadcast->recipients()->whereIn('status', ['pending', 'sending'])->count();

        $broadcast->recipients()
            ->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'cancelled', 'error' => 'Dihentikan admin', 'updated_at' => now()]);

        $broadcast->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();

        ActivityLogService::log(
            action: 'wa_broadcast',
            description: "Broadcast #{$broadcast->id} dihentikan, sisa {$sisa} pesan dibatalkan",
            subjectType: 'whatsapp',
            subjectId: 'broadcast:' . $broadcast->id,
            properties: ['cancelled' => $sisa],
        );

        return response()->json([
            'ok'      => true,
            'message' => "Broadcast dihentikan. {$sisa} pesan sisa dibatalkan.",
        ] + $this->ringkasanBroadcast($broadcast->refresh()));
    }

    public function ringkasanBroadcast(WaBroadcast $broadcast): array
    {
        $counts  = $broadcast->counts();
        $selesai = $counts['sent'] + $counts['failed'] + $counts['cancelled'];
        $total   = max(1, $broadcast->total);

        $rows = $broadcast->recipients()
            ->orderByRaw("FIELD(status,'sending','pending','failed','cancelled','sent')")
            ->orderBy('id')
            ->get()
            ->map(fn ($rec) => [
                'id'       => $rec->id,
                'name'     => $rec->name ?: $rec->username,
                'username' => $rec->username,
                'phone'    => self::maskPhone($rec->phone),
                'status'   => $rec->status,
                'error'    => $rec->error,
                'sent_at'  => $rec->sent_at?->format('H:i:s'),
            ]);

        $sentuhTerakhir = $broadcast->recipients()->max('updated_at');
        $macet = $broadcast->status === 'running'
            && $sentuhTerakhir
            && \Carbon\Carbon::parse($sentuhTerakhir)->lt(now()->subMinutes(10))
            && ($counts['pending'] + $counts['sending']) > 0;

        return [
            'id'          => $broadcast->id,
            'status'      => $broadcast->status,
            'target'      => WaBroadcast::TARGET_LABELS[$broadcast->target] ?? $broadcast->target,
            'total'       => $broadcast->total,
            'counts'      => $counts,
            'done'        => $selesai,
            'percent'     => (int) round($selesai / $total * 100),
            'running'     => $broadcast->status === 'running',
            'stalled'     => $macet,
            'started_at'  => $broadcast->created_at?->format('d M H:i'),
            'finished_at' => $broadcast->finished_at?->format('d M H:i'),
            'recipients'  => $rows,
        ];
    }
}
