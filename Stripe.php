<?php namespace Model\Stripe;

use Model\Core\Module;
use Model\Payments\PaymentInterface;
use Model\Payments\PaymentsOrderInterface;

class Stripe extends Module implements PaymentInterface
{
	public function beginPayment(PaymentsOrderInterface $order, string $type, array $options = [])
	{
		switch ($type) {
			case 'client':
				$config = $this->retrieveConfig();

				\Stripe\Stripe::setApiKey($config['secret-key']);

				$options = array_merge(['currency' => 'eur'], $options);
				$options = array_merge($options, ['amount' => $order->getPrice() * 100]);
				$options['metadata']['orderId'] = $order['id'];

				$intent = \Stripe\PaymentIntent::create($options);

				return [
					'secret' => $intent->client_secret,
				];

			case 'server':
				$options = array_merge([
					'name' => $order->getOrderDescription(),
					'description' => '',
					'images' => [],
					'currency' => 'eur',
					'quantity' => 1,
					'amount' => $order->getPrice(),
					'metadata' => [],
				], $options);

				$options['metadata']['orderId'] = $order['id'];

				$options['amount'] *= 100;
				if ((int)$options['amount'] === 0)
					$this->model->error('Nothing to pay');

				if (!$options['description'])
					unset($options['description']);
				if (!$options['images'])
					unset($options['images']);

				$config = $this->retrieveConfig();

				\Stripe\Stripe::setApiKey($config['secret-key']);

				$metadata = $options['metadata'];
				unset($options['metadata']);

				$session = \Stripe\Checkout\Session::create([
					'payment_method_types' => ['card'],
					'line_items' => [
						$options,
					],
					'payment_intent_data' => [
						'metadata' => $metadata,
					],
					'success_url' => BASE_HOST . PATH . $config['success-path'],
					'cancel_url' => BASE_HOST . PATH . $config['cancel-path'],
				]);
				?>
				<script src="https://js.stripe.com/v3/" type="text/javascript"></script>
				<script>
					window.addEventListener('load', function () {
						let stripe = Stripe('<?=$config['publishable-key']?>');
						stripe.redirectToCheckout({
							sessionId: '<?=$session['id']?>'
						}).then(function (result) {
							if (typeof result.error.message !== 'undefined' && result.error.message)
								alert(result.error.message);
						});
					});
				</script>
				<?php
				die();
		}
	}

	/**
	 * For JS flow:
	 * Renders the stripe card input in the page and build Stripe token in real time
	 */
	public function renderForm()
	{
		$config = $this->retrieveConfig();
		?>
		<style>
			.StripeElement {
				box-sizing: border-box;

				height: 40px;

				padding: 10px 12px;

				border: 1px solid transparent;
				border-radius: 4px;
				background-color: white;

				box-shadow: 0 1px 3px 0 #e6ebf1;
				-webkit-transition: box-shadow 150ms ease;
				transition: box-shadow 150ms ease;
			}

			.StripeElement--focus {
				box-shadow: 0 1px 3px 0 #cfd7df;
			}

			.StripeElement--invalid {
				border-color: #fa755a;
			}

			.StripeElement--webkit-autofill {
				background-color: #fefde5 !important;
			}
		</style>

		<div class="form-row">
			<div id="card-element">
				<!-- A Stripe Element will be inserted here. -->
			</div>

			<!-- Used to display form errors. -->
			<div id="card-errors" role="alert"></div>

			<input type="hidden" name="stripeToken" id="stripeToken"/>
		</div>

		<script>
			var stripe, stripeCard;
			window.addEventListener('load', function () {
				stripe = Stripe('<?=$config['publishable-key']?>');
				var elements = stripe.elements();

				// Custom styling can be passed to options when creating an Element.
				// (Note that this demo uses a wider set of styles than the guide below.)
				var style = {
					base: {
						color: '#32325d',
						fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
						fontSmoothing: 'antialiased',
						fontSize: '16px',
						'::placeholder': {
							color: '#aab7c4'
						}
					},
					invalid: {
						color: '#fa755a',
						iconColor: '#fa755a'
					}
				};

				// Create an instance of the card Element.
				stripeCard = elements.create('card', {style: style});

				// Add an instance of the card Element into the `card-element` <div>.
				stripeCard.mount('#card-element');

				// Handle real-time validation errors from the card Element.
				stripeCard.addEventListener('change', function (event) {
					var displayError = document.getElementById('card-errors');
					if (event.error) {
						displayError.textContent = event.error.message;
					} else {
						displayError.textContent = '';

						// Generate the token Id
						stripe.createToken(stripeCard).then(function (result) {
							if (result.error) {
								// Inform the user if there was an error.
								displayError.textContent = result.error.message;
							} else {
								document.getElementById('stripeToken').value = result.token.id;
							}
						});
					}
				});
			});
		</script>
		<?php
	}

	public function handleRequest(): array
	{
		ini_set('display_errors', '1');

		set_error_handler(function ($errno, $errstr, $errfile, $errline, $errcontext = false) {
			http_response_code(500);
			return false;
		});

		$config = $this->retrieveConfig();
		\Stripe\Stripe::setApiKey($config['secret-key']);

		// If you are testing your webhook locally with the Stripe CLI you
		// can find the endpoint's secret by running `stripe listen`
		// Otherwise, find your endpoint's secret in your webhook settings in the Developer Dashboard
		$endpoint_secret = $config['webhook-secret'];

		$payload = file_get_contents('php://input');
		$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];

		try {
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
			}

			// Handle the event
			switch ($event->type) {
				/*case 'checkout.session.completed':
					$stripeObject = $event->data->object;
					if (!$stripeObject->payment_intent)
						throw new \Exception('No payment intent found');

					$paymentIntent = \Stripe\PaymentIntent::retrieve($stripeObject->payment_intent);
					break;*/
				case 'payment_intent.succeeded':
					$paymentIntent = $event->data->object;
					break;
				default:
					http_response_code(400);
					echo 'Unexpected event type';
					die();
			}
		} catch (\Throwable $e) {
			http_response_code(500);
			echo getErr($e);
			die();
		}

		$meta = $paymentIntent->metadata->toArray();

		return [
			'id' => $meta['orderId'],
			'price' => $paymentIntent->amount / 100,
			'meta' => $meta,
		];
	}
}
