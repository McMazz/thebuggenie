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
		return $this->json([
			'status' => 'OK',
			'message' => 'The Server is fully functional'
		]);
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
		$includeClosed = $request['includeClosed'];
		if($includeClosed == null){
			$includeClosed = false;
		}else{
			$includeClosed = $includeClosed === 'true'? true: false;
		}
		if ($limit == 0)
		{
			$limit = 5;
		}
		$activities = [];
		$time_spent_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $time_spent_table->getCriteria();
		$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $this->getUser()->getID());
		$crit->addOrderBy(tables\IssueSpentTimes::EDITED_AT, Criteria::SORT_DESC);
		$distinctIssueIdFound = 0;
		$distinctIssueIds = [];
		$activityIDs = [];
		foreach ($time_spent_table->select($crit) as $activity)
		{
			if(!in_array($activity->getIssueID(), $distinctIssueIds)){
				$distinctIssueIds[] = $activity->getIssueID();
				$activityIDs[] = [$activity->getEditedAt() => $activity->getID()];
				$distinctIssueIdFound++;
			}
			if ($distinctIssueIdFound >= $limit){
				break;
			}
		}
		ksort($activityIDs);
		foreach ($activityIDs as $activityID){
			$values = $this->getFieldsIssueByActivityID($activityID, $includeClosed);
			if($values != null){
				$activities[] = $values;
			}
		}
			
		return $this->json($activities);
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

	public function runListUserProjects(Request $request)
	{
		$projects = [];
		$p = entities\Project::getAllRootProjects(false);
		foreach ($p as $project)
		{
			if ($project->hasAccess($this->getUser()))
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
		$userSpentTimeTable = [];
		$date_from = trim($request['date_from']);
		$date_to = trim($request['date_to']);
		$time_spent_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $time_spent_table->getCriteria();
		$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_from, $crit::DB_GREATER_THAN_EQUAL);
		$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_to, $crit::DB_LESS_THAN_EQUAL);
		foreach ($time_spent_table->select($crit) as $activity)
		{
			$issue = $this->getIssueByID($activity->getIssueID());
			$activity_type_id = $activity->getActivityTypeID();
			if($activity_type_id != 0){
				$listTypeTable = entities\tables\ListTypes::getTable();
				$crit3 = $listTypeTable->getCriteria();
				$crit3->addWhere(entities\tables\ListTypes::ID, $activity_type_id);
				$crit3->
				$result = $listTypeTable->selectOne($crit3);
				$activity_type_desc = $result->getName();
			}else{
				$activity_type_id = 0;
				$activity_type_desc = "";
			}
			$issues[] = ["id" => $activity->getID(),"issue_id" => $activity->getIssueID(),"issue_no" => $issue->getFormattedIssueNo() ,"href" => $this->getIssueHrefByID($activity->getIssueID()),"username" => $activity->getUser()->getUsername(),"spent_points" => $activity->getSpentPoints(),"spent_months" => $activity->getSpentMonths(),"spent_weeks" => $activity->getSpentWeeks(),"spent_days" => $activity->getSpentDays(),"spent_hours" => $activity->getSpentHours(),"spent_minutes" =>  $activity->getSpentMinutes(),"comment" => $activity->getComment(), "inserted" => $activity->getEditedAt(), "activity_type_id" => $activity_type_id, "activity_type_desc" => $activity_type_desc];
	}
		return $this->json($issues);
	}
	
	public function runListProjects(Request $request){
		$projects = [];
		foreach (entities\Project::getAll() as $project){
			
			$projects[] = $project->toJSON(false);
		}
		return $this->json($projects);
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
			$limit = intval($request['limit']);
			$offset = intval($request['page']);
			if($offset != 0)
			{
				$offset -= 1;
				$offset *= $limit;
			}
			$counter = 0;
			$issues= entities\Issue::findIssuesByText($text);
			$ret_issues = [];
			foreach ($issues[0] as $issue)
			{
				$ret_issues[] = $issue->toJSON(false);
				$counter++;
				if($limit != 0 && $counter == $limit){
					break;
				}
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
		$project_id = trim($request['project_id']);
		$project = entities\Project::getB2DBTable()->selectByID($project_id);
		$fields_array = $project->getReportableFieldsArray($request['issue_type'], true);
		$available_fields = entities\DatatypeBase::getAvailableFields();
		$available_fields[] = 'pain_bug_type';
		$available_fields[] = 'pain_likelihood';
		$available_fields[] = 'pain_effect';
		return $this->json(['available_fields' => $available_fields, 'fields' => $fields_array]);
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

	protected function getNames($item_type)
	{
		foreach (entities\tables\ListTypes::getTable()->getAllByItemType($item_type) as $option)
		{
			$options[] = $option->getName();
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
		foreach ($starredissues as $starred_issue_id)
		{
			$starred_issue = entities\Issue::getB2DBTable()->selectById($starred_issue_id);
			if(!$starred_issue->isClosed()){
				$starred_issues[] = entities\Issue::getB2DBTable()->selectById($starred_issue_id)->toJSON(false);
			}
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
		if (!isset($request['issue_id'])) return $this->json(["error" => "issue_id required"], Response::HTTP_STATUS_BAD_REQUEST);
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
		$modified_activity = $this->modifyActivity($activity, $issue_id);
		return $this->json($modified_activity->toJSON(false));
	}
	
	public function runDeleteActivity(Request $request){
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
		$crit = $activity_table->getCriteria();
		$crit->addWhere(entities\tables\IssueSpentTimes::ID, $activity_id);
		$activity_table->doDelete($crit);
		return $this->json(["message" => "success"]);
	}
	
	public function runModifyActivity(Request $request)
	{
		$activity_id = trim($request['activity_id']);
		$activity_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $activity_table->getCriteria();
		$crit->addWhere(entities\tables\IssueSpentTimes::ID, $activity_id);
		$activity = $activity_table->selectOne($crit);
		if($activity == null)
		{
			return $this->json(["error" => "id ". $activity_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
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
		$modified_activity = $this->modifyActivity($activity,$request['issueId'],$user_id,$request['spentMonths'],$request['spentWeeks'],$request['spentDays'],$request['spentHours'],$request['spentMinutes'],$request['spentPoints'],$request['comment'],$request['activity_type_id'],$request['inserted']);
		return $this->json($modified_activity->toJSON(false));
	}
	
	public function runListActivitiesByProjects(Request $request)
	{
		$dateFrom = intval($request['date_from']);
		$dateTo = intval($request['date_to']);
		
		$prefix = \b2db\Core::getTablePrefix();
		$username = $prefix . tables\Users::UNAME;
		$editedBy = $prefix . tables\IssueSpentTimes::EDITED_BY;
		$projectId = $prefix . tables\Issues::PROJECT_ID;
		$issueId = $prefix . tables\IssueSpentTimes::ISSUE_ID;
		$issueId2 = $prefix . tables\Issues::ID;
		$spentMonths = $prefix . tables\IssueSpentTimes::SPENT_MONTHS;
		$spentWeeks = $prefix . tables\IssueSpentTimes::SPENT_WEEKS;
		$spentDays = $prefix . tables\IssueSpentTimes::SPENT_DAYS;
		$spentHours = $prefix . tables\IssueSpentTimes::SPENT_HOURS;
		$spentMinutes = $prefix . tables\IssueSpentTimes::SPENT_MINUTES;
		$editedAt = $prefix . tables\IssueSpentTimes::EDITED_AT;
		$userId = $prefix . tables\Users::ID;
		$spentTimeTable = $prefix . tables\IssueSpentTimes::getTable()->getB2DBName();
		$issueTable = $prefix . tables\Issues::getTable()->getB2DBName();
		$userTable = $prefix . tables\Users::getTable()->getB2DBName();
		$sql = "
SELECT $username AS username, $projectId AS project_id, SUM($spentMonths) AS months, SUM($spentWeeks) AS weeks, SUM($spentDays) AS days, SUM($spentHours) AS hours, SUM($spentMinutes) AS minutes 
FROM $spentTimeTable 
JOIN $issueTable ON $issueId = $issueId2 
JOIN $userTable ON $editedBy = $userId 
WHERE $editedAt >= ? AND $editedAt <= ? 
GROUP BY username, project_id 
ORDER BY username, project_id";
		$statement = \b2db\Statement::getPreparedStatement($sql);
		$statement->statement->execute([$dateFrom, $dateTo]);
		$result = [];
		while($row = $statement->fetch()) {
			$months = intval($row['months']);
			$weeks = intval($row['weeks']);
			$days = intval($row['days']);
			$hours = intval($row['hours']);
			$minutes = intval($row['minutes']);
			$hours /= 100;
			$int_hours = floor($hours);
			$minutes += ($hours - $int_hours) * 60;
			$hours = $int_hours;
			$weeks += $months * 4;
			$days += $weeks * 5;
			$hours += $days * 8;
			$hours += floor($minutes / 60);
			$minutes %= 60;
			if($minutes < 10) {
				$spent = "$hours:0$minutes";
			} else {
				$spent = "$hours:$minutes";
			}
			$result[] = [
					'username' => $row['username'],
					'project_id' => intval($row['project_id']),
					'time_spent' => $spent
			];
		}
		return $this->json($result);
	}
	
	protected function modifyActivity($activity , $issue_id = null, $user_id = null , $months = null,$weeks = null,$days = null,$hours = null,$minutes = null,$points = null,$comment = null,$activity_type_id = null,$inserted = null){
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
		if(isset($activity_type_id)){
			$modified_activity->setActivityType(trim($activity_type_id));
		}
		if(isset($inserted)) $modified_activity->setEditedAt(trim($inserted));
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
		$issue = entities\Issue::getB2DBTable()->selectById($issue_id);
		$issue_no = $issue->getFormattedIssueNo();
		$project_key = entities\Project::getB2DBTable()->selectById($issue->getProjectID())->getKey();
		$href =  Context::getRouting()->generate('viewissue', ['project_key' => $project_key, 'issue_no' => $issue_no ], false);
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
		$fields = ["id" => $activity_id, "username" => $user,"inserted" => $edited_at,"issue_id" => $issue_id,"issue_no" => $issue_no,"href" => $href ,"spent_months" =>$spent_months,"spent_weeks" => $spent_weeks,"spent_days" => $spent_days,"spent_hours" => $spent_hours, "spent_minutes" => $spent_minutes,"spent_points" => $spent_points, "comment" => $comment, "activity_type_id" => $activity_type_id, "activity_type_desc" => $activity_type_desc];
		return $fields;
	}
	
	protected function getFieldsIssueByActivityID($activity_id, $includeClosed = false)
	{
		$activities_table = tables\IssueSpentTimes::getTable();
		$crit2 = $activities_table->getCriteria();
		$crit2->addWhere(tables\IssueSpentTimes::ID, $activity_id);
		$activity = $activities_table->selectOne($crit2);
		$issue_id = $activity->getIssueID();
		$issue = entities\Issue::getB2DBTable()->selectById($issue_id);
		if ($issue->isClosed() && !$includeClosed){
			return null;
		}
		$issue_no = $issue->getFormattedIssueNo();
		$project_key = entities\Project::getB2DBTable()->selectById($issue->getProjectID())->getKey();
		$href =  Context::getRouting()->generate('viewissue', ['project_key' => $project_key, 'issue_no' => $issue_no ], false);
		$fields = ["id" => $issue_id,"title" => $issue->getTitle(),"issue_no" => $issue_no,"href" => $href];
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
	
	protected function getIssueByID($issue_id){
		return entities\Issue::getB2DBTable()->selectById($issue_id);
	}
	
	protected function getIssueHrefByID($issue_id){
		$issue = $this->getIssueByID($issue_id);
		$issue_no = $issue->getFormattedIssueNo();
		$project_key = entities\Project::getB2DBTable()->selectById($issue->getProjectID())->getKey();
		return Context::getRouting()->generate('viewissue', ['project_key' => $project_key, 'issue_no' => $issue_no ], false);
	}
	
}