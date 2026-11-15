<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConviteContatoNotification extends Notification
{
    public function __construct(public User $solicitante) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Email telling the invited user about a contact invitation, with a link to the invitations (RN-066).
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Convite de contato no '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line("{$this->solicitante->name} quer adicionar você como contato para dividir lançamentos.")
            ->action('Ver convites', route('contatos.index'))
            ->line('Se não conhece essa pessoa, basta recusar o convite.');
    }
}
