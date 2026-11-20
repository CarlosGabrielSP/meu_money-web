<?php

namespace App\Notifications;

use App\Models\Carteira;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConviteMembroNotification extends Notification
{
    public function __construct(public User $dono, public Carteira $carteira) {}

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Email telling the invited user about a member invitation, with a link to the wallets page
     * (RN-271, RN-289).
     */
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Convite para a carteira {$this->carteira->nome} no ".config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line("{$this->dono->name} convidou você para lançar na carteira {$this->carteira->nome} em nome dele. O saldo continua só de {$this->dono->name}.")
            ->action('Ver convites', route('carteiras.index'));
    }
}
