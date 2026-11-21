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
     * (RN-271, RN-289); a card invitation speaks of the card and its invoice (RN-328).
     */
    public function toMail(User $notifiable): MailMessage
    {
        $nome = $this->carteira->nome;
        $dono = $this->dono->name;
        $noCartao = $this->carteira->isCartao();

        return (new MailMessage)
            ->subject(($noCartao ? "Convite para o cartão {$nome}" : "Convite para a carteira {$nome}").' no '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line($noCartao
                ? "{$dono} convidou você para lançar no cartão {$nome} em nome dele. A fatura continua só de {$dono}."
                : "{$dono} convidou você para lançar na carteira {$nome} em nome dele. O saldo continua só de {$dono}.")
            ->action('Ver convites', route('carteiras.index'));
    }
}
