<?php

declare(strict_types=1);

namespace MarioDevv\RedsysSubscriptions;

use Creagia\Redsys\Enums\{CofType, Currency, Environment, ExcepSca, TransactionType};
use Creagia\Redsys\RedsysClient;
use Creagia\Redsys\RedsysRequest;
use Creagia\Redsys\Exceptions\DeniedRedsysPaymentResponseException;
use Creagia\Redsys\Exceptions\ErrorRedsysResponseException;
use Creagia\Redsys\Support\PostRequestError;
use Creagia\Redsys\Support\RequestParameters;

/**
 * Lo minimo que necesitamos de Redsys. El protocolo lo pone creagia/redsys-php.
 */
class RedsysGateway
{
    public function __construct(
        private readonly string $merchantCode,
        private readonly string $secretKey,
        private readonly int $terminal,
        private readonly bool $production = false,
    ) {}

    private function client(): RedsysClient
    {
        return new RedsysClient(
            // creagia los quiere numericos y aqui hay strict_types: sin el cast
            // es un TypeError en el primer cobro, no un fallo de Redsys.
            merchantCode: (int) $this->merchantCode,
            secretKey:    $this->secretKey,
            terminal:     $this->terminal,
            environment:  $this->production ? Environment::Production : Environment::Test,
        );
    }

    /**
     * Formulario del pago inicial. Pide a Redsys que guarde la tarjeta (COF).
     * El titular tiene que estar presente y pasar el 3DS.
     */
    public function cardRegistrationForm(int $amountInCents, string $order, string $urlOk, string $urlKo, ?string $notifyUrl = null): string
    {
        return RedsysRequest::create($this->client(), new RequestParameters(
            amountInCents:   $amountInCents,
            transactionType: TransactionType::Autorizacion,
            currency:        Currency::EUR,
            order:           $order,
            merchantUrl:     $notifyUrl,
            urlOk:           $urlOk,
            urlKo:           $urlKo,
        ))->requestingCardToken(cofType: CofType::Recurring)
          ->getRedirectFormHtml();
    }

    /**
     * Cobro sin el titular delante, con la referencia guardada.
     */
    public function chargeStoredCard(Subscription $subscription, string $order): ChargeOutcome
    {
        $request = RedsysRequest::create($this->client(), new RequestParameters(
            amountInCents:   $subscription->amount_in_cents,
            transactionType: TransactionType::Autorizacion,
            currency:        Currency::EUR,
            order:           $order,
            // Sin esto Redsys exige SCA y el cobro se cae. creagia no lo fija
            // en usingCardToken(), hay que pasarlo aqui.
            excepSca:        ExcepSca::MerchantInitiatedTransaction->value,
        ))->usingCardToken(
            cofType:            CofType::Recurring,
            cofTransactionId:   (string) $subscription->cof_transaction_id,
            merchantIdentifier: (string) $subscription->card_token,
        );

        $response = $request->sendPostRequest();

        if ($response instanceof PostRequestError) {
            return ChargeOutcome::fromRedsys(null, $response->code);
        }

        try {
            // checkResponse() ademas verifica la firma de la respuesta.
            $response->checkResponse();

            return ChargeOutcome::Authorized;
        } catch (DeniedRedsysPaymentResponseException $e) {
            return ChargeOutcome::fromRedsys($e->redsysCode);
        } catch (ErrorRedsysResponseException $e) {
            return ChargeOutcome::fromRedsys(null, $e->redsysCode);
        }

        // InvalidRedsysResponseException se deja propagar a proposito: una firma
        // que no cuadra no es un cobro denegado, es una respuesta en la que no
        // se puede confiar. Tragarsela seria aceptar datos sin autenticar.
    }
}
