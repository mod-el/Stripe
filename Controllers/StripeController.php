<?php namespace Model\Stripe\Controllers;

use Model\Core\Controller;

class StripeController extends Controller
{
	public function index()
	{
		switch ($this->model->getRequest(1)) {
			case 'hook':
				$config = $this->model->_Stripe->retrieveConfig();
				\Stripe\Stripe::setApiKey($config['secret-key']);

				// If you are testing your webhook locally with the Stripe CLI you
				// can find the endpoint's secret by running `stripe listen`
				// Otherwise, find your endpoint's secret in your webhook settings in the Developer Dashboard
				$endpoint_secret = $config['webhook-secret'];

				$payload = @file_get_contents('php://input');
				$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
				$event = null;

				try {
					$event = \Stripe\Webhook::constructEvent(
						$payload, $sig_header, $endpoint_secret
					);
				} catch (\UnexpectedValueException $e) {
					// Invalid payload
					http_response_code(400);
					exit();
				} catch (\Stripe\Exception\SignatureVerificationException $e) {
					// Invalid signature
					http_response_code(400);
					exit();
				}

				// Handle the event
				switch ($event->type) {
					case 'checkout.session.completed':
						$stripeObject = $event->data->object;
						try {
							$config['handle-payment']($stripeObject->client_reference_id ?? $stripeObject->metadata ?? null, $stripeObject->amount);
						} catch (\Exception $e) {
							http_response_code(500);
							echo getErr($e);
						}
						break;
					default:
						// Unexpected event type
						http_response_code(400);
				}
				break;
			default:
				http_response_code(400);
				break;
		}

		die();
	}
}
