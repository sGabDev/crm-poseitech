<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPassword extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        // Use the configured application origin, never the incoming Host header.
        $url = rtrim(config('app.url'), '/').route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);

        return (new MailMessage)
            ->subject('Redefina sua senha · PoseiTech CRM')
            ->greeting('Olá!')
            ->line('Recebemos uma solicitação para redefinir a senha da sua conta PoseiTech CRM.')
            ->action('Definir nova senha', $url)
            ->line('Este link expira em '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutos e só pode ser utilizado uma vez.')
            ->line('Se você não solicitou esta alteração, ignore este e-mail. Sua senha continuará a mesma.')
            ->salutation('Equipe PoseiTech');
    }
}
