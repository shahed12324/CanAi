<?php

declare(strict_types=1);

namespace App\Billing;

interface PaymentGateway
{
    public function code(): string;

    public function label(): string;

    /** @return list<string> */
    public function currencies(): array;

    /** Opens a payment session at the gateway and returns where to send the customer. */
    public function initiate(OrderInfo $order, Urls $urls, Customer $customer): Initiation;

    /**
     * Reads a callback (customer return or IPN/webhook) just far enough to find the order. Nothing in here is trusted yet.
     *
     * @param  array<string, mixed>  $input  query + form + decoded JSON, merged
     * @param  array<string, string>  $headers  lower-cased names
     * @return array{order: ?string, ref: ?string}|null  null = not one of ours / not a usable event
     *
     * @throws GatewayFailure when a signature is present and wrong
     */
    public function locate(array $input, array $headers, string $body): ?array;

    /**
     * Asks the gateway itself whether the payment really happened, and for how much. Never believes the callback's own claims.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     *
     * @throws GatewayFailure when the gateway cannot be reached
     */
    public function confirm(array $input, array $headers, string $body, OrderInfo $order): Confirmation;
}
