<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** A message inside the system about a teaching plan (review comment, approval, submission). */
class TeachingNotice extends Notification
{
    public function __construct(public string $key, public array $params, public string $url)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        // the key is translated when it is read, in the reader's language
        return ['key' => $this->key, 'params' => $this->params, 'url' => $this->url];
    }
}
