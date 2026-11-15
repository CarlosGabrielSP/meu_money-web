<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DefinirSenhaNotification extends Notification
{
    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Email with the link that lets an account created by an administrator set its password (RN-102).
     */
    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);
        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Sua conta no '.config('app.name').' foi criada')
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Um administrador criou uma conta para você.')
            ->action('Definir minha senha', $url)
            ->line("Este link expira em {$minutos} minutos. Depois disso, use \"Esqueci minha senha\" na tela de login.");
    }
}
