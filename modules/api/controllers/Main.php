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
use b2db\Criteria;
use thebuggenie;

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
	
	public function runListIssuesByRecentActivities(Request $request)
	{
		$issues_JSON = [];
		$issues_ids= [];
		$project_id = trim($request['project_id']);
		$limit = intval($request['limit']);
		if($limit == 0){
			$limit = 10;
		}
		foreach($this->getUser()->getAssociatedProjects() as $project){
			if($project->getID() == $project_id){
				foreach($project->getRecentActivities($limit,false,null,true) as $activities)
				{
					foreach ($activities as $activity){
						if(!in_array($activity["target"], $issues_ids)){
							$issues_ids[] = $activity["target"];
						}
					}
				}
			}
		}
		foreach ($issues_ids as $issue)
		{
			$issues_JSON[] = entities\Issue::getB2DBTable()->selectById($issue)->toJSON(false);
		}
		return $this->json($issues_JSON);
	}
	
	public function runListTeams(Request $request)
	{
		$teams = [];		
		foreach ($this->getUser()->getTeams() as $team){
			$teams[] = $team->toJSON(false);
		}
		return $this->json($teams);
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
	
	protected function getItemsByType($item_type){
		$items = [];
		foreach (entities\tables\ListTypes::getTable()->getAllByItemType($item_type) as $item)
		{
			$items[] = $item->toJSON(false);
		}
		return $items;
	}

	public function runListSeverities(Request $request)
	{
		return $this->json($this->getItemsByType(entities\Datatype::SEVERITY));
	}
	
	public function runListPriorities(Request $request)
	{
		return $this->json($this->getItemsByType(entities\Datatype::PRIORITY));
	}

	public function runListEditions(Request $request)
	{
		$editions = [];
		$project_id = trim($request['project_id']);
		$editions_table = entities\tables\Editions::getTable();
 		$crit = $editions_table->getCriteria();
 		$crit->addWhere(tables\Editions::PROJECT, $project_id);
		foreach ($editions_table->select($crit) as $edition){
			$edition_entity = entities\Edition::getB2DBTable()->selectById($edition->getID());
			$owner = "";
			$leader = "";
			$qa_responsible_user = "";
			if($edition->getLeader() != null){
				$leader = $edition->getLeader()->getID();
			}
			if($edition->getOwner() != null){
				$owner = $edition->getOwner()->getID();
			}
			if($edition->getQaresponsible() != null){
				$qa_responsible_user = $edition->getQaresponsible()->getID();
			}
			$editions[] = array(tables\Editions::ID => $edition->getID(),tables\Editions::NAME => $edition_entity->getName(), tables\Editions::DESCRIPTION => $edition_entity->getDescription(), tables\Editions::LEAD_BY => $leader, tables\Editions::OWNED_BY => $owner, tables\Editions::QA => $qa_responsible_user);
		}
		return $this->json($editions);
	}
	
	public function runListComponents(Request $request)
	{
		$components = [];
		$project_id = trim($request['project_id']);
		$components_table = entities\tables\Components::getTable();
		$crit = $components_table->getCriteria();
		$crit->addWhere(tables\Components::PROJECT, $project_id);
		foreach ($components_table->select($crit) as $component){
			$component_entity = entities\Component::getB2DBTable()->selectById($component->getID());
			$owner = "";
			$leader = "";
			$qa_responsible_user = "";
			if($component->getLeader() != null){
				$leader = $component->getLeader()->getID();
			}
			if($component->getOwner() != null){
				$owner = $component->getOwner()->getID();
			}
			if($component->getQaresponsible() != null){
				$qa_responsible_user = $component->getQaresponsible()->getID();
			}
			$components[] = array(tables\Components::ID => $component->getID(),tables\Components::NAME => $component_entity->getName(), tables\Components::LEAD_BY => $leader, tables\Components::B2DBNAME . "owner" => $owner, tables\Components::B2DBNAME . "qa_responsible" => $qa_responsible_user);
		}
		return $this->json($components);
	}
	
	public function runIssues(Request $request)
	{
		if($request->isPost())
		{
			$insert_obj = new \thebuggenie\core\modules\main\controllers\Main;
			$insert_obj->preExecute($request,"reportIssue");
			$insert_obj->runReportIssue($request);
			if(!empty($insert_obj->errors))
			{
				return $this->json(['errors' => $insert_obj->errors], Response::HTTP_STATUS_BAD_REQUEST);
			}
			if(!empty($insert_obj->permission_errors))
			{
				return $this->json(['errors' => $insert_obj->permission_errors], Response::HTTP_STATUS_FORBIDDEN);
			}
			if($insert_obj->issue == NULL)
			{
				return $this->json(['error' => 'Something went wrong.'], 500);
			}
			return $this->json($insert_obj->issue->toJSON());
		}
		else
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
			$ret_issues = [];
			foreach ($issues[0] as $issue)
			{
				$ret_issues[] = $issue->toJSON(false);
			}
			return $this->json($ret_issues);
		}
	}
	
	public function runListUserRelatedIssues(Request $request)
	{
		$recent_issues_JSON = [];
		$recent_issues = [];
		$parsed_issues = [];
		$user_teams = $this->getUser()->getTeams();
		$user_id = $this->getUser()->getID();
		$limit = intval($request['limit']);
		if($limit == 0)
		{
			$limit = 10;
		}
		$issues_table = tables\Issues::getTable();
		foreach ($user_teams as $team)
		{
			$crit = $issues_table->getCriteria();
			$crit->addWhere(tables\Issues::DELETED, false);
			$crit->addWhere(tables\Issues::ASSIGNEE_TEAM, $team->getID());
			$crit->addOr(tables\Issues::POSTED_BY, $user_id);
			$crit->addOr(tables\Issues::BEING_WORKED_ON_BY_USER, $user_id);
			$crit->addOr(tables\Issues::ASSIGNEE_USER, $user_id);
			$crit->addOr(tables\Issues::OWNER_USER, $user_id);
			$crit->addOrderBy(tables\Issues::LAST_UPDATED, Criteria::SORT_DESC);
			$crit->setLimit($limit);
			foreach ($issues_table->select($crit) as $issue){
				$recent_issues[]	= $issue;
			}
		}
		$crit = $issues_table->getCriteria();
		$crit->addWhere(tables\Issues::DELETED, false);
		$crit->addWhere(tables\Issues::POSTED_BY, $user_id);
		$crit->addOr(tables\Issues::BEING_WORKED_ON_BY_USER, $user_id);
		$crit->addOr(tables\Issues::ASSIGNEE_USER, $user_id);
		$crit->addOr(tables\Issues::OWNER_USER, $user_id);
		$crit->addOrderBy(tables\Issues::LAST_UPDATED, Criteria::SORT_DESC);
		$crit->setLimit($limit);
		foreach ($issues_table->select($crit) as $issue){
			$recent_issues[]	= $issue;
		}
		foreach ($recent_issues as $issue){
			if(!in_array($issue->getID(), $parsed_issues)){
				$recent_issues_JSON[] = $issue->toJSON(false);
				$parsed_issues[] = $issue->getID();
			}
		}
		return $this->json($recent_issues_JSON);
	}

	public function runListAssignedIssues(Request $request)
	{
		$assigned_issues = [];
		$user = $this->getUser();
		foreach ($user->getUserAssignedIssues() as $issue)
		{
			$assigned_issues[] = $issue->toJSON(false);
		}
		return $this->json($assigned_issues);
	}
	
	public function runListIssueTypes(Request $request)
	{
		$issue_types = [];
		$project_id = trim($request['project_id']);
		$project = entities\Project::getB2DBTable()->selectByID($project_id);
		foreach ($project->getIssueTypeScheme()->getIssuetypes() as $issue_type){
			$issue_types[] = $issue_type->toJSON(false);
		}
		return $this->json($issue_types);
	}
	
	public function runListFieldsByIssueType(Request $request)
	{
		$fields = [];
		$i18n = Context::getI18n();
		$project_id = trim($request['project_id']);
		$issue_type = intval($request['issue_type']);
		$project = entities\Project::getB2DBTable()->selectByID($project_id);
		$issue_type_scheme_id = $project->getIssuetypeScheme()->getID();
		$rows =  tables\IssueFields::getTable()->getBySchemeIDandIssuetypeID($issue_type_scheme_id,$issue_type);
		while ($row = $rows->getNextRow()){
			$field_key = str_replace("_", " ", ucfirst($row->get(tables\IssueFields::FIELD_KEY)));
			$required = strcmp($row->get(tables\IssueFields::REQUIRED), 1) == 0 ? true : false;
			$reportable = $row->get(tables\IssueFields::REPORTABLE);
			$additional = $row->get(tables\IssueFields::ADDITIONAL);
			$scope = $row->get(tables\IssueFields::SCOPE);
			$fields[] = array(tables\IssueFields::FIELD_KEY => $i18n->__($field_key),tables\IssueFields::REQUIRED => $required,tables\IssueFields::REPORTABLE => $reportable,tables\IssueFields::ADDITIONAL => $additional,tables\IssueFields::SCOPE => $scope);
		}
		return $this->json($fields);
	}

	public function runListStarredIssues(Request $request)
	{
		$starred_issues = [];
		$user = $this->getUser();
		foreach ($user->getStarredIssues() as $starred_issue)
		{
			$starred_issues[] = entities\Issue::getB2DBTable()->selectById($starred_issue)->toJSON(false);
		}
		return $this->json($starred_issues);
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
				'starred' => $retval ? "true" : "false",
				'count' => count($issue->getSubscribers())
		]);
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
}