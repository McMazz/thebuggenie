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
use thebuggenie\core\entities\tables\CustomFieldOptions;
use thebuggenie\core\entities\Team;
use thebuggenie\core\entities\User;
use thebuggenie\core\entities\CustomDatatype;

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
	
	public function runInitialAdvance(Request $request){
		return $this->json($request['issue_id']);
	}
	
	public function runGenReportActivity(Request $request)
	{
		$issues = [];
		$date_from = trim($request['date_from']);
		$date_to = trim($request['date_to']);
		$username = trim($request['username']);
		
		$time_spent_table = entities\IssueSpentTime::getB2DBTable();
		$crit = $time_spent_table->getCriteria();
		$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_from, $crit::DB_GREATER_THAN_EQUAL);
		$crit->addWhere(tables\IssueSpentTimes::EDITED_AT, $date_to, $crit::DB_LESS_THAN_EQUAL);
		$crit->addOrderBy(tables\IssueSpentTimes::EDITED_AT, $crit::SORT_DESC_NUMERIC);
		
		if ($username != null){
			$searched_user = entities\User::getByUsername($username);
			if ($searched_user != null && $searched_user->getID() != null){
				$crit->addWhere(tables\IssueSpentTimes::EDITED_BY, $searched_user->getID());
			}else{
				return $this->json(["error" => "Utente " . $username . " non trovato"], 404);
			}
		}
		
		foreach ($time_spent_table->select($crit) as $activity)
		{
			$issue = $this->getIssueByID($activity->getIssueID());
			$project = entities\Project::getB2DBTable()->selectById($issue->getProjectID());
			$issue_type = entities\Issuetype::getB2DBTable()->selectById($issue->getIssueType()->getID());
			$activity_type_id = $activity->getActivityTypeID();
			$issue_status = entities\Status::getB2DBTable()->selectById($issue->getStatus()->getID());
			$customValues = [];
			if ($rows = tables\IssueCustomFields::getTable()->getAllValuesByIssueID($issue->getID()))
			{
				foreach ($rows as $row)
				{
					$custom_field_id = $row->get(tables\IssueCustomFields::CUSTOMFIELDS_ID);
					$customOptionID = $row->get(tables\IssueCustomFields::CUSTOMFIELDOPTION_ID);
					if($custom_field_id == 1){
						if($row->get(tables\IssueCustomFields::OPTION_VALUE) != null){
							$refOrder = $row->get(tables\IssueCustomFields::OPTION_VALUE);
							$customValues[$custom_field_id] = $refOrder;
						}
					}else if($custom_field_id != null){
						if($customOptionID != null && $customOptionID != 0){
							if($custom_field_id == 2){
								$customFieldOption = tables\CustomFieldOptions::getTable()->getByID($customOptionID);
								$ticketType = $customFieldOption->get(tables\CustomFieldOptions::NAME);
								$customValues[$custom_field_id] = $ticketType;
							}else if($custom_field_id == 4){
								$component = tables\Components::getTable()->getByID($customOptionID);
								if($component != null){
									$refDepart = $component->get(tables\Components::NAME);
									$customValues[$custom_field_id] = $refDepart;
								}
							}else if($custom_field_id == 5){
								$edition = tables\Editions::getTable()->getByID($customOptionID);
								if ($edition != null){
									$refCustomer = $edition->get(tables\Editions::NAME);
									$customValues[$custom_field_id] = $refCustomer;
								}
							}
						}
					}
				}
			}
			if($issue->hasAssignee()){
				$assignee = $issue->getAssignee();
				if($assignee != null){
					$assignee = $assignee->getUsername();
				}else{
					$assignee = "";
				}
			}else{
				$assignee = "";
			}
			if($issue->getDescription() != null){
				$issueDescription = $issue->getDescription();
			}else{
				$issueDescription = "";
			}
			$owner = $issue->getOwner();
			if($owner!= null){
				if($owner instanceof Team ){
					$owner = $owner->getName();
				}else if($owner instanceof  User){
					$owner = $owner->getRealname();
				}else{
					$owner = $owner->getUsername();
				}
			}else{
				$owner = "";
			}
			$posted_by = $issue->getPostedBy();
			if($posted_by != null){
				if($posted_by instanceof Team ){
					$posted_by = $posted_by->getName();
				}else if($posted_by instanceof  User){
					$posted_by= $posted_by->getRealname();
				}else{
					$posted_by= $posted_by->getUsername();
				}
			}else{
				$posted_by = "";		
			}
			if($issue_type != null){
				$issue_type = $issue_type->getName();
			}else{
				$issue_type = "";
			}
			if($issue_status != null){
				$issue_status= $issue_status->getName();
			}else{
				$issue_status= "";
			}
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
			if(isset($customValues[2])){
				$ticketType = $customValues[2];
			}else{
				$ticketType = "";
			}
			if(isset($customValues[1])){
				$refOrder= $customValues[1];
			}else{
				$refOrder= "";
			}
			if(isset($customValues[4])){
				$refDepart= $customValues[4];
			}else{
				$refDepart= "";
			}
			if(isset($customValues[5])){
				$refCustomer= $customValues[5];
			}else{
				$refCustomer= "";
			}
			$minutesProvided = ($activity->getSpentWeeks() * 2400) + ($activity->getSpentDays() * 480 ) + (($activity->getSpentHours()/100)*60) + $activity->getSpentMinutes();
			$estimatedTime = ($issue->getEstimatedWeeks() * 2400) + ($issue->getEstimatedDays() * 480) + (($issue->getEstimatedHours())*60) + $issue->getEstimatedMinutes();
			$issues[] = ["project_id" => $project->getID(),"project_name" => $project->getPrefix(),"username" => $activity->getUser()->getUsername(),"employee" => $activity->getUser()->getRealname(),"issue_id" => $activity->getIssueID(), "issue_name" => $issue->getName(),"issue_description" => $issueDescription,"issue_no" => $issue->getFormattedIssueNo(), "assigned_to" => $assignee,"posted_by" => $posted_by,"owned_by" => $owner,"status" => $issue->getStatus()->getID(),"status_description" => $issue_status,"issue_type" => $issue_type,"ref_order" => $refOrder,"ref_customer" => $refCustomer,"ref_depart" => $refDepart,"ticket_type" => $ticketType,"href" => $this->getIssueHrefByID($activity->getIssueID()),"spent_points" => $activity->getSpentPoints(),"minutes_provided" => $minutesProvided,"estimated_minutes" => $estimatedTime,"comment" => $activity->getComment(), "date_of_entry" => $activity->getEditedAt(),"activity_type_id" => $activity_type_id, "activity_type_desc" => $activity_type_desc];
		}
		return $this->json($issues);
	}
	
	public function runListTimeSpentBetweenDates(Request $request)
	{
		$issues = [];
		$current_user_id = thebuggenie\core\framework\Context::getUser()->getID();
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
			return $this->json($insert_obj->issue->toJSON(false));
		}
		else
		{
			$projects = [];
			$p = entities\Project::getAllRootProjects(false);
			//Recupero i progetti a cui l'utente corrente ha accesso
			foreach ($p as $project)
			{
				if ($project->hasAccess($this->getUser()))
					$projects[] = $project->getId();
			}
  			$limit = intval($request['limit']);
			
			$crit = tables\Issues::getTable()->getCriteria();
			$crit->addWhere(tables\Issues::DELETED, false);
			$crit->addWhere(tables\Issues::STATE, entities\Issue::STATE_OPEN);
			$crit->addWhere(tables\Issues::PROJECT_ID, $projects , Criteria::DB_IN);
			$crit->addOrderBy(tables\Issues::LAST_UPDATED, Criteria::SORT_DESC);
			if ($limit !== null || $limit != 0)
				$crit->setLimit($limit);
			
			$recent_issues = [];
			foreach (tables\Issues::getTable()->select($crit) as $issue)
			{
				$recent_issues[] = $issue->toJSON(false);
			}
			
			return $this->json($recent_issues); 
// 			<-> PARTE VECCHIA CHE EFFETTUA REALMENTE LA RICERCA, SOLUZIONE TRONCATA PER NON SOVRACCARICARE TBG N.B AGGIUNGERE VARIABILE LIMIT
// 			$text = trim($request['search']);
// 			$offset = intval($request['page']);
// 			if($offset != 0)
// 			{
// 				$offset -= 1;
// 				$offset *= $limit;
// 			}
// 			$counter = 0;
// 			$issues = entities\Issue::findIssuesByText($text);
// 			$ret_issues = [];
// 			foreach ($issues[0] as $issue)
// 			{
// 				$ret_issues[] = $issue->toJSON(false);
// 				$counter++;
// 				if($limit != 0 && $counter == $limit){
// 					break;
// 				}
// 			}
// 			return $this->json($ret_issues);
			
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
	
	public function runListFieldsByIssueTypeTest(Request $request)
	{
		$project_id = trim($request['project_id']);
		$project = entities\Project::getB2DBTable()->selectByID($project_id);
		$fields_array = $project->getReportableFieldsArray($request['issue_type']);
		foreach ($fields_array as $key => $value){
			if(isset($value['custom'])){
				if ($value['custom'] == true){
					$fields_array[$key]['description'] = $this->getFieldDescription($key);
				}
			}else{
				$i18n = Context::getI18n();
				$fields_array[$key]['description'] = $i18n->__($key);
			}
		}
		return $this->json(['fields' => $fields_array]);
	}
// 	public function runListFieldsByIssueTypeNew(Request $request)
// 	{
// 		$project_id = trim($request['project_id']);
// 		$project = entities\Project::getB2DBTable()->selectByID($project_id);
// 		$fields_array = $project->getReportableFieldsArray($request['issue_type']);
// 		$fields = [];
// 		$i18n = Context::getI18n();
// 		$custom_fields = tables\CustomFields::getTable()->getAll();
// 		$custom_fields_keys = [];
// 		foreach ($custom_fields as $field)
// 		{
// 			$custom_fields_keys[] = strtolower($field->getName());
// 		}
// 		if($fields_array != null){
// 			foreach ($fields_array as $row)
// 			{
// 				$field_key = $row->get(tables\IssueFields::FIELD_KEY);
// 				$is_custom = false;
// 				$field_name;
// 				if(!in_array(strtolower($field_key), $custom_fields_keys))
// 				{
// 					$field_name = str_replace("_", " ", ucfirst($field_key));
// 					$field_name = $i18n->__($field_name);
// 				}else
// 				{
// 					$field_name = $custom_fields[$field_key]->getName();
// 					$field_name = $this->getFieldDescription($field_key);;
// 					$is_custom = true;
// 				}
// 				$required = $this->stringToBoolean($row->get(tables\IssueFields::REQUIRED));
// 				$reportable = $this->stringToBoolean($row->get(tables\IssueFields::REPORTABLE));
// 				$additional = $this->stringToBoolean($row->get(tables\IssueFields::ADDITIONAL));
// 				$option_values = [];
				
// 				if($is_custom)
// 				{
// 					$options = $this->getListOptionsByCustomItemType($field_key);
// 					$dataTypeDescription = entities\CustomDatatype::getByKey($field_key)->getTypeDescription();
// 					$dataType = entities\CustomDatatype::getByKey($field_key)->getType();
// 				}else
// 				{
// 					$options = $this->getListOptionsByItemType($field_key);
// 					$dataTypes = entities\Datatype::getTypes();
// 				}
// 				if($additional && $options == []){
// 					$options = $this->getListOptionsAdditionalItem($project_id, $issue_type,$field_key);
// 					$options = $this->removeUnnecessaryAdditionlItemOptions($options);
// 				}
				
// 				if($options == []){
// 					$options = $this->getListOptionsAdditionalItem($project_id, $issue_type,$field_key);
// 					$options = $this->removeUnnecessaryAdditionlItemOptions($options);
// 				}
				
// 				foreach ($options as $option){
// 					$option_values[$option['id']] = $option['name'];
// 				}
				
// 				if ($reportable){
// 					if($is_custom){
// 						if($option_values == []){
// 							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "custom" => $is_custom, "field_type_description" => $dataTypeDescription, "field_type_id" => $dataType];
// 						}else{
// 							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "values" => $option_values, "custom" => $is_custom, "field_type_description" => $dataTypeDescription, "field_type_id" => $dataType];
// 						}
// 					}else{
// 						if($option_values == []){
// 							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "custom" => $is_custom];
// 						}else{
// 							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "values" => $option_values, "custom" => $is_custom];
// 						}
// 					}
// 					$fields[$field_key] =  $obj;
// 				}
// 			}
// 		}
// 		return $this->json(["fields" => $fields]);
// 	}
	
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
		if($rows != null){
			while ($row = $rows->getNextRow())
			{
				$field_key = $row->get(tables\IssueFields::FIELD_KEY);
				$is_custom = false;
				$field_name;
				if(!in_array(strtolower($field_key), $custom_fields_keys))
				{
					$field_name = str_replace("_", " ", ucfirst($field_key));
					$field_name = $i18n->__($field_name);
				}else
				{
	 				$field_name = $custom_fields[$field_key]->getName();
					$field_name = $this->getFieldDescription($field_key);;
					$is_custom = true;
				}
				$required = $this->stringToBoolean($row->get(tables\IssueFields::REQUIRED));
				$reportable = $this->stringToBoolean($row->get(tables\IssueFields::REPORTABLE));
				$additional = $this->stringToBoolean($row->get(tables\IssueFields::ADDITIONAL));
				$option_values = [];
				
				if($is_custom)
				{
					$options = $this->getListOptionsByCustomItemType($field_key);
					$dataTypeDescription = entities\CustomDatatype::getByKey($field_key)->getTypeDescription();
					$dataType = entities\CustomDatatype::getByKey($field_key)->getType();
				}else
				{
					$options = $this->getListOptionsByItemType($field_key);
					$dataTypes = entities\Datatype::getTypes();
				}
				if($additional && $options == []){
					$options = $this->getListOptionsAdditionalItem($project_id, $issue_type,$field_key);
					$options = $this->removeUnnecessaryAdditionlItemOptions($options);
				}
				
				if($options == []){
					$options = $this->getListOptionsAdditionalItem($project_id, $issue_type,$field_key);
					$options = $this->removeUnnecessaryAdditionlItemOptions($options);
				}
				
				foreach ($options as $option){
					$option_values[$option['id']] = $option['name'];
				}
				
				if ($reportable){
					if($is_custom){
						if($option_values == []){
							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "custom" => $is_custom, "field_type_description" => $dataTypeDescription, "field_type_id" => $dataType];
						}else{
							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "values" => $option_values, "custom" => $is_custom, "field_type_description" => $dataTypeDescription, "field_type_id" => $dataType];
						}
					}else{
						if($option_values == []){
							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "custom" => $is_custom];
						}else{
							$obj = ["id" => $row->get(tables\IssueFields::ID), "description" => $field_name, "name" => $field_key ,"required" => $required, "additional" => $additional, "values" => $option_values, "custom" => $is_custom];
						}
					}
					$fields[$field_key] =  $obj;
				}
			}
		}
		return $this->json(["fields" => $fields]);
	}
	
	protected function getListOptionsAdditionalItem($project_id, $issue_type, $item_type)
	{
		$options = [];
		$project = entities\Project::getB2DBTable()->selectByID($project_id);
		$fields_array = $project->getReportableFieldsArray($issue_type, true);
		if(isset($fields_array[$item_type])){
			if(isset($fields_array[$item_type]['values'])){
				$options = $fields_array[$item_type]['values'];
			}
		}
		
		return $options;
	}
	
	protected function removeUnnecessaryAdditionlItemOptions($options)
	{
		$cleaned_options = [];
		foreach ($options as $key => $value){
			if(strtoupper($options[$key]) != "NONE"){
				if (strpos($key, 'v') !== false) {
					$id = str_replace('v', '', $key);
					$cleaned_options[] = array('id' => $id, 'name' => $options[$key]);
				}
			}
		}
		return $cleaned_options;
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
	
	protected function getFieldDescription($key)
	{
		return entities\tables\CustomFields::getTable()->getByKey($key)[entities\tables\CustomFields::FIELD_DESCRIPTION];
	}
	
	protected function getListOptionsByCustomItemType($item_type)
	{
		$options = [];
		$custom_fields_options_table = tables\CustomFieldOptions::getTable();
		$crit = $custom_fields_options_table->getCriteria();
		$crit->addWhere("customfieldoptions.key", $item_type);
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
			$issue = $this->getIssueByID($issue_id);
			$spenttime->setIssue($issue);
			if (isset($request['username'])){
				if(entities\User::getByUsername(trim($request['username'])) != null){
					$user = entities\User::getByUsername(trim($request['username']));
					$spenttime->setUser($user);
				}else{
					return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
				}
			}else{
				$spenttime->setUser(\thebuggenie\core\framework\Context::getUser());
			}
			
			//Converto il codice per il carattere apostrofo con l'apostrofo
			if($request['comment'] != null){
				$spenttime->setComment(htmlspecialchars_decode($request['comment'], ENT_QUOTES));
			}
			
			$spenttime->setSpentPoints($request['spentPoints']);
			$spenttime->setSpentMinutes($request['spentMinutes']);
			$spenttime->setSpentHours($request['spentHours']);
			$spenttime->setSpentDays($request['spendDays']);
			$spenttime->setSpentWeeks($request['spentWeeks']);
			$spenttime->setSpentMonths($request['spentMonths']);
			$spenttime->setActivityType($request['activityTypeId']);
			$spenttime->setEditedAt($request['inserted']);
			$spenttime->save();
			$spenttime->getIssue()->saveSpentTime();
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
		$activity = tables\IssueSpentTimes::getTable()->selectById($activity_id);
		if($activity == null)
		{
			return $this->json(["error" => "id ". $activity_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$issue = $this->getIssueByID($issue_id);
		$initial_issue = $this->getIssueByID($activity->getIssue()->getID());
		if($issue == null)
		{
			return $this->json(["error" => "issue_id ". $issue_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$modified_activity = $this->modifyActivity($activity, $issue);
		$times = tables\IssueSpentTimes::getTable()->getSpentTimeSumsByIssueId($initial_issue->getID());
		$initial_issue->setSpentPoints($times['points']);
		$initial_issue->setSpentMinutes($times['minutes']);
		$initial_issue->setSpentHours($times['hours']);
		$initial_issue->setSpentDays($times['days']);
		$initial_issue->setSpentWeeks($times['weeks']);
		$initial_issue->setSpentMonths($times['months']);
		$initial_issue->save();
		$initial_issue->saveSpentTime();
		return $this->json($modified_activity->toJSON(false));
	}
	
	public function runDeleteActivity(Request $request){
		$activity_id = trim($request['activity_id']);
		$activity = tables\IssueSpentTimes::getTable()->selectById($activity_id);
		if($activity == null)
		{
			return $this->json(["error" => "id ". $activity_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		$issue = $activity->getIssue();
		$activity->delete();
		$issue->saveSpentTime();
		return $this->json(["message" => "success"]);
	}
	
	public function runModifyActivity(Request $request)
	{
		$activity_id = trim($request['activity_id']);
		$activity = tables\IssueSpentTimes::getTable()->selectById($activity_id);
		if($activity == null)
		{
			return $this->json(["error" => "id ". $activity_id ." not found"], Response::HTTP_STATUS_BAD_REQUEST);
		}
		if (isset($request['username'])){
			$user = entities\User::getByUsername(trim($request['username']));
			if($user == null)
			{
				return $this->json(['error' => "Could not find username:". trim($request['username']) ." , please provide a valid username"], Response::HTTP_STATUS_BAD_REQUEST);
			}
		}else{
			return $this->json(['error' => "Please provide a username"], Response::HTTP_STATUS_BAD_REQUEST);
				
		}
		$issue = $this->getIssueByID(trim($request['issueId']));
		$modified_activity = $this->modifyActivity($activity,$issue,$user,$request['spentMonths'],$request['spentWeeks'],$request['spentDays'],$request['spentHours'],$request['spentMinutes'],$request['spentPoints'],$request['comment'],$request['activityTypeId'],$request['inserted']);
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
	
	public function runGetIssueByID(Request $request){
		$issue_id = trim($request['issue_id']);
		$issue = entities\Issue::getB2DBTable()->selectById($issue_id);
		$issue_no = $issue->getFormattedIssueNo();
		$project_key = entities\Project::getB2DBTable()->selectById($issue->getProjectID())->getKey();
		$href =  Context::getRouting()->generate('viewissue', ['project_key' => $project_key, 'issue_no' => $issue_no ], false);
		return $this->json(["id" =>  $issue->getID(), "issue_no" => $issue->getFormattedIssueNo(), "state" => $issue->getState(), "closed" => $issue->isClosed(),"created_at" => $issue->getPosted(), "title" => $issue->getTitle(), "href" => $href]);
	}
	
	protected function modifyActivity($activity, $issue = null, $user = null, $months = null, $weeks = null, $days = null, $hours = null, $minutes = null, $points = null, $comment = null, $activity_type_id = null, $inserted = null){
		if(isset($issue)) $activity->setIssue($issue);
		if(isset($user)) $activity->setUser($user);
		if(isset($months)) $activity->setSpentMonths(trim($months));
		if(isset($weeks)) $activity->setSpentWeeks(trim($weeks));
		if(isset($days)) $activity->setSpentDays(trim($days));
		if(isset($hours)) $activity->setSpentHours(trim($hours));
		if(isset($minutes)) $activity->setSpentMinutes(trim($minutes));
		if(isset($points)) $activity->setSpentPoints(trim($points));
		if(isset($comment)){
			if($comment != null){
				$comment = htmlspecialchars_decode($comment, ENT_QUOTES);
			}
			$activity->setComment(trim($comment));
		}
		if(isset($activity_type_id)){
			$activity->setActivityType(trim($activity_type_id));
		}
		if(isset($inserted)) $activity->setEditedAt(trim($inserted));
		$activity->save();
		$activity->getIssue()->saveSpentTime();
		return $activity;
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
		$issue_title = $issue->getTitle();
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
		$fields = ["id" => $activity_id, "username" => $user,"inserted" => $edited_at,"issue_id" => $issue_id,"issue_no" => $issue_no,"issue_title" => $issue_title,"href" => $href ,"spent_months" =>$spent_months,"spent_weeks" => $spent_weeks,"spent_days" => $spent_days,"spent_hours" => $spent_hours, "spent_minutes" => $spent_minutes,"spent_points" => $spent_points, "comment" => $comment, "activity_type_id" => $activity_type_id, "activity_type_desc" => $activity_type_desc];
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
