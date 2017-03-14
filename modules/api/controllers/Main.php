<?php

namespace thebuggenie\modules\api\controllers;

use thebuggenie\core\entities;
use thebuggenie\core\entities\tables;
use thebuggenie\core\entities\Issue;
use thebuggenie\core\entities\Issuetype;
use thebuggenie\core\entities\Project;
use thebuggenie\core\framework\Context;
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

	/**
	 * 
	 * @param string $username
	 * @param string $password
	 * @return entities\User|false
	 */
	protected function authenticateUser($username, $password)
	{
		$mod = Context::getModule(Settings::getAuthenticationBackend());
		$user = $mod->doLogin($username, $password);
		if (!$user->isActivated())
		{
			return false;
		}
		elseif (!$user->isEnabled())
		{
			return false;
		}
		elseif(!$user->isConfirmedMemberOfScope(Context::getScope()))
		{
			if (!framework\Settings::isRegistrationAllowed())
			{
				return false;
			}
		}
		return $user;
	}

	/**
	 * 
	 * @param entities\User $user
	 * @param string $token_name
	 * @return string|false
	 */
	protected function createApplicationPassword($user, $token_name)
	{
		foreach($user->getApplicationPasswords() as $app_password)
		{
			if($app_password->getName() === $token_name)
			{
				return false;
			}
		}
		$password = new entities\ApplicationPassword();
		$password->setUser($user);
		$password->setName($token_name);
		$visible_password = strtolower(entities\User::createPassword());
		$password->setPassword($visible_password);
		$password->save();
		return entities\ApplicationPassword::createToken($visible_password);
	}

	public function runAuth(Request $request)
	{
		if(!isset($request['username'], $request['password'], $request['token_name'])) {
			return $this->json(['error' => 'Username, password, and token name are required.'], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$username = trim($request['username']);
		$password = trim($request['password']);
		$user = $this->authenticateUser($username, $password);
		if($user === false)
		{
			return $this->json(['error' => 'Authentication forbidden.'], Response::HTTP_STATUS_FORBIDDEN);
		}
		$token_name = trim($request['token_name']);
		$token = $this->createApplicationPassword($user, $token_name);
		if($token === false)
		{
			return $this->json(['error' => 'Token name already in use.'], Response::HTTP_STATUS_BAD_REQUEST);
		}
		return $this->json(['token' => $token]);
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
	
	public function runListOffices(Request $request)
	{
		$offices = [];
		
		$project_id = trim($request['project_id']);
		$componentsTable = tables\Components::getTable();
		$crit = $componentsTable->getCriteria();
		$crit->addWhere(tables\Components::PROJECT, $project_id);
		$components = $componentsTable->select($crit, false);
		
		foreach ($components as $component){
			$offices[] = $component->toJSON(false);
		}
		
		return $this->json($offices);
	}

	public function runToggleFavouriteIssue(Request $request)
	{
		if ($issue_id = trim($request['issue_id']))
		{
			try
			{
				$issue = entities\Issue::getB2DBTable()->selectById($issue_id);
			}
			catch (\Exception $e)
			{
				return $this->json(['error' => 'Errore nello svolgimento della richiesta.'], 500);
			}
		}
		else
		{
			return $this->json(['error' => 'No issue found with id "'.$issue_id.'"'], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$user = $this->getUser();
		if ($user->isIssueStarred($issue_id))
		{
			$retval = !$user->removeStarredIssue($issue_id);
		}
		else
		{
			$retval = $user->addStarredIssue($issue_id);
		}
		return $this->json([
				'starred' => $retval ? "true" : "false", // Action::renderJSON turns everything into strings... @TODO: change ::json implementation
				'count' => count($issue->getSubscribers())
		]);
	}
	
	public function runListIssues(Request $request)
	{
		$text = trim($request['search']);
		$limit = intval($request['paginate']);
		$offset = intval($request['page']);
		if($limit == 0)
		{
			$limit = 10;
		}
		if($offset != 0)
		{
			$offset -= 1;
			$offset *= $limit;
		}
		$filters = ['text' => entities\SearchFilter::createFilter('text', ['v' => $text, 'o' => '='])];
		$issues = entities\Issue::findIssues($filters, $limit, $offset);
		$retIssues = [];
		foreach ($issues[0] as $issue)
		{
			$retIssues[] = $issue->toJSON(false);
		}
		return $this->json($retIssues);
	}

	public function runListRecentIssues(Request $request)
	{
		$recentIssues = [];
		$issuetype_ids = entities\Issuetype::getAll();
	
		foreach ($this->getUser()->getAssociatedProjects() as $project)
		{
			foreach ($issuetype_ids as $issuetype_id){
				foreach($project->getRecentIssues($issuetype_id) as $issue)
				{
					$recentIssues[] = $issue->toJSON(false);
				}
			}
		}
		
		return $this->json($recentIssues);
	}

	public function runListAssignedIssues(Request $request)
	{
		$assignedIssues = [];
		$user = $this->getUser();
		
		foreach ($user->getUserAssignedIssues() as $issue)
		{
			$assignedIssues[] = $issue->toJSON(false);
		}
		
		return $this->json($assignedIssues);
	}

	public function runListStarredIssues(Request $request)
	{
		$starredissues = [];
		$user = $this->getUser();

		foreach ($user->getStarredIssues() as $starredIssue)
		{
			$starredissues[] = $starredIssue->toJSON(false);
		}
		
		return $this->json($starredissues);
	}

	/*
	 * @param project_id
	 */
	public function runListIssueTypes(Request $request)
	{
		$issuetypes = [];

		$project_id = trim($request['project_id']);
		$project = entities\Project::getB2DBTable()->selectByID($project_id);
		
		foreach ($project->getIssueTypeScheme()->getIssuetypes() as $issueType){
			$issuetypes[] = $issueType->toJSON(false);
		}
	
		return $this->json($issuetypes);
	}
	
	public function runListClients(Request $request)
	{
		$clients = [];
	
		$project_id = trim($request['project_id']);
		$editionsTable = tables\Editions::getTable();
		$crit = $editionsTable->getCriteria();
		$crit->addWhere(tables\Editions::PROJECT, $project_id);
		$editions = $editionsTable->select($crit, false);
	
		foreach ($editions as $edition){
			$clients[] = $edition->toJSON(true);
		}
	
		return $this->json($clients);
	}
}