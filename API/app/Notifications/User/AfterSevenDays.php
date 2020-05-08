<?php

namespace App\Notifications\User;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AfterSevenDays extends Notification implements ShouldQueue
{
    use Queueable;

    private $user;
    public $tries = 3;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        if (
            $this->user->isOld(7 * 24)
            && $this->user->channels->count() <=2
            && !\App\Models\Notification::existsForUser($this->user->id, "App\Notifications\User\AfterSevenDays")
        ) {
            return ['database', 'mail'];
        } else {
            return [];
        }
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $username = $this->user->name;
        return (new MailMessage)
            ->view('emails.user.after_seven_days', [ 'user' => $username])
            ->subject('Already one week together!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
