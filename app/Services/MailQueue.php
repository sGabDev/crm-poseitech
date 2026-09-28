<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailQueue
{
    public function send(int $id, Company $company, bool $retry = false): string
    {
        $query = DB::table('email_logs')->where('company_id', $company->id)->where('id', $id);
        $email = (clone $query)->firstOrFail();
        if (! $company->available() || ! $company->smtp) {
            return 'indisponível';
        }
        $customer = $email->customer_id ? DB::table('customers')->where('company_id', $company->id)->find($email->customer_id) : null;
        if (($email->campaign_id && ! $company->enabled('campaigns')) || ($email->customer_id && (! $customer || $customer->anonymized_at || ($email->campaign_id && ! $customer->email_consent)))) {
            (clone $query)->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);

            return 'cancelled';
        }
        if (! (clone $query)->whereIn('status', $retry ? ['pending', 'failed'] : ['pending'])->update(['status' => 'sending', 'attempts' => DB::raw('attempts+1'), 'updated_at' => now()])) {
            return $email->status;
        }
        try {
            $smtp = $company->smtp;
            $mailer = Mail::build(['transport' => 'smtp', 'host' => $smtp['host'], 'port' => $smtp['port'], 'username' => $smtp['username'] ?? null, 'password' => $smtp['password'] ?? null, 'scheme' => $smtp['encryption'] === 'ssl' ? 'smtps' : 'smtp', 'require_tls' => true, 'timeout' => 15]);
            $mailer->send('emails.message', ['body' => $email->body, 'companyName' => $company->name], function ($m) use ($email, $smtp) {
                $m->to($email->recipient)->from($smtp['from'], $smtp['from_name'])->subject($email->subject);
            });
            $query->update(['status' => 'sent', 'error' => null, 'sent_at' => now(), 'updated_at' => now()]);

            return 'sent';
        } catch (Throwable $e) {
            $query->update(['status' => 'failed', 'error' => 'Falha no envio SMTP. Verifique conexão e credenciais.', 'updated_at' => now()]);

            return 'failed';
        }
    }
}
