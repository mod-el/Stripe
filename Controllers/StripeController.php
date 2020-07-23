<?php namespace Model\Stripe\Controllers;

use Model\Core\Controller;

class StripeController extends Controller
{
	public function index()
	{
		ini_set('display_errors', '1');

		set_error_handler(function ($errno, $errstr, $errfile, $errline, $errcontext = false) {
			http_response_code(500);
			return false;
		});

		switch ($this->model->getRequest(1)) {
			case 'hook':
				$config = $this->model->_Stripe->retrieveConfig();
				\Stripe\Stripe::setApiKey($config['secret-key']);

				// If you are testing your webhook locally with the Stripe CLI you
				// can find the endpoint's secret by running `stripe listen`
				// Otherwise, find your endpoint's secret in your webhook settings in the Developer Dashboard
				$endpoint_secret = $config['webhook-secret'];

				$payload = file_get_contents('php://input');
				$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
				$event = null;

				echo "Signature: " . $sig_header . "\n";
				try {
					$event = \Stripe\Webhook::constructEvent(
						$payload, $sig_header, $endpoint_secret
					);
				} catch (\UnexpectedValueException $e) {
					// Invalid payload
					http_response_code(400);
					echo "Invalid payload\n";
					echo getErr($e);
					exit();
				} catch (\Stripe\Exception\SignatureVerificationException $e) {
					// Invalid signature
					http_response_code(400);
					echo "Invalid signature\n";
					echo getErr($e);
					exit();
				} catch (\Exception $e) {
					echo "Generic request error\n";
					echo getErr($e);
					exit();
				}

				// Handle the event
				switch ($event->type) {
					case 'checkout.session.completed':
						$stripeObject = $event->data->object;
						try {
							if (!$stripeObject->payment_intent)
								throw new \Exception('No payment intent found');

							$paymentIntent = \Stripe\PaymentIntent::retrieve($stripeObject->payment_intent);

							$config['handle-payment']($paymentIntent->metadata->toArray(), $stripeObject->amount_total / 100);
						} catch (\Exception $e) {
							http_response_code(500);
							echo getErr($e);
						}
						break;
					default:
						http_response_code(400);
						echo 'Unexpected event type';
				}
				break;
			default:
				http_response_code(400);
				echo 'Unknown action';
				break;
		}

		die();
	}
}
