<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions\Tests;

use Creagia\Redsys\Enums\Environment;
use Creagia\Redsys\Exceptions\DeniedRedsysPaymentResponseException;
use Creagia\Redsys\Exceptions\ErrorRedsysResponseException;
use Creagia\Redsys\Exceptions\InvalidRedsysResponseException;
use Creagia\Redsys\RedsysClient;
use Creagia\Redsys\RedsysRequest;
use Creagia\Redsys\RedsysResponse;
use Creagia\Redsys\Support\NotificationParameters;
use Creagia\Redsys\Support\PostRequestError;
use MarioDevv\RedsysSubscriptions\ChargeOutcome;
use MarioDevv\RedsysSubscriptions\RedsysGateway;
use MarioDevv\RedsysSubscriptions\Subscription;

/**
 * Como se traduce lo que contesta Redsys a una decision sobre la suscripcion.
 *
 * Es la mitad del paquete y hasta ahora no tenia ni una asercion: los demas
 * tests sustituyen chargeStoredCard() entero, asi que estas tres ramas —error
 * de peticion, pago denegado y error de respuesta— no se ejecutaban nunca.
 */
final class GatewayResponseTest extends TestCase
{
    /** Un Redsys que contesta lo que se le diga sin salir a la red. */
    private function gateway(RedsysResponse|PostRequestError|\Throwable $answer): RedsysGateway
    {
        return new class ('999008881', 'sq7HjrUOBfKmC576ILgskD5srU870gJ7', 1, false, $answer) extends RedsysGateway {
            public function __construct(string $merchantCode, string $secretKey, int $terminal, bool $production, private readonly mixed $answer)
            {
                parent::__construct($merchantCode, $secretKey, $terminal, $production);
            }

            protected function send(RedsysRequest $request): RedsysResponse|PostRequestError
            {
                if ($this->answer instanceof \Throwable) {
                    throw $this->answer;
                }

                return $this->answer;
            }
        };
    }

    /** Una respuesta de Redsys que falla al comprobarse, como la de verdad. */
    private function failingResponse(\Throwable $onCheck): RedsysResponse
    {
        return new class (new RedsysClient(999008881, 'k', 1, Environment::Test), $onCheck) extends RedsysResponse {
            public function __construct(RedsysClient $client, private readonly \Throwable $onCheck)
            {
                parent::__construct($client);
            }

            public function checkResponse(): NotificationParameters
            {
                throw $this->onCheck;
            }
        };
    }

    private function subscription(): Subscription
    {
        return Subscription::create([
            'billable_type'      => 'App\\Models\\User',
            'billable_id'        => 1,
            'amount_in_cents'    => 1500,
            'card_token'         => 'referencia-guardada',
            'cof_transaction_id' => '2609141031500',
            'status'             => Subscription::ACTIVE,
        ]);
    }

    /**
     * SIS0042 es una firma mal calculada: tu configuracion, no la tarjeta del
     * titular. Tratarlo como denegacion cancela la cartera entera en tres pases.
     */
    public function test_a_request_error_is_read_as_a_merchant_error(): void
    {
        $result = $this->gateway(new PostRequestError('SIS0042', 'firma mal'))
            ->chargeStoredCard($this->subscription(), '260914000001');

        $this->assertSame(ChargeOutcome::MerchantError, $result->outcome);
        $this->assertSame('SIS0042', $result->code);
    }

    /** Pedido repetido: el choque es nuestro, no del titular. No gasta intento. */
    public function test_a_repeated_order_is_read_as_transient(): void
    {
        $result = $this->gateway(new PostRequestError('SIS0051', 'pedido repetido'))
            ->chargeStoredCard($this->subscription(), '260914000001');

        $this->assertSame(ChargeOutcome::Unavailable, $result->outcome);
    }

    public function test_a_denied_payment_keeps_its_response_code(): void
    {
        $result = $this->gateway($this->failingResponse(new DeniedRedsysPaymentResponseException('0180', 'denegada')))
            ->chargeStoredCard($this->subscription(), '260914000001');

        $this->assertSame(ChargeOutcome::Declined, $result->outcome);
        $this->assertSame('0180', $result->code, 'sin el codigo, el panel no puede decir por que fallo');
    }

    /** 0195 no es un rechazo de tarjeta: el emisor pide autenticar esa cuota. */
    public function test_sca_required_is_not_a_denial(): void
    {
        $result = $this->gateway($this->failingResponse(new DeniedRedsysPaymentResponseException('0195', 'sca')))
            ->chargeStoredCard($this->subscription(), '260914000001');

        $this->assertSame(ChargeOutcome::ScaRequired, $result->outcome);
    }

    /** SIS0321: Redsys ha matado la referencia. Reintentar es tirar peticiones. */
    public function test_a_dead_reference_is_read_as_a_dead_card(): void
    {
        $result = $this->gateway($this->failingResponse(new ErrorRedsysResponseException('SIS0321', 'referencia muerta')))
            ->chargeStoredCard($this->subscription(), '260914000001');

        $this->assertSame(ChargeOutcome::TokenDead, $result->outcome);
    }

    /**
     * Una firma que no cuadra no es un cobro denegado: es una respuesta en la
     * que no se puede confiar. Tragarsela seria aceptar datos sin autenticar,
     * asi que sube y el comando la registra sin anotar nada.
     */
    public function test_an_unauthentic_response_is_not_turned_into_an_outcome(): void
    {
        $this->expectException(InvalidRedsysResponseException::class);

        $this->gateway($this->failingResponse(new InvalidRedsysResponseException('firma que no cuadra')))
            ->chargeStoredCard($this->subscription(), '260914000001');
    }

    public function test_an_authorised_charge_carries_the_response_code(): void
    {
        $response = new class (new RedsysClient(999008881, 'k', 1, Environment::Test)) extends RedsysResponse {
            public function checkResponse(): NotificationParameters
            {
                return new NotificationParameters(
                    amount: 1500, currency: 978, order: '260914000001',
                    merchantCode: '999008881', terminal: 1,
                    responseCode: '0000', securePayment: '1',
                );
            }
        };

        $result = $this->gateway($response)->chargeStoredCard($this->subscription(), '260914000001');

        $this->assertSame(ChargeOutcome::Authorized, $result->outcome);
        $this->assertSame('0000', $result->code);
    }
}
