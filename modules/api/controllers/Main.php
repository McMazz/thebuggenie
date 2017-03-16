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
	
	public function runListEditions(Request $request)
	{
		$editions = [];
		$project_id = trim($request['project_id']);
		$editions_table = tables\Editions::getTable();
		$crit = $editions_table->getCriteria();
		$crit->addWhere(tables\Editions::PROJECT, $project_id);
		foreach ($editions_table->select($crit) as $edition){
			$editions[] = $edition->toJSON(true);
		}
		return $this->json($editions);
	}
	
	public function runListComponents(Request $request)
	{
		$components = [];
		$project_id = trim($request['project_id']);
		$components_table = tables\Components::getTable();
		$crit = $components_table->getCriteria();
		$crit->addWhere(tables\Components::PROJECT, $project_id);
		foreach ($components_table->select($crit, false) as $component){
			$components[] = $component->toJSON(false);
		}
		return $this->json($components);
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
		$ret_issues = [];
		foreach ($issues[0] as $issue)
		{
			$ret_issues[] = $issue->toJSON(false);
		}
		return $this->json($ret_issues);
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
				'starred' => $retval ? "true" : "false", // Action::renderJSON turns everything into strings... @TODO: change ::json implementation
				'count' => count($issue->getSubscribers())
		]);
	}
	
	protected function _loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction(framework\Request $request)
	{
		try
		{
			if ($project_key = $request['project_key'])
				$this->selected_project = entities\Project::getByKey($project_key);
			elseif ($project_id = $request['project_id'])
				$this->selected_project = entities\Project::getB2DBTable()->selectById($project_id);
		}
		catch (\Exception $e)
		{
	
		}
	
		if ($this->selected_project instanceof entities\Project){
			framework\Context::setCurrentProject($this->selected_project);
		}
		if ($this->selected_project instanceof entities\Project){
			$this->issuetypes = $this->selected_project->getIssuetypeScheme()->getIssuetypes();
		}
		else{
			$this->issuetypes = entities\Issuetype::getAll();
		}
	
		$this->selected_issuetype = null;
		if ($request->hasParameter('issuetype')){
			$this->selected_issuetype = entities\Issuetype::getByKeyish($request['issuetype']);
		}
		
		$this->locked_issuetype = (bool) $request['lock_issuetype'];

		if (!$this->selected_issuetype instanceof entities\Issuetype)
		{
			$this->issuetype_id = $request['issuetype_id'];
			if ($this->issuetype_id)
			{
				try
				{
					$this->selected_issuetype = entities\Issuetype::getB2DBTable()->selectById($this->issuetype_id);
				}
				catch (\Exception $e)
				{

				}
			}
		}
		else
		{
			$this->issuetype_id = $this->selected_issuetype->getID();
		}
	}
	
	protected function _getMilestoneFromRequest($request)
	{
		if ($request->hasParameter('milestone_id'))
		{
			try
			{
				$milestone = entities\Milestone::getB2DBTable()->selectById((int) $request['milestone_id']);
				if ($milestone instanceof entities\Milestone && !$milestone->hasAccess()) $milestone = null;
				return $milestone;
			}
			catch (\Exception $e) { }
		}
	}
	
	protected function _postIssueValidation(framework\Request $request, &$errors, &$permission_errors)
	{
		$i18n = framework\Context::getI18n();
		if (!$this->selected_project instanceof entities\Project)
			$errors['project'] = $i18n->__('You have to select a valid project');
		if (!$this->selected_issuetype instanceof entities\Issuetype)
			$errors['issuetype'] = $i18n->__('You have to select a valid issue type');
		if (empty($errors))
		{
			$fields_array = $this->selected_project->getReportableFieldsArray($this->issuetype_id);

			$this->title = $request->getRawParameter('title');
			$this->selected_shortname = $request->getRawParameter('shortname', null);
			$this->selected_description = $request->getRawParameter('description', null);
			$this->selected_description_syntax = $request->getRawParameter('description_syntax', null);
			$this->selected_reproduction_steps = $request->getRawParameter('reproduction_steps', null);
			$this->selected_reproduction_steps_syntax = $request->getRawParameter('reproduction_steps_syntax', null);

			if ($edition_id = (int) $request['edition_id'])
				$this->selected_edition = entities\Edition::getB2DBTable()->selectById($edition_id);
			if ($build_id = (int) $request['build_id'])
				$this->selected_build = entities\Build::getB2DBTable()->selectById($build_id);
			if ($component_id = (int) $request['component_id'])
				$this->selected_component = entities\Component::getB2DBTable()->selectById($component_id);

			if (trim($this->title) == '' || $this->title == $this->default_title)
				$errors['title'] = true;
			if (isset($fields_array['shortname']) && $fields_array['shortname']['required'] && trim($this->selected_shortname) == '')
				$errors['shortname'] = true;
			if (isset($fields_array['description']) && $fields_array['description']['required'] && trim($this->selected_description) == '')
				$errors['description'] = true;
			if (isset($fields_array['reproduction_steps']) && !$request->isAjaxCall() && $fields_array['reproduction_steps']['required'] && trim($this->selected_reproduction_steps) == '')
				$errors['reproduction_steps'] = true;

			if (isset($fields_array['edition']) && $edition_id && !in_array($edition_id, array_keys($fields_array['edition']['values'])))
				$errors['edition'] = true;

			if (isset($fields_array['build']) && $build_id && !in_array($build_id, array_keys($fields_array['build']['values'])))
				$errors['build'] = true;

			if (isset($fields_array['component']) && $component_id && !in_array($component_id, array_keys($fields_array['component']['values'])))
				$errors['component'] = true;

			if ($category_id = (int) $request['category_id'])
			{
				$category = entities\Category::getB2DBTable()->selectById($category_id);

				if (! $category->hasAccess())
				{
					$errors['category'] = true;
				}
				else
				{
					$this->selected_category = $category;
				}
			}

			if ($status_id = (int) $request['status_id'])
				$this->selected_status = entities\Status::getB2DBTable()->selectById($status_id);

			if ($reproducability_id = (int) $request['reproducability_id'])
				$this->selected_reproducability = entities\Reproducability::getB2DBTable()->selectById($reproducability_id);

			if ($milestone_id = (int) $request['milestone_id'])
			{
				$milestone = $this->_getMilestoneFromRequest($request);

				if (!$milestone instanceof entities\Milestone)
				{
					$errors['milestone'] = true;
				}
				else
				{
					$this->selected_milestone = $milestone;
				}
			}

			if ($parent_issue_id = (int) $request['parent_issue_id'])
				$this->parent_issue = entities\Issue::getB2DBTable()->selectById($parent_issue_id);

			if ($resolution_id = (int) $request['resolution_id'])
				$this->selected_resolution = entities\Resolution::getB2DBTable()->selectById($resolution_id);

			if ($severity_id = (int) $request['severity_id'])
				$this->selected_severity = entities\Severity::getB2DBTable()->selectById($severity_id);

			if ($priority_id = (int) $request['priority_id'])
				$this->selected_priority = entities\Priority::getB2DBTable()->selectById($priority_id);

			if ($request['estimated_time'])
				$this->selected_estimated_time = $request['estimated_time'];

			if ($request['spent_time'])
				$this->selected_spent_time = $request['spent_time'];

			if (is_numeric($request['percent_complete']))
				$this->selected_percent_complete = (int) $request['percent_complete'];

			if ($pain_bug_type_id = (int) $request['pain_bug_type_id'])
				$this->selected_pain_bug_type = $pain_bug_type_id;

			if ($pain_likelihood_id = (int) $request['pain_likelihood_id'])
				$this->selected_pain_likelihood = $pain_likelihood_id;

			if ($pain_effect_id = (int) $request['pain_effect_id'])
				$this->selected_pain_effect = $pain_effect_id;

			$selected_customdatatype = array();
			foreach (entities\CustomDatatype::getAll() as $customdatatype)
			{
				$customdatatype_id = $customdatatype->getKey() . '_id';
				$customdatatype_value = $customdatatype->getKey() . '_value';
				if ($customdatatype->hasCustomOptions())
				{
					$selected_customdatatype[$customdatatype->getKey()] = null;
					if ($request->hasParameter($customdatatype_id))
					{
						$customdatatype_id = (int) $request->getParameter($customdatatype_id);
						$selected_customdatatype[$customdatatype->getKey()] = new entities\CustomDatatypeOption($customdatatype_id);
					}
				}
				else
				{
					$selected_customdatatype[$customdatatype->getKey()] = null;
					switch ($customdatatype->getType())
					{
						case entities\CustomDatatype::INPUT_TEXTAREA_MAIN:
						case entities\CustomDatatype::INPUT_TEXTAREA_SMALL:
							if ($request->hasParameter($customdatatype_value))
								$selected_customdatatype[$customdatatype->getKey()] = $request->getParameter($customdatatype_value, null, false);

								break;
						default:
							if ($request->hasParameter($customdatatype_value))
								$selected_customdatatype[$customdatatype->getKey()] = $request->getParameter($customdatatype_value);
								elseif ($request->hasParameter($customdatatype_id))
								$selected_customdatatype[$customdatatype->getKey()] = $request->getParameter($customdatatype_id);

								break;
					}
				}
			}
			$this->selected_customdatatype = $selected_customdatatype;

			foreach ($fields_array as $field => $info)
			{
				if ($field == 'user_pain')
				{
					if ($info['required'])
					{
						if (!($this->selected_pain_bug_type != 0 && $this->selected_pain_likelihood != 0 && $this->selected_pain_effect != 0))
						{
							$errors['user_pain'] = true;
						}
					}
				}
				elseif ($info['required'])
				{
					$var_name = "selected_{$field}";
					if ((in_array($field, entities\Datatype::getAvailableFields(true)) && ($this->$var_name === null || $this->$var_name === 0)) || (!in_array($field, entities\DatatypeBase::getAvailableFields(true)) && !in_array($field, array('pain_bug_type', 'pain_likelihood', 'pain_effect')) && (array_key_exists($field, $selected_customdatatype) && $selected_customdatatype[$field] === null)))
					{
						$errors[$field] = true;
					}
				}
				else
				{
					if (in_array($field, entities\Datatype::getAvailableFields(true)) || in_array($field, array('pain_bug_type', 'pain_likelihood', 'pain_effect')))
					{
						if (!$this->selected_project->fieldPermissionCheck($field))
						{
							$permission_errors[$field] = true;
						}
					}
					elseif (!$this->selected_project->fieldPermissionCheck($field, true, true))
					{
						$permission_errors[$field] = true;
					}
				}
			}
			$event = new \thebuggenie\core\framework\Event('core', 'mainActions::_postIssueValidation', null, array(), $errors);
			$event->trigger();
			$errors = $event->getReturnList();
		}
		return !(bool) (count($errors) + count($permission_errors));
	}
	
	protected function _postIssue(framework\Request $request)
	{
		$fields_array = $this->selected_project->getReportableFieldsArray($this->issuetype_id);
		$issue = new entities\Issue();
		$issue->setTitle($this->title);
		$issue->setIssuetype($this->issuetype_id);
		$issue->setProject($this->selected_project);
		if (isset($fields_array['shortname']))
			$issue->setShortname($this->selected_shortname);
		if (isset($fields_array['description'])) {
			$issue->setDescription($this->selected_description);
			$issue->setDescriptionSyntax($this->selected_description_syntax);
		}
		if (isset($fields_array['reproduction_steps'])) {
			$issue->setReproductionSteps($this->selected_reproduction_steps);
			$issue->setReproductionStepsSyntax($this->selected_reproduction_steps_syntax);
		}
		if (isset($fields_array['category']) && $this->selected_category instanceof entities\Datatype)
			$issue->setCategory($this->selected_category->getID());
		if (isset($fields_array['status']) && $this->selected_status instanceof entities\Datatype)
			$issue->setStatus($this->selected_status->getID());
		if (isset($fields_array['reproducability']) && $this->selected_reproducability instanceof entities\Datatype)
			$issue->setReproducability($this->selected_reproducability->getID());
		if (isset($fields_array['resolution']) && $this->selected_resolution instanceof entities\Datatype)
			$issue->setResolution($this->selected_resolution->getID());
		if (isset($fields_array['severity']) && $this->selected_severity instanceof entities\Datatype)
			$issue->setSeverity($this->selected_severity->getID());
		if (isset($fields_array['priority']) && $this->selected_priority instanceof entities\Datatype)
			$issue->setPriority($this->selected_priority->getID());
		if (isset($fields_array['estimated_time']))
			$issue->setEstimatedTime($this->selected_estimated_time);
		if (isset($fields_array['spent_time']))
			$issue->setSpentTime($this->selected_spent_time);
		if (isset($fields_array['milestone']) || isset($this->selected_milestone))
			$issue->setMilestone($this->selected_milestone);
		if (isset($fields_array['percent_complete']))
			$issue->setPercentCompleted($this->selected_percent_complete);
		if (isset($fields_array['pain_bug_type']))
			$issue->setPainBugType($this->selected_pain_bug_type);
		if (isset($fields_array['pain_likelihood']))
			$issue->setPainLikelihood($this->selected_pain_likelihood);
		if (isset($fields_array['pain_effect']))
			$issue->setPainEffect($this->selected_pain_effect);
		foreach (entities\CustomDatatype::getAll() as $customdatatype)
		{
			if (!isset($fields_array[$customdatatype->getKey()]))
				continue;
			if ($customdatatype->hasCustomOptions())
			{
				if (isset($fields_array[$customdatatype->getKey()]) && $this->selected_customdatatype[$customdatatype->getKey()] instanceof entities\CustomDatatypeOption)
				{
					$selected_option = $this->selected_customdatatype[$customdatatype->getKey()];
					$issue->setCustomField($customdatatype->getKey(), $selected_option->getID());
				}
			}
			else
			{
				$issue->setCustomField($customdatatype->getKey(), $this->selected_customdatatype[$customdatatype->getKey()]);
			}
		}
	
		// FIXME: If we set the issue assignee during report issue, this needs to be set INSTEAD of this
		if ($this->selected_project->canAutoassign())
		{
			if (isset($fields_array['component']) && $this->selected_component instanceof entities\Component && $this->selected_component->hasLeader())
			{
				$issue->setAssignee($this->selected_component->getLeader());
			}
			elseif (isset($fields_array['edition']) && $this->selected_edition instanceof entities\Edition && $this->selected_edition->hasLeader())
			{
				$issue->setAssignee($this->selected_edition->getLeader());
			}
			elseif ($this->selected_project->hasLeader())
			{
				$issue->setAssignee($this->selected_project->getLeader());
			}
		}

		if ($request->hasParameter('custom_issue_access') && $this->selected_project->permissionCheck('canlockandeditlockedissues'))
		{
			switch ($request->getParameter('issue_access'))
			{
				case 'public':
				case 'public_category':
					$issue->setLocked(false);
					$issue->setLockedCategory($request->hasParameter('public_category'));
					break;
				case 'restricted':
					$issue->setLocked();
					break;
			}
		}
		else
		{
			$issue->setLockedFromProject($this->selected_project);
		}

		framework\Event::listen('core', 'thebuggenie\core\entities\Issue::createNew_pre_notifications', array($this, 'listen_issueCreate'));
		$issue->save();

		if (isset($this->parent_issue))
			$issue->addParentIssue($this->parent_issue);
		if (isset($fields_array['edition']) && $this->selected_edition instanceof entities\Edition)
			$issue->addAffectedEdition($this->selected_edition);
		if (isset($fields_array['build']) && $this->selected_build instanceof entities\Build)
			$issue->addAffectedBuild($this->selected_build);
		if (isset($fields_array['component']) && $this->selected_component instanceof entities\Component)
			$issue->addAffectedComponent($this->selected_component);

		return $issue;
	}
	
	protected function _getBuildFromRequest($request)
	{
		if ($request->hasParameter('build_id'))
		{
			try
			{
				$build = entities\Build::getB2DBTable()->selectById((int) $request['build_id']);
				return $build;
			}
			catch (\Exception $e) { }
		}
	}
	
	protected function _getParentIssueFromRequest($request)
	{
		if ($request->hasParameter('parent_issue_id'))
		{
			try
			{
				$parent_issue = entities\Issue::getB2DBTable()->selectById((int) $request['parent_issue_id']);
				return $parent_issue;
			}
			catch (\Exception $e) { }
		}
	}
	
	protected function _clearReportIssueProperties()
	{
		$this->title = null;
		$this->description = null;
		$this->description_syntax = null;
		$this->reproduction_steps = null;
		$this->reproduction_steps_syntax = null;
		$this->selected_category = null;
		$this->selected_status = null;
		$this->selected_reproducability = null;
		$this->selected_resolution = null;
		$this->selected_severity = null;
		$this->selected_priority = null;
		$this->selected_edition = null;
		$this->selected_build = null;
		$this->selected_component = null;
		$this->selected_estimated_time = null;
		$this->selected_spent_time = null;
		$this->selected_percent_complete = null;
		$this->selected_pain_bug_type = null;
		$this->selected_pain_likelihood = null;
		$this->selected_pain_effect = null;
		$selected_customdatatype = array();
		foreach (entities\CustomDatatype::getAll() as $customdatatype)
		{
			$selected_customdatatype[$customdatatype->getKey()] = null;
		}
		$this->selected_customdatatype = $selected_customdatatype;
	}
	
	public function runReportIssue(framework\Request $request)
	{
		$i18n = framework\Context::getI18n();
		$errors = array();
		$permission_errors = array();
		$this->issue = null;
		$this->getResponse()->setPage('reportissue');
	
		$this->_loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction($request);
	
		$this->forward403unless(framework\Context::getCurrentProject() instanceof entities\Project && framework\Context::getCurrentProject()->hasAccess() && $this->getUser()->canReportIssues(framework\Context::getCurrentProject()));
	
		if ($request->isPost())
		{
			if ($this->_postIssueValidation($request, $errors, $permission_errors))
			{
				try
				{
					$issue = $this->_postIssue($request);
					if ($request->hasParameter('files') && $request->hasParameter('file_description'))
					{
						$files = $request['files'];
						$file_descriptions = $request['file_description'];
						foreach ($files as $file_id => $nothing)
						{
							$file = entities\File::getB2DBTable()->selectById((int) $file_id);
							$file->setDescription($file_descriptions[$file_id]);
							$file->save();
							tables\IssueFiles::getTable()->addByIssueIDandFileID($issue->getID(), $file->getID());
						}
					}
					if ($request['return_format'] == 'planning')
					{
						$this->_loadSelectedProjectAndIssueTypeFromRequestForReportIssueAction($request);
						$options = array();
						$options['selected_issuetype'] = $issue->getIssueType();
						$options['selected_project'] = $this->selected_project;
						$options['issuetypes'] = $this->issuetypes;
						$options['issue'] = $issue;
						$options['errors'] = $errors;
						$options['permission_errors'] = $permission_errors;
						$options['selected_milestone'] = $this->_getMilestoneFromRequest($request);
						$options['selected_build'] = $this->_getBuildFromRequest($request);
						$options['parent_issue'] = $this->_getParentIssueFromRequest($request);
						$options['medium_backdrop'] = 1;
						return $this->renderJSON(array('content' => $this->getComponentHTML('main/reportissuecontainer', $options)));
					}
					if ($request->getRequestedFormat() != 'json' && $issue->getProject()->getIssuetypeScheme()->isIssuetypeRedirectedAfterReporting($this->selected_issuetype))
					{
						$this->forward(framework\Context::getRouting()->generate('viewissue', array('project_key' => $issue->getProject()->getKey(), 'issue_no' => $issue->getFormattedIssueNo())), 303);
					}
					else
					{
						$this->_clearReportIssueProperties();
						$this->issue = $issue;
					}
				}
				catch (\Exception $e)
				{
					if ($request['return_format'] == 'planning')
					{
						$this->getResponse()->setHttpStatus(400);
						return $this->renderJSON(array('error' => $e->getMessage()));
					}
					$errors[] = $e->getMessage();
				}
			}
		}
		if ($request['return_format'] == 'planning')
		{
			$err_msg = array();
			foreach ($errors as $field => $value)
			{
				$err_msg[] = $i18n->__('Please provide a value for the %field_name field', array('%field_name' => $field));
			}
			foreach ($permission_errors as $field => $value)
			{
				$err_msg[] = $i18n->__("The %field_name field is marked as required, but you don't have permission to set it", array('%field_name' => $field));
			}
			$this->getResponse()->setHttpStatus(400);
			return $this->renderJSON(array('error' => $i18n->__('An error occured while creating this story: %errors', array('%errors' => '')), 'message' => join('<br>', $err_msg)));
		}
		$this->errors = $errors;
		$this->permission_errors = $permission_errors;
		$this->options = $this->getParameterHolder();
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