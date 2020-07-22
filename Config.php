<?php namespace Model\Stripe;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/**
	 */
	protected function assetsList()
	{
		$this->addAsset('config', 'config.php', function () {
			return '<?php
$config = [
	\'path\' => \'stripe\',
	\'publishable-key\' => \'\',
	\'secret-key\' => \'\',
	\'success-path\' => \'payment-success\',
	\'cancel-path\' => \'payment-cancel\',
	\'webhook-secret\' => \'\',
	\'handle-payment\' => function (array $metaData, float $amount) {
	},
];
';
		});
	}

	/**
	 * @return array
	 */
	public function getRules(): array
	{
		$config = $this->retrieveConfig();

		return [
			'rules' => [
				'stripe' => $config['path'],
			],
			'controllers' => [
				'Stripe',
			],
		];
	}
}
