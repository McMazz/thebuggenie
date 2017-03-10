<?php

namespace thebuggenie\modules\api\controllers;

use thebuggenie\core\entities;
use thebuggenie\core\entities\tables;
use thebuggenie\core\framework\Request;
use thebuggenie\core\framework\Response;
use thebuggenie\core\framework\Settings;

class Main extends Action
{
	public function getAuthenticationMethodForAction($action)
	{
		switch ($action)
		{
			case 'health':
			case 'auth':
				return self::AUTHENTICATION_METHOD_DUMMY;
				break;
			default:
				return self::AUTHENTICATION_METHOD_APPLICATION_PASSWORD;
				break;
		}
	}

	public function runHealth(Request $request)
	{
		if(Settings::isMaintenanceModeEnabled())
		{
			return $this->json([
					'status' => 'MAINTENANCE',
					'message' => Settings::getMaintenanceMessage() ?: 'Maintenance mode enabled'
			], 500);
		}
		return $this->json(['status' => 'OK']);
	}

	public function runAuth(Request $request)
	{
		$this->info('Authenticating new application password.');
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
							return $this->json([
									'api_username' => $username,
									'api_token' => $token
							]);
						}
					}
				}
			}
			$this->warn('No password matched.');
		}
		return $this->json(['error' => 'Incorrect username or application password'], Response::HTTP_STATUS_BAD_REQUEST);
	}

	public function runMe(Request $request)
	{
		$user = $this->getUser()->toJSON();
		return $this->json($user);
	}

	public function runListProjects(Request $request)
	{
		$projects = [];
		foreach ($this->getUser()->getAssociatedProjects() as $project)
		{
			if ($project->isDeleted()) continue;
			$projects[] = $project->toJSON(false);
		}
		return $this->json($projects);
	}
}