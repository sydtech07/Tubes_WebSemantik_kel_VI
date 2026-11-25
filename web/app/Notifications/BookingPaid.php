<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingPaid extends Notification
{
    public function __construct(public Booking $booking)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;

        return (new MailMessage)
            ->subject('Receipt pembayaran CINÉLOG #'.$booking->id)
            ->greeting('Halo, '.$notifiable->name)
            ->line('Pembayaran tiketmu sudah kami terima.')
            ->line('Nomor order: '.$booking->midtrans_order_id)
            ->line('Kursi: '.$booking->seats->pluck('seat_code')->join(', '))
            ->line('Total: Rp '.number_format($booking->total_amount, 0, ',', '.'))
            ->action('Lihat receipt', route('bookings.receipt', $booking));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'message' => 'Pembayaran booking #'.$this->booking->id.' berhasil. Receipt sudah tersedia.',
            'url' => route('bookings.receipt', $this->booking),
        ];
    }
}
