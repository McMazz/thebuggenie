<?php

	class BUGSmessages extends BUGSmodule 
	{

	//define ('MESSAGES_NEWMSGWINDOWSTRING', "window.open('" . BUGScontext::getTBGPath() . "modules/messages/newmessage.php?clear_sendto=true','mywindow','menubar=0,toolbar=0,location=0,status=0,scrollbars=0,width=600,height=500');");
	//define ('MESSAGES_NEWMSGWINDOWSTRING_TOUSER_STRING', "window.open('" . BUGScontext::getTBGPath() . "modules/messages/newmessage.php?set_sendto={uid}','mywindow','menubar=0,toolbar=0,location=0,status=0,scrollbars=0,width=600,height=500');");

		
		public function __construct($m_id, $res = null)
		{
			parent::__construct($m_id, $res);
			$this->_module_menu_title = BUGScontext::getI18n()->__("Messages");
			$this->_module_config_title = BUGScontext::getI18n()->__("Messages");
			$this->_module_config_description = BUGScontext::getI18n()->__('Set up the messaging module from this section.');
			$this->_module_version = "1.0";
			$this->addAvailableListener('core', 'dashboard_left_top', 'section_messagesBox', 'Dashboard message summary');
			$this->addAvailableListener('core', 'useractions_bottom', 'section_useractionsBottom', '"Send message" in user drop-down menu');
			$this->addAvailableListener('core', 'teamactions_bottom', 'section_teamactionsBottom', '"Send message" in team drop-down menu');
		}

		public function initialize()
		{

		}

		static public function install($scope = null)
		{
  			if ($scope === null)
  			{
  				$scope = BUGScontext::getScope()->getID();
  			}
			$module = parent::_install('messages', 
  									  'Messages', 
  									  'Enables messaging functionality',
  									  'BUGSmessages',
  									  true, false, true,
  									  '1.0',
  									  true,
  									  $scope);

			$module->setPermission(0, 0, 0, true, $scope);
			$module->enableSection('core', 'dashboard_left_top', $scope);
			$module->enableSection('core', 'useractions_bottom', $scope);
			$module->enableSection('core', 'teamactions_bottom', $scope);
			$module->enableSection('core', 'account_settings', $scope);
			$module->enableSection('core', 'account_settingslist', $scope);
			$module->saveSetting('viewmode', 1);

			if ($scope == BUGScontext::getScope()->getID())
			{
				B2DB::getTable('B2tMessageFolders')->setAutoIncrementStart(5);
				B2DB::getTable('B2tMessageFolders')->create();
				B2DB::getTable('B2tMessages')->create();
			}
			
			try
			{
				self::loadFixtures($scope);
			}
			catch (Exception $e)
			{
				throw $e;
			}
			
		}
		
		static function loadFixtures($scope)
		{
			/*try
			{
				
			}
			catch (Exception $e)
			{
				
			}*/
		}
		
		public function uninstall($scope)
		{
			B2DB::getTable('B2tMessageFolders')->drop();
			B2DB::getTable('B2tMessages')->drop();
			parent::uninstall($scope);
		}
				
		public function getCommentAccess($target_type, $target_id, $type = 'view')
		{
			
		}
		
		public function enableSection($module, $identifier, $scope)
		{
			$function_name = '';
			switch ($module . '_' . $identifier)
			{
				case 'core_teamactions_bottom':
					$function_name = 'section_teamactionsBottom';
					break;
				case 'core_useractions_bottom':
					$function_name = 'section_useractionsBottom';
					break;
				case 'core_account_settingslist':
					$function_name = 'section_accountSettingsList';
					break;
				case 'core_account_settings':
					$function_name = 'section_accountSettings';
					break;
				case 'core_dashboard_left_top':
					$function_name = 'section_messagesSummary';
					break;
			}
			if ($function_name != '') parent::registerPermanentTriggerListener($module, $identifier, $function_name, $scope);
		}
		
		public function getMessages($gettype, $uid = 0, $folder = 0, $msg = 0, $tid = 0)
		{
			$returnmsg = array();
	
			switch($gettype)
			{
				case "details":
					$msgs = array();
					switch ($folder)
					{
						case 2:
							$crit = new B2DBCriteria();
							$crit->setFromTable(B2DB::getTable('B2tMessages'));
							$crit->addJoin(B2DB::getTable('B2tBuddies'), B2tBuddies::ID, B2tMessages::FROM_USER, array(array(B2tBuddies::UID, BUGScontext::getUser()->getUID())));

							if ($_SESSION['messages_filter'] != '')
							{
								$ctn = $crit->returnCriterion(B2tMessages::BODY, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tMessages::TITLE, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tMessages::TITLE, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tUsers::BUDDYNAME, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tUsers::UNAME, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$crit->addWhere($ctn);
							}
							
							if ($_SESSION['unread_filter'] != '')
							{
								$crit->addWhere(B2tMessages::IS_READ, $_SESSION['unread_filter']);
							}
							
							$crit->addWhere(B2tMessages::FROM_USER, $uid);
							$crit->addWhere(B2tMessages::DELETED_SENT, 0);
							if ($msg != 0) 
							{ 
								$res = B2DB::getTable('B2tMessages')->doSelectById($msg, $crit);
								$msgs[] = $res;
							}
							else
							{
								$crit->addOrderBy(B2tMessages::SENT, 'desc');
								$crit->addOrderBy(B2tMessages::ID, 'desc');
								$res = B2DB::getTable('B2tMessages')->doSelect($crit);
								$msgs = $res->getAllRows();
							}
							break;
						case 4:
							foreach (BUGScontext::getUser()->getTeams() as $thetid)
							{
								if (($tid != 0 && $thetid == $tid) || $tid == 0)
								{
									$crit = new B2DBCriteria();
									$crit->setFromTable(B2DB::getTable('B2tMessages'));
									$crit->addJoin(B2DB::getTable('B2tBuddies'), B2tBuddies::ID, B2tMessages::FROM_USER, array(array(B2tBuddies::UID, BUGScontext::getUser()->getUID())));
									
									if ($_SESSION['messages_filter'] != '')
									{
										$ctn = $crit->returnCriterion(B2tMessages::BODY, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
										$ctn->addOr(B2tMessages::TITLE, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
										$ctn->addOr(B2tUsers::BUDDYNAME, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
										$ctn->addOr(B2tUsers::UNAME, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
										$crit->addWhere($ctn);
									}
									
									if ($_SESSION['unread_filter'] != '')
									{
										$crit->addWhere(B2tMessages::IS_READ, $_SESSION['unread_filter']);
									}

									if ($tid != 0)
									{
										$crit->addWhere(B2tMessages::TO_TEAM, $thetid, B2DBCriteria::DB_IN);
									}
									$crit->addWhere(B2tMessages::FOLDER, $folder);
									$crit->addWhere(B2tMessages::DELETED, 0);
									$crit->addWhere(B2tMessages::TO_USER, $uid);
									if ($msg != 0) 
									{ 
										try
										{
											$res = B2DB::getTable('B2tMessages')->doSelectById($msg, $crit);
											$msgs[] = $res;
										}
										catch (Exception $e)
										{
											throw $e;
										}
									}
									else
									{
										$crit->addOrderBy(B2tMessages::SENT, 'desc');
										$crit->addOrderBy(B2tMessages::ID, 'desc');
										$res = B2DB::getTable('B2tMessages')->doSelect($crit);
										$msgs = $res->getAllRows();
									}
								}
							}
							break;
						default:
							$crit = new B2DBCriteria();
							$crit->setFromTable(B2DB::getTable('B2tMessages'));
							$crit->addJoin(B2DB::getTable('B2tBuddies'), B2tBuddies::ID, B2tMessages::FROM_USER, array(array(B2tBuddies::UID, BUGScontext::getUser()->getUID())));
							$crit->addWhere(B2tMessages::TO_USER, $uid);
							$crit->addWhere(B2tMessages::FOLDER, $folder);
							$crit->addWhere(B2tMessages::DELETED, 0);
							$crit->addWhere(B2tMessages::FOLDER, 2, B2DBCriteria::DB_NOT_EQUALS);

							if ($_SESSION['messages_filter'] != '')
							{
								$ctn = $crit->returnCriterion(B2tMessages::BODY, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tMessages::TITLE, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tUsers::BUDDYNAME, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$ctn->addOr(B2tUsers::UNAME, '%'.$_SESSION['messages_filter'].'%', B2DBCriteria::DB_LIKE);
								$crit->addWhere($ctn);
							}
							
							if ($_SESSION['unread_filter'] != '')
							{
								$crit->addWhere(B2tMessages::IS_READ, $_SESSION['unread_filter']);
							}
							
							if ($msg != 0) 
							{ 
								$res = B2DB::getTable('B2tMessages')->doSelectById($msg, $crit);
								$msgs[] = $res;
							}
							else
							{
								$crit->addOrderBy(B2tMessages::SENT, 'desc');
								$crit->addOrderBy(B2tMessages::ID, 'desc');
								$res = B2DB::getTable('B2tMessages')->doSelect($crit);
								$msgs = $res->getAllRows();
							}
							break;
	
					}
					return $msgs;
	
				default:
	
			}
		}
	
		public function getFolders($uid, $pid = null)
		{
			$crit = new B2DBCriteria();
			$crit->addWhere(B2tMessageFolders::UID, $uid);
			$crit->addOrderBy(B2tMessageFolders::FOLDERNAME, 'asc');
			if ($pid !== null)
			{
				$crit->addWhere(B2tMessageFolders::PARENT_FOLDER, $pid);
			}
			$res = B2DB::getTable('B2tMessageFolders')->doSelect($crit);
			$mfs = array();
			while ($row = $res->getNextRow())
			{
				$mfs[] = array('id' => $row->get(B2tMessageFolders::ID), 'foldername' => $row->get(B2tMessageFolders::FOLDERNAME));
			}
			return $mfs;
		}
	
		public function addFolder($folder_name)
		{
			$crit = new B2DBCriteria();
			$crit->addInsert(B2tMessageFolders::UID, BUGScontext::getUser()->getUID());
			$crit->addInsert(B2tMessageFolders::FOLDERNAME, $folder_name);
			$res = B2DB::getTable('B2tMessageFolders')->doInsert($crit);
	
			return $res->getInsertID();
		}
	
		public function deleteFolder($folder_id, $force = false)
		{
			if (!$force)
			{
				$crit = new B2DBCriteria();
				$crit->addWhere(B2tMessageFolders::UID, BUGScontext::getUser()->getUID());
				$crit->addWhere(B2tMessageFolders::ID, $folder_id);
				$res = B2DB::getTable('B2tMessageFolders')->doDelete($crit);
			}
			else
			{
				$res = B2DB::getTable('B2tMessageFolders')->doDeleteById($folder_id);
			}
		}
	
		public function deleteMessage($msg_id, $force = false)
		{
			if (!$force)
			{
				$crit = new B2DBCriteria();
				$crit->addWhere(B2tMessages::TO_USER, BUGScontext::getUser()->getUID());
				$crit->addWhere(B2tMessages::ID, $msg_id);
				$res = B2DB::getTable('B2tMessages')->doDelete($crit);
			}
			else
			{
				$res = B2DB::getTable('B2tMessages')->doDeleteById($msg_id);
			}
		}
	
		public function setRead($msg_id, $read)
		{
			$crit = new B2DBCriteria();
			$crit->addUpdate(B2tMessages::IS_READ, $read);
			$crit->addWhere(B2tMessages::ID, $msg_id);
			$crit->addWhere(B2tMessages::TO_USER, BUGScontext::getUser()->getUID());
			B2DB::getTable('B2tMessages')->doUpdate($crit);
		}
	
		public function moveMessage($msg_id, $to_folder)
		{
			$crit = new B2DBCriteria();
			$crit->addUpdate(B2tMessages::FOLDER, $to_folder);
			$crit->addWhere(B2tMessages::ID, $msg_id);
			$crit->addWhere(B2tMessages::TO_USER, BUGScontext::getUser()->getUID());
			B2DB::getTable('B2tMessages')->doUpdate($crit);
		}
	
		public function sendMessage($to_id, $to_team, $title, $content)
		{
			$now = $_SERVER["REQUEST_TIME"];
	
			$crit = new B2DBCriteria();
			$crit->addInsert(B2tMessages::FROM_USER, BUGScontext::getUser()->getUID());
			$crit->addInsert(B2tMessages::TITLE, $title);
			$crit->addInsert(B2tMessages::BODY, $content);
			$crit->addInsert(B2tMessages::SENT, $now);
			if ($to_team == 0)
			{
				$crit->addInsert(B2tMessages::FOLDER, 1);
				$crit->addInsert(B2tMessages::TO_USER, $to_id);
				B2DB::getTable('B2tMessages')->doInsert($crit);
			}
			else
			{
				$theTeam = BUGSfactory::teamLab($to_id);
				
				$crit->addInsert(B2tMessages::FOLDER, 4);
				$crit->addInsert(B2tMessages::TO_TEAM, $to_id);
				foreach ($theTeam->getMembers() as $anUid)
				{
					$crit2 = clone $crit;
					$crit2->addInsert(B2tMessages::TO_USER, $anUid);
					B2DB::getTable('B2tMessages')->doInsert($crit2);
				}
			}
		}
	
		public function countMessages($folder_id, $tid = null)
		{
			$crit = new B2DBCriteria();
			$crit->setDistinct();
			$crit->addWhere(B2tMessages::FOLDER, (int) $folder_id);
			$crit->addWhere(B2tMessages::DELETED, 0);
			$crit->addWhere(B2tMessages::TO_USER, (int) BUGScontext::getUser()->getUID());
			if ($tid !== null)
			{
				$crit->addWhere(B2tMessages::TO_TEAM, $tid);
			}
			
			$unread_count = 0;
			$total_count = 0;
			$frombuds_count = 0;
			$urgent_count = 0;
			
			if ($res = B2DB::getTable('B2tMessages')->doSelect($crit))
			{
				while ($row = $res->getNextRow())
				{
					if ($row->get(B2tMessages::IS_READ) == 0)
					{
						$unread_count++;
						if (BUGScontext::getUser()->isFriend($row->get(B2tMessages::FROM_USER)))
						{
							$frombuds_count++;
						}
						if ($row->get(B2tMessages::URGENT) == 1)
						{
							$urgent_count++;
						}
					}
					$total_count++;
				}
			}

			return array('total' => $total_count, 'unread' => $unread_count, 'frombuds' => $frombuds_count, 'urgent' => $urgent_count);
		}
		
		public function section_teamactionsBottom($vars)
		{
			if (!is_array($vars)) throw new Exception('something went wrong');
			$tid = array_shift($vars);
			$closemenustring = array_shift($vars);
			$msgstring = '';
			/*MESSAGES_NEWMSGWINDOWSTRING_TOUSER_STRING;
			$msgstring = str_replace("{uid}", $tid . "&sendto_team=1", $msgstring);*/
			if (BUGSuser::isThisGuest() == false)
			{
				$retval = '<div style="padding: 2px;"><a href="javascript:void(0);" onclick="' . $msgstring . $closemenustring . '">' . BUGScontext::getI18n()->__('Send a message to this team') . '</a></div>';
			}
			return $retval;
		}
		
		public function section_useractionsBottom($vars)
		{
			if (!is_array($vars)) throw new Exception('something went wrong');
			$user = array_shift($vars);
			$closemenustring = array_shift($vars);
			$msgstring = '';
			$retval = '';
			/*MESSAGES_NEWMSGWINDOWSTRING_TOUSER_STRING;
			$msgstring = str_replace("{uid}", $user->getID(), $msgstring);*/
			if (BUGSuser::isThisGuest() == false && $user->isGuest() == false)
			{
				$retval = '<div style="padding: 2px;"><a href="javascript:void(0);" onclick="' . $msgstring . $closemenustring . '">' . BUGScontext::getI18n()->__('Send a message to this user') . '</a></div>';
			}
			return $retval;
		}
		
		public function section_accountSettingsList()
		{
			include_template('messages/accountsettingslist');
		}
		
		public function section_accountSettings($module)
		{
			if ($module != $this->getName()) return;
			if (BUGScontext::getRequest()->getParameter('viewmode'))
			{
				BUGScontext::getModule('messages')->saveSetting('viewmode', BUGScontext::getRequest()->getParameter('viewmode'), BUGScontext::getUser()->getUID());
			}
			?>
			<table style="table-layout: fixed; width: 100%; background-color: #F1F1F1; margin-top: 15px; border: 1px solid #DDD;" cellpadding=0 cellspacing=0>
			<tr>
			<td style="padding-left: 4px; width: 20px;"><?php echo image_tag('mail_nonew.png'); ?></td>
			<td style="border: 0px; width: auto; padding: 3px; padding-left: 7px;"><b><?php echo BUGScontext::getI18n()->__('Messages settings'); ?></b></td>
			</tr>
			</table>
			<form accept-charset="<?php echo BUGScontext::getI18n()->getCharset(); ?>" action="account.php" method="post">
			<input type="hidden" name="settings" value="<?php echo $this->getName(); ?>">
			<table class="b2_section_miniframe" cellpadding=0 cellspacing=0>
			<tr>
			<td style="width: 200px;"><b><?php echo BUGScontext::getI18n()->__('Message center layout'); ?></b></td>
			<td style="width: 200px;"><select name="viewmode" style="width: 100%;">
			<option value=1 <?php if (BUGSsettings::get('viewmode', 'messages', null, BUGScontext::getUser()->getUID()) == 1) echo ' selected'; ?>><?php echo BUGScontext::getI18n()->__('Standard layout'); ?></option>
			<option value=2 <?php if (BUGSsettings::get('viewmode', 'messages', null, BUGScontext::getUser()->getUID()) == 2) echo ' selected'; ?>><?php echo BUGScontext::getI18n()->__('Wide layout'); ?></option>
			</select>
			</td>
			</tr>
			<tr>
			<td colspan=2 style="text-align: right;"><input type="submit" value="<?php echo BUGScontext::getI18n()->__('Save'); ?>"></td>
			</tr>
			</table>
			</form>
			<?php
		}

		public function section_messagesSummary()
		{
			include_template('messages/messagessummary', array('messages' => $this->countMessages(1)));
		}
		
		public function section_messagesBox()
		{
			if (BUGSuser::isThisGuest() == false)
			{
				?>
				<table class="b2_section_miniframe" cellpadding=0 cellspacing=0>
				<tr>
				<td class="b2_section_miniframe_header"><?php echo BUGScontext::getI18n()->__('Message central'); ?></td>
				</tr>
				<tr>
				<td class="td1">
				<?php
	
					$messages = $this->countMessages(1);

				?>
				<table cellpadding=0 cellspacing=0 style="width: 100%;">
				<tr>
				<td class="imgtd">
				<?php 
				
				if ($messages['unread'] >= 1)
				{
					echo image_tag('mail_new.png');
				}
				else
				{
					echo image_tag('mail_nonew.png');
				}

				?>
				</td>
				<td><a href="modules/messages/messages.php?select_folder=1"><b><?php echo BUGScontext::getI18n()->__('Inbox:'); ?></b></a>&nbsp;<?php print $messages['total']; ?>&nbsp;<?php print ($messages['unread'] > 0) ? "<b>(" . BUGScontext::getI18n()->__('%number_of% new', array('%number_of%' => $messages['unread'])) . ")</b>" : ""; ?></td>
				</tr>
				<?php
				if ($messages['urgent'] > 0 || $messages['frombuds'] > 0)
				{
					?>
					<tr>
					<td>&nbsp;</td>
					<td><?php
	
					if ($messages['urgent'] > 0)
					{
						echo '<b style="color: #A55;">' . BUGScontext::getI18n()->__('%number_of% urgent message(s)', array('%number_of%' => $messages['urgent'])) . "</b><br>";
					}
					if ($messages['frombuds'] > 0)
					{
						echo BUGScontext::getI18n()->__('%number_of% message(s) from people you know', array('%number_of%' => $messages['frombuds']));
					}
	
					?></td>
					</tr>
					<?php
				}
	
				if (count(BUGScontext::getUser()->getTeams()) >= 1)
				{
					$teammessages = $this->countMessages(4);
					?>
					<tr>
					<td class="imgtd">
					<?php
					
					if ($teammessages['unread'] > 0)
					{
						echo image_tag('mail_new.png');
					}
					else
					{
						echo image_tag('mail_nonew.png');
					}

					?>
					</td>
					<td><a href="modules/messages/messages.php?select_folder=4&amp;team_id=0"><b><?php echo BUGScontext::getI18n()->__('Team inbox:'); ?></b></a>&nbsp;<?php print $teammessages['total']; ?>&nbsp;<?php print ($teammessages['unread'] > 0) ? "<b>(" . BUGScontext::getI18n()->__('%number_of% new', array('%number_of%' => $teammessages['unread'])) . ")</b>" : ""; ?></td>
					</tr>
					<?php
					foreach (BUGScontext::getUser()->getTeams() as $tid)
					{
						$teammessages = $this->countMessages(4, $tid);
						if ($teammessages['unread'] > 0)
						{
							$team = BUGSfactory::teamLab($tid);
							?>
							<tr>
							<td>&nbsp;</td>
							<td><?php echo BUGScontext::getI18n()->__('%teamname%: %number_of% new', array('%teamname%' => $team->getName(), '%number_of%' => $teammessages['unread'])); ?><br></td>
							</tr>
							<?php
						}
					}
				}
				?>
				<tr>
				<td class="imgtd" style="padding-top: 15px;"><?php echo image_tag('msg_unknown.png'); ?></td>
				<td style="padding-top: 15px;"><a href="javascript:void(0);" onclick="<?php //print MESSAGES_NEWMSGWINDOWSTRING; ?>"><?php echo BUGScontext::getI18n()->__('Write a new message'); ?></a></td>
				</tr>
				</table>
				</td>
				</tr>
				</table>
				<?php
			}
		}
		
	}
?>