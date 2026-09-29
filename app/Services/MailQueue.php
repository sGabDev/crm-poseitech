<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $reward = DB::table('coupon_grants')->where('company_id', $company->id)->where('email_log_id', $id)->first();
        if ($reward) {
            $coupon = DB::table('coupons')->where('company_id', $company->id)->find($reward->coupon_id);
            if ($reward->revoked_at || $reward->used_sale_id || ! $coupon || $coupon->deleted_at || ! $coupon->active || $coupon->expires_at < today()->toDateString()) {
                (clone $query)->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);

                return 'cancelled';
            }
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
            $mailer = Mail::build(CompanySmtp::config($smtp));
            $view = 'emails.message';
            $data = ['body' => $email->body, 'companyName' => $company->name];
            if ($email->sale_id) {
                $sale = DB::table('sales')->where('company_id', $company->id)->where('id', $email->sale_id)->firstOrFail();
                $data = ['sale' => $sale, 'company' => $company, 'customer' => $customer, 'items' => DB::table('sale_items')->where('company_id', $company->id)->where('sale_id', $sale->id)->get(), 'payments' => DB::table('payments')->where('company_id', $company->id)->where('sale_id', $sale->id)->get()];
                $view = ['html' => 'emails.receipt', 'text' => 'emails.receipt-text'];
            }
            $mailer->send($view, $data, function ($m) use ($email, $smtp) {
                $m->to($email->recipient)->from($smtp['from'], $smtp['from_name'] ?? '')->subject($email->subject);
            });
            $query->update(['status' => 'sent', 'error' => null, 'sent_at' => now(), 'updated_at' => now()]);

            return 'sent';
        } catch (Throwable $e) {
            $reason = CompanySmtp::failure($e);
            $query->update(['status' => 'failed', 'error' => $reason, 'updated_at' => now()]);
            Log::warning('company.smtp_failed', ['company_id' => $company->id, 'email_id' => $id, 'category' => $reason, 'exception_class' => get_class($e)]);

            return 'failed';
        }
    }
}
