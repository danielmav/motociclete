<?php

declare(strict_types=1);

namespace App\Newsletter\Webhook;

/**
 * Traduce notificarea unui releu de email în evenimentele pe care le înțelege
 * modulul. Există o implementare per furnizor.
 */
interface Provider
{
    public function name(): string;

    /**
     * @return array{confirm_url:?string, events:array<int,array{type:string,email:string,message_id:?string,detail:string}>}
     *         `type`: bounce_hard, bounce_soft, complaint sau delivery.
     *         `confirm_url`: adresa de vizitat pentru confirmarea abonării la notificări, dacă e cazul.
     */
    public function parse(string $body): array;
}
