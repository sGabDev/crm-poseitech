<?php

namespace App\Services;

use InvalidArgumentException;
use Throwable;

class CompanySmtp
{
    public static function config(array $smtp): array
    {
        $host = preg_replace('#^(?:smtp|smtps|ssl|tls)://#i', '', trim($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 0);
        if (! $host || preg_match('#[\s/?:]#', $host) || $port < 1 || $port > 65535 || ! filter_var($smtp['from'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('SMTP_CONFIG');
        }
        $scheme = $port === 465 ? 'smtps' : ($port === 587 ? 'smtp' : (($smtp['encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp'));
        $password = $smtp['password'] ?? null;
        if (in_array(strtolower($host), ['smtp.gmail.com', 'smtp.googlemail.com']) && is_string($password)) {
            $password = preg_replace('/\s+/', '', $password);
        }

        return ['transport' => 'smtp', 'host' => $host, 'port' => $port, 'username' => trim($smtp['username'] ?? ''), 'password' => $password,
            'scheme' => $scheme, 'require_tls' => true, 'auto_tls' => true, 'timeout' => 20, 'local_domain' => substr(strrchr($smtp['from'], '@'), 1)];
    }

    public static function failure(Throwable $error): string
    {
        // Classify without persisting the SMTP transcript, credentials or message body.
        $message = strtolower($error->getMessage());

        return match (true) {
            str_contains($message, 'smtp_config') => 'Configuração SMTP inválida: confira servidor (sem URL), porta e e-mail remetente.',
            str_contains($message, 'auth'), str_contains($message, '535'), str_contains($message, '534') => 'Autenticação SMTP recusada. Confira o usuário (e-mail completo) e a senha da caixa postal; se necessário, use uma senha de aplicativo.',
            str_contains($message, 'certificate'), str_contains($message, 'crypto'), str_contains($message, 'ssl'), str_contains($message, 'starttls'), str_contains($message, 'tls') => 'Falha na conexão segura SMTP. Confira servidor e porta: 465 usa SSL/TLS; 587 usa STARTTLS. Verifique o certificado e o OpenSSL da hospedagem.',
            str_contains($message, 'timed out'), str_contains($message, 'timeout'), str_contains($message, 'connection'), str_contains($message, 'getaddrinfo'), str_contains($message, 'network') => 'Não foi possível conectar ao SMTP. Confira servidor, porta e bloqueios de rede da hospedagem.',
            str_contains($message, '550'), str_contains($message, '553'), str_contains($message, 'sender'), str_contains($message, 'recipient'), str_contains($message, 'relay') => 'Servidor recusou remetente ou destinatário. Use um remetente autorizado pela conta SMTP e confira o destinatário.',
            str_contains($message, 'quota'), str_contains($message, 'limit'), str_contains($message, '452'), str_contains($message, '421') => 'Limite ou indisponibilidade temporária do servidor SMTP. Aguarde e tente novamente.',
            default => 'Falha ao preparar ou enviar o e-mail. Contate o suporte com o ID da mensagem e a categoria registrada no log.',
        };
    }
}
