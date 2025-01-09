<?php namespace Model\Stripe;

use Model\Core\Module_Config;

class Config extends Module_Config
{
	/**
	 */
	protected function assetsList(): void
	{
		$this->addAsset('config', 'config.php', function () {
			return '<?php
$config = [
	\'publishable-key\' => \'\',
	\'secret-key\' => \'\',
	\'success-path\' => \'payment-success\',
	\'cancel-path\' => \'payment-cancel\',
	\'webhook-secret\' => \'\',
];
';
		});
	}

	public function getConfigData(): ?array
	{
		return [];
	}
}
