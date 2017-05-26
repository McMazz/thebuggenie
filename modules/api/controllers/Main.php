<?php

namespace thebuggenie\modules\api\controllers;

use b2db\Criteria;
use thebuggenie;
use thebuggenie\core\entities;
use thebuggenie\core\entities\Issue;
use thebuggenie\core\entities\Project;
use thebuggenie\core\entities\tables;
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
		$limit = intval($request['limit']);
		if ($limit == 0)
		{
			$limit = 5;
		}
		foreach($this->getUser()->getAssociatedProjects() as $project)
		{
			foreach($project->getRecentActivities($limit,false,null,true) as $activities)
			{
				foreach ($activities as $activity)
				{
					if(!in_array($activity["target"], $issues_ids))
					{
						$issues_ids[] = $activity["target"];
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
	
	public function runListIssuesByProject(Request $request)
	{
		$issues = [];
		$project_id = $request['project_id'];	//Si e' scelto di non fare il controllo sulla validita' per velocizzare la query
		$limit = $request['limit'];
		$offset = $request['offset'];
		if($limit == null)
		{
			$limit = 0;
		}
		if($offset == null)
		{
			$offset = 0;
		}
		$issues_table = tables\Issues::getTable();
		$crit = $issues_table->getCriteria();
		$crit->addWhere(tables\Issues::PROJECT_ID, $project_id);
		$crit->addOrderBy(tables\Issues::LAST_UPDATED, Criteria::SORT_DESC);
		$crit->setLimit($limit);
		$crit->setOffset($offset);
		foreach ($issues_table->select($crit) as $issue)
		{
			$issues[] = $issue->toJSON(false);
		}
		return $this->json($issues);
	}
	
	public function runListTeams(Request $request)
	{
		$teams = [];		
		foreach ($this->getUser()->getTeams() as $team)
		{
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
	
	protected function isAdmin(){
		$is_admin = false;
		$current_user_id = Context::getUser()->getID();
		foreach (entities\Group::getAll() as $group)
		{
			if($group->getName() == "Administrators")
			{
				$administrators = $group->getMembers();
				foreach ($administrators as $admin)
				{
					if($admin->getID() == $current_user_id)
					{
						$is_admin = true;
						break;
					}
				}
			}
		}
		return $is_admin;
	}
	
	public function runListTimeSpentBetweenDates(Request $request)
	{
		$issues = [];
		$current_user_id = thebuggenie\core\framework\Context::getUser()->getID();
		$is_admin = $this->isAdmin();
		if (!$is_admin)
		{
			return $this->json(['error' => "You don't have administrative privileges."], Response::HTTP_STATUS_FORBIDDEN);
		}
		$date_from = trim($request['date_from']);
		$date_to = trim($request['date_to']);
		$time_spent_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $time_spent_table->getCriteria();
		$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_from, $crit::DB_GREATER_THAN_EQUAL);
		$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_to, $crit::DB_LESS_THAN_EQUAL);
		foreach ($time_spent_table->select($crit) as $activity)
		{
			$time_spent_data = tables\IssueSpentTimes::getTable()->getSpentTimeSumsByIssueId($activity->getIssueID());
			$issues[] = ["id" => $activity->getIssueID(),"username" => $activity->getUser()->getUsername(),"points" => $time_spent_data['points'],"hours" => intval($time_spent_data['hours']),"minutes" =>  $time_spent_data['minutes']];
		}
		return $this->json($issues);
	}
	
	public function runListOptionsByCustomItemType(Request $request)
	{
		$custom_options = [];
		$item_type = trim($request['item_type']);
		$custom_options_table = tables\CustomFieldOptions::getTable();
		$crit = $custom_options_table->getCriteria();
		foreach (entities\tables\CustomFieldOptions::getTable() as $opt)
		{
			$custom_options[] = $option->toJSON(false);
		}
		return $this->json($custom_options);
	}

	public function runListEditions(Request $request)
	{
		$editions = [];
		$project_id = trim($request['project_id']);
		$editions_table = entities\tables\Editions::getTable();
 		$crit = $editions_table->getCriteria();
 		$crit->addWhere(tables\Editions::PROJECT, $project_id);
		foreach ($editions_table->select($crit) as $edition)
		{
			$edition_entity = entities\Edition::getB2DBTable()->selectById($edition->getID());
			$owner = null;
			$leader = null;
			$qa_responsible_user = null;
			if($edition->getLeader() != null)
			{
				$leader = $edition->getLeader()->getID();
			}
			if($edition->getOwner() != null)
			{
				$owner = $edition->getOwner()->getID();
			}
			if($edition->getQaresponsible() != null)
			{
				$qa_responsible_user = $edition->getQaresponsible()->getID();
			}
			$editions[] = ["id" => $edition->getID(),"name" => $edition_entity->getName(), "description" => $edition_entity->getDescription(), "leader" => $leader, "owner" => $owner, "qa_responsible" => $qa_responsible_user];
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
		foreach ($components_table->select($crit) as $component)
		{
			$component_entity = entities\Component::getB2DBTable()->selectById($component->getID());
			$owner = null;
			$leader = null;
			$qa_responsible_user =null;
			if($component->getLeader() != null)
			{
				$leader = $component->getLeader()->getID();
			}
			if($component->getOwner() != null)
			{
				$owner = $component->getOwner()->getID();
			}
			if($component->getQaresponsible() != null)
			{
				$qa_responsible_user = $component->getQaresponsible()->getID();
			}
			$components[] = ["id" => $component->getID(),"name" => $component_entity->getName(), "leader" => $leader, "owner" => $owner, "qa_responsible" => $qa_responsible_user];
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
			if($insert_obj->issue != null)
			{
				return $this->json(['errors' => $insert_obj->errors, 'permission_errors' => $insert_obj->permission_errors], Response::HTTP_STATUS_BAD_REQUEST);
			}
			return $this->json($insert_obj->issue->toJSON(false));
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
			foreach ($issues_table->select($crit) as $issue)
			{
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
		foreach ($issues_table->select($crit) as $issue)
		{
			$recent_issues[]	= $issue;
		}
		foreach ($recent_issues as $issue)
		{
			if(!in_array($issue->getID(), $parsed_issues))
			{
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
		foreach ($project->getIssueTypeScheme()->getIssuetypes() as $issue_type)
		{
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
		$custom_fields = tables\CustomFields::getTable()->getAll();
		$custom_fields_keys = [];
		foreach ($custom_fields as $field)
		{
			$custom_fields_keys[] = strtolower($field->getName());
		}
		while ($row = $rows->getNextRow())
		{
			$field_key = $row->get(tables\IssueFields::FIELD_KEY);
			$is_custom = false;
			$field_name;
			if(!in_array($field_key, $custom_fields_keys))
			{
				$field_name = str_replace("_", " ", ucfirst($field_key));
				$field_name = $i18n->__($field_name);
			}else
			{
				$field_name = $custom_fields[$field_key]->getName();
				$is_custom = true;
			}
			$required = $this->stringToBoolean($row->get(tables\IssueFields::REQUIRED));
			$reportable = $this->stringToBoolean($row->get(tables\IssueFields::REPORTABLE));
			$additional = $this->stringToBoolean($row->get(tables\IssueFields::ADDITIONAL));
			if($is_custom)
			{
				$options = $this->getListOptionsByCustomItemType($field_key);
			}else
			{
				$options = $this->getListOptionsByItemType($field_key);
			}
			$fields[] = ["id" => $row->get(tables\IssueFields::ID), "name" => $field_name, "key" => $field_key ,"required" => $required, "reportable" => $reportable, "additional" => $additional, "options" => $options];
		}
		return $this->json($fields);
	}
	
	protected function getListOptionsByItemType($item_type)
	{
		$options = [];
		foreach (entities\tables\ListTypes::getTable()->getAllByItemType($item_type) as $option)
		{
			$options[] = $option->toJSON(false);
		}
		return $options;
	}
	
	protected function getListOptionsByCustomItemType($item_type)
	{
		$options = [];
		$custom_fields = tables\CustomFields::getTable()->getAll();
		$custom_fields_ids = [];
		foreach ($custom_fields as $custom_field)
		{
			$custom_fields_ids[$custom_field->getKey()] = $custom_field->getItemType();
		}
		$custom_fields_options_table = tables\CustomFieldOptions::getTable();
		$crit = $custom_fields_options_table->getCriteria();
		$crit->addWhere(tables\CustomFieldOptions::CUSTOMFIELD_ID, $custom_fields_ids[$item_type]);
		foreach ($custom_fields_options_table->select($crit) as $option)
		{
			$options[] = $option->toJSON(false);
		}
		return $options;
	}
	
	protected function stringToBoolean($value)
	{
		return $value == "1"? true : false;
	}
	
	public function runListStarredIssues(Request $request)
	{
		$starred_issues = [];
		$user = $this->getUser();
		$starredissues = \thebuggenie\core\entities\tables\UserIssues::getTable()->getUserStarredIssues($user->getID());
                ksort($starredissues, SORT_NUMERIC);
		foreach ($starredissues as $starred_issue)
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
				'starred' => $retval,
				'count' => count($issue->getSubscribers())
		]);
	}
	
	public function runListActivityTypes(Request $request)
	{
		return $this->json($this->getListOptionsByItemType("activitytype"));
	}
	
	public function runActivities(Request $request)
	{
		$issue_id = trim($request['issue_id']);
		if($request->isPost())
		{
			$spenttime = new \thebuggenie\core\entities\IssueSpentTime();
			$spenttime->setIssue($issue_id);
			if (isset($request['username'])){
				if(entities\User::getByUsername(trim($request['username'])) != null){
					$user_id = entities\User::getByUsername(trim($request['username']))->getID();
					if($user_id != $this->getUser()->getID() && !$this->isAdmin()){
						return $this->json(['error' => "You don't have administrative privileges."], Response::HTTP_STATUS_FORBIDDEN);
					}
					$spenttime->setUser($user_id);
				}else{
					return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
				}
			}else{
				$spenttime->setUser(\thebuggenie\core\framework\Context::getUser());
			}
			$spenttime->setSpentPoints($request['spentPoints']);
			$spenttime->setSpentMinutes($request['spentMinutes']);
			$spenttime->setSpentHours($request['spentHours']);
			$spenttime->setSpentDays($request['spendDays']);
			$spenttime->setSpentWeeks($request['spentWeeks']);
			$spenttime->setSpentMonths($request['spentMonths']);
			$spenttime->setActivityType($request['activityTypeId']);
			$spenttime->setEditedAt($request['inserted']);
			$spenttime->setComment($request['comment']);
			$spenttime->save();
			return $this->json($this->getFieldsActivityByID($spenttime->getID()));
		}
		else{
			$activities = [];
			$time_spent_table = entities\IssueSpentTime::getB2DBTable();
			$crit = $time_spent_table->getCriteria();
			$crit->addWhere(tables\IssueSpentTimes::ISSUE_ID, $issue_id);
			if (isset($request['username'])){
				if(entities\User::getByUsername(trim($request['username'])) != null){
					$user_id = entities\User::getByUsername(trim($request['username']))->getID();
					if($user_id != $this->getUser()->getID() && !$this->isAdmin()){
						return $this->json(['error' => "You don't have administrative privileges."], Response::HTTP_STATUS_FORBIDDEN);
					}
					$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $user_id);
				}else{
					return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
				}
			}
			foreach ($time_spent_table->select($crit) as $issue)
			{
				$activities[] = $this->getFieldsActivityByID($issue->getID());
			}
			return $this->json($activities);
		}
	}
	
	public function runListActivities(Request $request) {
		$date_from = trim($request['date_from']);
		$date_to = trim($request['date_to']);
		if($date_from != "" && $date_to != ""){
			$activities = [];
			$time_spent_table = entities\IssueSpentTime::getB2DBTable();
			$crit = $time_spent_table->getCriteria();
			if (isset($request['username'])){
				if(entities\User::getByUsername(trim($request['username'])) != null)
				{
					$user_id = entities\User::getByUsername(trim($request['username']))->getID();
					$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $user_id);
				}else
				{
					return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
				}
			}else{
				$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $this->getUser()->getID());
			}
			if(isset($request['issue_id'])){
				$crit->addWhere(tables\IssueSpentTimes::ISSUE_ID, trim($request['issue_id']));
			}
			$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_from, $crit::DB_GREATER_THAN_EQUAL);
			$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_to, $crit::DB_LESS_THAN_EQUAL);
			foreach ($time_spent_table->select($crit) as $activity)
			{
				$activities[] = $this->getFieldsActivityByID($activity->getID());
			}
			return $this->json($activities);
		}
		else{
			$issue_id = trim($request['issue_id']);
			if(isset($issue_id)){
				$activities = [];
				$time_spent_table = entities\IssueSpentTime::getB2DBTable();
				$crit = $time_spent_table->getCriteria();
				if (isset($request['username'])){
					if(entities\User::getByUsername(trim($request['username'])) != null)
					{
						$user_id = entities\User::getByUsername(trim($request['username']))->getID();
						$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $user_id);
					}else
					{
						return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
					}
				}else{
					$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $this->getUser()->getID());
				}
				$crit->addWhere(tables\IssueSpentTimes::ISSUE_ID, $issue_id);
				foreach ($time_spent_table->select($crit) as $activity)
				{
					$activities[] = $this->getFieldsActivityByID($activity->getID());
				}
				return $this->json($activities);
			}else{
				return $this->json(['error' => "Bad Request"], Response::HTTP_STATUS_BAD_REQUEST);
			}
			
		}
		
	}
	
	public function runMoveActivity(Request $request){
		$issue_id = trim($request['issue_id']);
		if (isset($request['issue_id'])) return $this->json(["error" => "issue_id required"], Response::HTTP_STATUS_BAD_REQUEST);
		$activity_id = trim($request['activity_id']);
		$activity_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $activity_table->getCriteria();
		$crit->addWhere(entities\tables\IssueSpentTimes::ID, $activity_id);
		$activity = $activity_table->selectOne($crit);
		if($activity == null)
		{
			return $this->json(["error" => "id ". $activity_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$issues_tables = entities\Issue::getB2DBTable();
		$crit = $issues_tables->getCriteria();
		$crit->addWhere(tables\Issues::ID, $issue_id);
		$issue = $issues_tables->selectOne($crit);
		if($issue == null)
		{
			return $this->json(["error" => "issue_id ". $issue_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		if (!$this->isAdmin())
		{
			if($activity->getUser() != Context::getUser())
			{
				return $this->json(["error" => "This activity doesn't belong to you."], Response::HTTP_STATUS_FORBIDDEN);
			}
		}
		$modified_activity = $this->modifyActivity($activity, $issue_id);
		return $this->json($modified_activity->toJSON(false));
	}
	
	public function runModifyActivity(Request $request)
	{
		$activity_id = trim($request['activity_id']);
		$issue_id = trim($request['issue_id']);
		$activity_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $activity_table->getCriteria();
		$crit->addWhere(entities\tables\IssueSpentTimes::ID, $activity_id);
		$activity = $activity_table->selectOne($crit);
		if($activity == null)
		{
			return $this->json(["error" => "id ". $activity_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$issues_tables = entities\Issue::getB2DBTable();
		$crit = $issues_tables->getCriteria();
		$crit->addWhere(tables\Issues::ID, $issue_id);
		$issue = $issues_tables->selectOne($crit);
		if($issue == null)
		{
			return $this->json(["error" => "issue_id ". $issue_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		if (!$this->isAdmin())
		{
			if($activity->getUser() != Context::getUser())
			{
				return $this->json(["error" => "This activity doesn't belong to you."], Response::HTTP_STATUS_FORBIDDEN);
			}
		}
		if (isset($request['username'])){
			if(entities\User::getByUsername(trim($request['username'])) != null)
			{
				$user_id = entities\User::getByUsername(trim($request['username']))->getID();
			}else
			{
				return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
			}
		}else{
			return $this->json(['error' => "Please provide a username"], Response::HTTP_STATUS_BAD_REQUEST);
				
		}
		$modified_activity = $this->modifyActivity($activity,$issue->getID(),$user_id,$request['spentMonths'],$request['spentWeeks'],$request['spendDays'],$request['spentHours'],$request['spentMinutes'],$request['spentPoints'],$request['comment']);
		return $this->json($modified_activity->toJSON(false));
	}
	
	protected function modifyActivity($activity , $issue_id = null, $user_id = null , $months = null,$weeks = null,$days = null,$hours = null,$minutes = null,$points = null,$comment = null){
		$modified_activity = $activity;
		if(isset($issue_id)) $modified_activity->setIssue(trim($issue_id));
		if(isset($user_id)) $modified_activity->setUser(trim($user_id));
		if(isset($months)) $modified_activity->setSpentMonths(trim($months));
		if(isset($weeks)) $modified_activity->setSpentWeeks(trim($weeks));
		if(isset($days)) $modified_activity->setSpentDays(trim($days));
		if(isset($hours)) $modified_activity->setSpentHours(trim($hours));
		if(isset($minutes)) $modified_activity->setSpentMinutes(trim($minutes));
		if(isset($points)) $modified_activity->setSpentPoints(trim($points));
		if(isset($comment)) $modified_activity->setComment(trim($comment));
		$modified_activity->save();
		return $modified_activity;
	}

	protected function getFieldsActivityByID($activity_id)
	{
		$activities_table = tables\IssueSpentTimes::getTable();
		$crit2 = $activities_table->getCriteria();
		$crit2->addWhere(tables\IssueSpentTimes::ID, $activity_id);
		$activity = $activities_table->selectOne($crit2);
		$edited_at = $activity->getEditedAt();
		$user =  $activity->getUser()->getUsername();
		$issue_id = $activity->getIssueID();
		$spent_months = $activity->getSpentMonths();
		$spent_weeks = $activity->getSpentWeeks();
		$spent_days = $activity->getSpentDays();
		$spent_hours = $activity->getSpentHours();
		$spent_minutes = $activity->getSpentMinutes();
		$spent_points = $activity->getSpentPoints();
		$activity_type_id = $activity->getActivityTypeID();
		if($activity_type_id != 0){
			$listTypeTable = entities\tables\ListTypes::getTable();
			$crit3 = $listTypeTable->getCriteria();
			$crit3->addWhere(entities\tables\ListTypes::ID, $activity_type_id);
			$result = $listTypeTable->selectOne($crit3);
			$activity_type_desc = $result->getName();
		}else{
			$activity_type_id = 0;
			$activity_type_desc = "";
		}
		$comment = $activity->getComment();
		$fields = ["id" => $activity_id, "username" => $user,"inserted" => $edited_at,"issue_id" => $issue_id,"issue_no" => $this->getIssueNoParsed($issue_id), "spent_months" =>$spent_months,"spent_weeks" => $spent_weeks,"spent_days" => $spent_days,"spent_hours" => $spent_hours, "spent_minutes" => $spent_minutes,"spent_points" => $spent_points, "comment" => $comment, "activity_type_id" => $activity_type_id, "activity_type_desc" => $activity_type_desc];
		return $fields;
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
	
	protected function getIssueNoParsed($issue_id){
		$issue = entities\Issue::getB2DBTable()->selectById($issue_id);
		$issue_No = $issue->getIssueNo();
		$project_prefix = entities\Project::getB2DBTable()->selectById($issue->getProjectID())->getPrefix();
		return $project_prefix . "-" . $issue_No;
	}
	
}
