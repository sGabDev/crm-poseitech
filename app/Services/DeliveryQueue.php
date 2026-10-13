<?php

namespace App\Services;

use App\Jobs\SendMercadoPagoLink;
use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliveryQueue
{
    private int $shard = 0;

    private int $shards = 1;

    public static function releaseConnection(): void
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() === 0 && $connection->getDatabaseName() !== ':memory:') {
            $connection->disconnect();
        }
    }

    private function due(string $table)
    {
        return DB::table($table)->when($this->shards > 1, fn ($q) => $q->whereRaw('company_id % ? = ?', [$this->shards, $this->shard]))->where(function ($q) {
            $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'failed')->whereNotNull('next_attempt_at'));
        })->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
    }

    public function process(string $channel, int $limit = 100, int $seconds = 45, int $shard = 0, int $shards = 1): int
    {
        $this->shards = max(1, min(32, $shards));
        $this->shard = $shard;
        if ($shard < 0 || $shard >= $this->shards) {
            throw new \InvalidArgumentException('Partição de envios inválida.');
        }
        $table = ['email' => 'email_logs', 'whatsapp' => 'whatsapp_messages', 'payment' => 'mp_deliveries'][$channel];
        $deadline = microtime(true) + $seconds;
        $processed = 0;
        // A bounded group per company prevents one campaign from occupying the entire batch.
        $cursorKey = 'delivery.cursor.'.$channel.'.'.$this->shards.'.'.$shard;
        $cursor = (int) Cache::get($cursorKey, 0);
        $companies = $this->due($table)->where('company_id', '>', $cursor)->select('company_id')->distinct()->orderBy('company_id')->limit($limit)->get();
        if ($companies->isEmpty() && $cursor) {
            $companies = $this->due($table)->select('company_id')->distinct()->orderBy('company_id')->limit($limit)->get();
        }
        foreach ($companies as $entry) {
            if ($processed >= $limit || microtime(true) >= $deadline || DatabaseCapacity::remaining()) {
                break;
            }
            $cursor = $entry->company_id;
            $company = Company::find($entry->company_id);
            if (! $company || ! $company->available() || ($channel === 'email' && ! $company->smtp) || ($channel !== 'email' && ! $company->enabled('whatsapp'))) {
                $this->due($table)->where('company_id', $entry->company_id)->update(['next_attempt_at' => now()->addHour()]);

                continue;
            }
            $tenant = new Tenant;
            $tenant->company = $company;
            foreach ($this->due($table)->where('company_id', $company->id)->orderBy('id')->limit(min(5, $limit - $processed))->get() as $message) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                try {
                    if ($channel === 'email') {
                        app(MailQueue::class)->send($message->id, $company, $message->status === 'failed');
                    } elseif ($channel === 'whatsapp') {
                        (new WhatsApp($tenant))->send($message);
                    } else {
                        // Mercado Pago delivery locks are shared with manual delivery actions.
                        app(SendMercadoPagoLink::class, ['companyId' => $company->id, 'checkoutId' => $message->checkout_id])->handle(app(Tenant::class), app(MercadoPago::class));
                    }
                } catch (\Throwable $e) {
                    if (DatabaseCapacity::exhausted($e)) {
                        DatabaseCapacity::pause();
                        throw $e;
                    }
                    Log::warning('delivery.worker_failed', ['channel' => $channel, 'message_id' => $message->id, 'exception_class' => get_class($e)]);
                    $this->due($table)->where('id', $message->id)->update(['next_attempt_at' => now()->addMinutes(5)]);
                }
                $processed++;
            }
        }
        Cache::put($cursorKey, $cursor, now()->addDay());
        if ($channel === 'email') {
            $this->completeCampaigns();
        }

        return $processed;
    }

    private function completeCampaigns(): void
    {
        $campaigns = DB::table('campaigns')->where('status', 'queued')->when($this->shards > 1, fn ($q) => $q->whereRaw('company_id % ? = ?', [$this->shards, $this->shard]))->orderBy('updated_at')->orderBy('id')->limit(20)->get();
        foreach ($campaigns as $campaign) {
            $messages = DB::table('email_logs')->where('company_id', $campaign->company_id)->where('campaign_id', $campaign->id);
            $waiting = (clone $messages)->where(fn ($q) => $q->whereIn('status', ['pending', 'sending'])->orWhere(fn ($q) => $q->where('status', 'failed')->whereNotNull('next_attempt_at')))->exists();
            if ($waiting) {
                DB::table('campaigns')->where('id', $campaign->id)->where('status', 'queued')->update(['updated_at' => now()]);

                continue;
            }
            $failed = (clone $messages)->where('status', 'failed')->exists();
            if (DB::table('campaigns')->where('id', $campaign->id)->where('status', 'queued')->update(['status' => $failed ? 'failed' : 'completed', 'updated_at' => now()])) {
                DB::table('alerts')->insertOrIgnore(['company_id' => $campaign->company_id, 'key' => 'campaign-'.$campaign->id, 'title' => $failed ? 'Campanha com falhas' : 'Campanha concluída', 'body' => $campaign->name, 'url' => '/campaigns', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
