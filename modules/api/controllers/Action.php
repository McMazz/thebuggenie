<?php

namespace thebuggenie\modules\api\controllers;

use thebuggenie\core\framework\Action as FrameworkAction;
use thebuggenie\core\framework\Logging;

class Action extends FrameworkAction
{
	protected function json(array $data = [], $code = null)
	{
		if($code != null)
		{
			$this->getResponse()->setHttpStatus(intval($code));
		}
		return $this->renderJSON($data);
	}

	protected function log($message, $level = Logging::LEVEL_INFO)
	{
		Logging::log($message, 'api', $level);
	}

	protected function info($message)
	{
		$this->log($message);
	}

	protected function notice($message)
	{
		$this->log($message, Logging::LEVEL_NOTICE);
	}

	protected function warn($message)
	{
		$this->log($message, Logging::LEVEL_WARNING);
	}

	protected function risk($message)
	{
		$this->log($message, Logging::LEVEL_WARNING_RISK);
	}

	protected function fatal($message)
	{
		$this->log($message, Logging::LEVEL_FATAL);
	}
}