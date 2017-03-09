<?php

namespace thebuggenie\modules\api\controllers;

use thebuggenie\core\framework;
use thebuggenie\core\entities;
use thebuggenie\core\entities\tables;

class Main extends framework\Action
{
	public function getAuthenticationMethodForAction($action)
	{
		switch ($action)
		{
			case 'auth':
				return framework\Action::AUTHENTICATION_METHOD_DUMMY;
				break;
			default:
				return framework\Action::AUTHENTICATION_METHOD_APPLICATION_PASSWORD;
				break;
		}
	}

	public function runAuth(framework\Request $request)
	{
		framework\Logging::log('Authenticating new application password.', 'api', framework\Logging::LEVEL_INFO);
		$username = trim($request['username']);
		$password = trim($request['password']);
		if ($username)
		{
			$user = tables\Users::getTable()->getByUsername($username);
			if ($password && $user instanceof entities\User)
			{
				// Generate token from the application password
				$token = entities\ApplicationPassword::createToken($password);
				// Crypt, for comparison with db value
				$hashed_token = entities\User::hashPassword($token, $user->getSalt());
				foreach ($user->getApplicationPasswords() as $app_password)
				{
					// Only return the token for new application passwords!
					if (!$app_password->isUsed())
					{
						if ($app_password->getHashPassword() == $hashed_token)
						{
							$app_password->useOnce();
							$app_password->save();
							return $this->renderJSON([ 'api_username' => $username, 'api_token' => $token ]);
						}
					}
				}
			}
			framework\Logging::log('No password matched.', 'api', framework\Logging::LEVEL_INFO);
		}
		$this->getResponse()->setHttpStatus(400);
		return $this->renderJSON([ 'error' => 'Incorrect username or application password' ]);
	}

	public function runTest(framework\Request $request)
	{
		return $this->renderJSON([ 'message' => 'This is a test' ]);
	}
}