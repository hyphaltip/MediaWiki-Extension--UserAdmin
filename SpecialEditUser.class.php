<?php

/**
 * UserAdmin is a MediaWiki extension which allows administrators to add and 
 * delete users (e.g. spam or unused accounts), change user passwords, edit user 
 * details (e.g. username, real name or email), edit user groups, resend emails 
 * (e.g. reset password email or welcome message email). This extension is 
 * primarily for administrators of private wikis that require tighter control of 
 * user accounts.
 *
 * Usage:
 * 	require_once("extensions/UserAdmin/UserAdmin.php"); in LocalSettings.php
 *
 * @file
 * @ingroup Extensions
 * @link http://www.mediawiki.org/wiki/Extension:UserAdmin   Documentation
 * @author Lance Gatlin <lance.gatlin@gmail.com>
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @version 0.1.0
*/

/*
 * Special page to edit a user
 */
class SpecialEditUser extends SpecialUADMBase {

  function __construct() {
    parent::__construct('EditUser', 'createaccount');
  }
  
  /*
   * Get the parameters and their default values for a GET
   * 
   * @return array key-value parameters with default value
   */
  function getParamsGET()
  {
    return array(
      'userid' => '',
      'username' => '',
      'realname' => '',
      'groups' => array(),
      'pwdaction' => 'nochange',
      'email' => '',
      'reason' => '',
      'returnto' => $this->getDefaultReturnTo(),
      'preview' => '',
    );
  }
  
  /*
   * Get the parameters and their default values for a POST
   * 
   * @return array key-value parameters with default value
   */
  function getParamsPOST()
  {
    // Have to list these here otherwise they will never get read
    // from wgRequest
    return array(
      'action' => '',
      'userid' => '',
      'username' => '',
      'realname' => '',
      'email' => '',
      'groups' => array(),
      'pwdaction' => '',
      'password1' => '',
      'password2' => '',
      'reason' => '',
      'edittoken' => '',
      'returnto' => $this->getDefaultReturnTo(),
    );
  }

  /*
   * Helper function to validate get parameters; throws on invalid
   * 
   * @return User object requested for edit based on parameters OR null
   * if user not found
   */
  function validateGETParams()
  {
    if(!empty($this->returnto))
    {
      $title = Title::newFromText($this->returnto);
      if(!is_object($title) || !$title->isKnown())
        $this->returnto = $this->mDefaultParams['returnto'];
    }
    
    if(!empty($this->userid))
    {
      $user = User::newFromId($this->userid);
      if(!$user->loadFromId())
        throw new InvalidGETParamException(wfMsg('uadm-invaliduseridmsg',$this->userid), $this->copyParamsAndRemoveBadParam('userid'));
      
      return $user;
    }
    
    if(!empty($this->subpage))
    {
      $user = User::newFromName($this->subpage);
      if(!is_object($user) || $user->getId() == 0)
        throw new InvalidGETParamException(wfMsg('uadm-usernoexistmsg', $this->subpage), $this->copyParamsAndRemoveBadParam('subpage'));
      return $user;
    }
    
    if(!empty($this->username))
    {
      $user = User::newFromName($this->username);
      if(!is_object($user) || $user->getId() == 0)
        throw new InvalidGETParamException(wfMsg('uadm-usernoexistmsg', $this->username), $this->copyParamsAndRemoveBadParam('username'));
      return $user;
    }
    
    return null;
  }
  
  /*
   * Display the edit user form
   * 
   * @return string HTML
   */
  function doGET() 
  {
    global $wgLang, $wgOut, $wgUser;
    
    $user = $this->validateGETParams();
    
    $searchFormHTML = $this->getSearchFormHTML(wfMsg('uadm-finduserlabel'));
    
    $returnToHTML = '';
    $backHTML = '';
    if(!empty($this->returnto))
    {
      $returnToHTML = self::parse(wfMsg('uadm-returntomsg', $this->returnto));
      $backHTML = $this->parse("[[$this->returnto|< $this->backactionlabel]] | ");
    }
    
    if(!is_object($user))
      return <<<EOT
$searchFormHTML
$returnToHTML
EOT;
    
    // Suppress search form if editing a user and returnto specified 
    if(!empty($this->returnto))
      $searchFormHTML = '';
    
    $user->loadGroups();

    $id = $user->getId();
    $this->userid = $id;
    $this->params['userid'] = $id;

    // user editable parameters
    $userName = $user->getName();
    $realName = $user->getRealName();
    $email = $user->getEmail();
    $groups = $user->getGroups();
    
    // If userid was used to load the user then prefer URL
    // query parameters to preserve unsaved changes since preview 
    // POST was selected then redirected back to GET
    if(!empty($this->userid))
    {
      if(!empty($this->username)) $userName = $this->username;
      if(!empty($this->realname)) $realName = $this->realname;
      if(!empty($this->email)) $email = $this->email;
      if(!empty($this->groups)) $groups = $this->groups;
    }
    
    $emailAuthDate = $user->getEmailAuthenticationTimestamp();
    
    $groupsHTML = '';
    foreach(User::getAllGroups() as $groupName)
    {
      $checked = in_array($groupName, $groups) ? 'checked' : '';
      $localName = User::getGroupMember($groupName);
      $groupsHTML.= <<<EOT
<input id="grp$groupName" type="checkbox" name="groups[]" value="$groupName" $checked/> <label for="grp$groupName">$localName</label><br/>
EOT;
    }

    $unconfirmed = $user->isEmailConfirmationPending() ? '[' . $this->pendinglabel . ']' : '';
    $userPageURL = $user->getUserPage()->getLocalURL();
    $editCount = $user->getEditCount();
    $userTouchedDate = $user->getTouched();
    $userTouchedDate = $wgLang->timeanddate($userTouchedDate, true);
    $createDate = $user->getRegistration();
    $createDate = $wgLang->timeanddate($createDate, true);
    if ($editCount > 0) {
      $lastEditDate = self::getUserLastEditTimestamp($user);
      $lastEditDate = $wgLang->timeanddate($lastEditDate, true);
    }
    else
      $lastEditDate = '';

    $contribsHref = $this->getSpecialPageURL('Contributions', $userName);
    $logsHref = $this->getSpecialPageURL('Log',$userName);
    $groupsHref = $this->getSpecialPageURL('UserRights',$userName);
    
    $userPageHref = $user->getUserPage()->getLocalURL();
    $userTalkPageHref = $user->getUserPage()->getTalkPage()->getLocalURL();
    $blockHref = $this->getSpecialPageURL('Block',$userName);
    $deleteHref = $this->getSpecialPageURL('DeleteUser',$userName);
    $logsHref = $this->getSpecialPageURL('Log',$userName);
    $checkuserHref = $this->getSpecialPageURL('CheckUser',$userName);
    
    $pwdtitleHref = Title::newFromText('passwordremindertitle', NS_MEDIAWIKI)->getLocalURL();
    $pwdtextHref = Title::newFromText('passwordremindertext', NS_MEDIAWIKI)->getLocalURL();
    $welcomeTitleHref = Title::newFromText('createaccount-title', NS_MEDIAWIKI)->getLocalURL();
    $welcomeTextHref = Title::newFromText('createaccount-text', NS_MEDIAWIKI)->getLocalURL();
    
    $returnToHTML = '';
    $backHTML = '';
    if(!empty($this->returnto))
    {
      $returnToHTML = self::parse(wfMsg('uadm-returntomsg', $this->returnto));
      $searchFormHTML = '';
      $backHTML = $this->parse("[[$this->returnto|< $this->backactionlabel]] | ");
    }
    
    $postURL = $this->getURL($this->params);
    
    $editToken = $wgUser->editToken($this->userid);

//    $previewPasswordEmailHref = $this->getURL(array('preview' => 'password') + $this->params);
//    $previewWelcomeEmailHref = $this->getURL(array('preview' => 'welcome') + $this->params);
    
    $previewPasswordEmailHTML = '';
    $previewWelcomeEmailHTML = '';
    if(!empty($this->preview))
    {
      switch($this->preview)
      {
        case 'password' :
          list($subject, $body) = $this->getPasswordMailMessage($user);
          break;
        case 'welcome' :
          list($subject, $body) = $this->getWelcomeMailMessage($user);
          break;
      }
    
      $previewHTML=<<<EOT
<table>
  <tr>
    <td>$this->subjectlabel</td>
    <td><input value="$subject" size="70" disabled="disabled"/></td>
  <tr>
    <td colspan="2"><textarea rows="10" cols="80" disabled="disabled">$body</textarea></td>
  </tr>
</table>
EOT;
    
      switch($this->preview)
      {
        case 'password' :
          $previewPasswordEmailHTML = $previewHTML;
          break;
        case 'welcome' :
          $previewWelcomeEmailHTML = $previewHTML;
          break;
      }
    }
    
    $pwdSetPasswordChecked = '';
    $pwdEmailPasswordChecked = '';
    $pwdEmailWelcomeChecked = '';
    $pwdNoChangeChecked = '';
    switch($this->pwdaction)
    {
      case 'manual' :
        $pwdSetPasswordChecked = 'checked';
        break;
      case 'email' :
        $pwdEmailPasswordChecked = 'checked';
        break;
      case 'emailwelcome' :
        $pwdEmailWelcomeChecked = 'checked';
        break;
      case 'nochange' :
        $pwdNoChangeChecked = 'checked';
        break;
    }
    
    $subtitle =<<<EOT
$backHTML<a href="$userPageHref"><b>$userName</b></a> (<a href="$userTalkPageHref">$this->talkactionlabel</a> | <a href="$blockHref">$this->blockactionlabel</a> | <a href="$deleteHref">$this->deleteactionlabel</a> | <a href="$logsHref">$this->logsactionlabel</a> | <a href="$contribsHref">$this->contributionsactionlabel</a> | <a href="$checkuserHref">$this->ipsactionlabel</a>) 
EOT;
    
    $wgOut->setSubtitle($subtitle);
    
    return <<<EOT
<form id="edituserform" name="input" action="$postURL" method="post" class="visualClear">
  <input type="hidden" name="edittoken" value="$editToken"/>
  <fieldset>
    <legend>$this->edituserlabel:</legend>
    <table>
      <tr>
        <td><label for="userid">$this->useridfield:</label></td>
        <td><input id="userid" type="text" name="userid" value="$id" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="username">$this->usernamefield:</label></td>
        <td><input id="username" type="text" name="username" value="$userName" size="30"/> $this->requiredlabel<br/></td>
      </tr>
      <tr>
        <td><label for="realname">$this->realnamefield:</label></td>
        <td><input id="realname" type="text" name="realname" value="$realName" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="email">$this->emailfield:</label></td>
        <td><input id="email" type="text" name="email" value="$email" size="30"/> $this->requiredlabel<br/></td>
      </tr>
      <tr>
        <td><label for="emailauthdate">$this->emailauthdatefield:</label></td>
        <td><input id="emailauthdate" type="text" value="$emailAuthDate" size="30" disabled="disabled"/><br/></td>
      </tr>
      <tr>
        <td><label for="createdate">$this->createddatefield:</label></td>
        <td><input id="createdate" type="text" value="$createDate" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="usertouched">$this->usertoucheddatefield:</label></td>
        <td><input id="usertouched" type="text" value="$userTouchedDate" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="lasteditdate">$this->lasteditdatefield:</label></td>
        <td><input id="lasteditdate" type="text" value="$lastEditDate" disabled="disabled" size="30"/><br/></td>
      </tr>
      <tr>
        <td><label for="editcount">$this->editcountfield:</label></td>
        <td><input id="editcount" type="text" value="$editCount" disabled="disabled" size="30"/><br/></td>
      </tr>
    </table>
    <fieldset>
      <legend>$this->editgroupslabel:</legend>
      $groupsHTML
    </fieldset>
    <fieldset>
      <legend>$this->editpasswordlabel:</legend>
      <input id="pwdmanual" type="radio" name="pwdaction" value="manual" $pwdSetPasswordChecked/> <label for="pwdmanual">$this->setpasswordforuserlabel:</label><br/>
        <table>
          <tr>
            <td><label for="password1">$this->passwordlabel:</label></td>
            <td><input id="password1" type="password" name="password1" size="30"/></td>
          </tr>
          <tr>
            <td><label for="password2">$this->verifypasswordlabel:</label></td>
            <td><input id="password2" type="password" name="password2" size="30"/></td>
          </tr>
        </table>
      <input id="pwdemail" type="radio" name="pwdaction" value="email" $pwdEmailPasswordChecked/> <label for="pwdemail">$this->emailpasswordlabel</label> <button type="submit" name="action" value="emailpwdpreview">$this->previewactionlabel</button>(<a href="$pwdtitleHref">$this->subjectlabel</a> | <a href="$pwdtextHref">$this->bodylabel</a>)<br/>
      $previewPasswordEmailHTML
      <input id="pwdemailwelcome" type="radio" name="pwdaction" value="emailwelcome" $pwdEmailWelcomeChecked/> <label for="pwdemailwelcome">$this->emailwelcomelabel</label> <button type="submit" name="action" value="emailwelcomepreview">$this->previewactionlabel</button>(<a href="$welcomeTitleHref">$this->subjectlabel</a> | <a href="$welcomeTextHref">$this->bodylabel</a>)<br/>
      $previewWelcomeEmailHTML
      <input id="pwdnochange" type="radio" name="pwdaction" value="nochange" $pwdNoChangeChecked/> <label for="pwdnochange">$this->nochangetopasswordlabel</label><br/>
    </fieldset>
    <label for="reason">$this->reasonlabel:</label> <input id="reason" type="text" name="reason" size="60" maxlength="255" value="$this->reason"/> $this->requiredlabel<br/>
    <button type="submit" name="action" value="saveuser">$this->saveuserlabel</button>
  </fieldset>
</form>
$searchFormHTML
$returnToHTML
EOT;
    }

  /*
   * Helper function to validate POST parameters
   */
  function validatePOSTParams()
  {
    global $wgUser;
    
    $user = User::newFromId($this->userid);
    if(!$user->loadFromId())
      throw new InvalidPOSTParamException(wfMsg('uadm-failedtoloadfromidmsg', $this->userid));
    
    // Validate FORM 
    if(empty($this->username))
      throw new InvalidPOSTParamException(wfMsg('uadm-fieldisrequiredmsg',$this->usernamefield));
    
    // changing user name?
    if($user->getName() != $this->username)
    {
      // check if its already being used
      if(User::idFromName($this->username) !== null)
        throw new InvalidPOSTParamException(wfMsg('uadm-usernameinusemsg', $this->username));

      if(!User::isCreatableName($this->username))
        throw new InvalidPOSTParamException(wfMsg('uadm-invalidusernamemsg',$this->usernamefield));
    }
    
//    if(!$wgUser->matchEditToken(stripslashes($this->edittoken), $this->userid))
    if(!$wgUser->matchEditToken($this->edittoken, $this->userid))
      throw new InvalidPOSTParamException(wfMsg('uadm-formsubmissionerrormsg'));
    
    if(empty($this->email))
      throw new InvalidPOSTParamException(wfMsg('uadm-fieldisrequiredmsg',$this->emailfield));

    if(!User::isValidEmailAddr($this->email))
      throw new InvalidPOSTParamException(wfMsg('uadm-invalidemailmsg',$this->emailfield));

    if(empty($this->reason))
      throw new InvalidPOSTParamException(wfMsg('uadm-fieldisrequiredmsg',$this->reasonfield));
    
    if(empty($this->pwdaction))
      throw new InvalidPOSTParamException(wfMsg('uadm-formsubmissionerrormsg'));
      
    if($this->pwdaction == 'manual')
    {
      if(empty($this->password1) || empty($this->password2))
        throw new InvalidPOSTParamException(wfMsg('uadm-fieldisrequiredmsg',$this->passwordfield));
      
      if($this->password1 != $this->password2)
        throw new InvalidPOSTParamException(wfMsg('uadm-passwordsmustmatchmsg'));
      
//      $result = $user->checkPassword($this->password1);
//      if($result !== true)
//        throw new InvalidPOSTParamException(wfMsg('uadm-invalidpasswordmsg'));
    }
    
    return $user;
  }
  
  /*
   * Edit a user according to POST parameters
   * 
   * @return string URL to redirect to
   */
  function doPOST()
  {
    global $wgUser;

    $user = $this->validatePOSTParams();
    
    switch($this->action)
    {
      case 'emailpwdpreview' :
        return $this->getURL(array('preview' => 'password', 'pwdaction' => 'email') + $this->params);
      case 'emailwelcomepreview' :
        return $this->getURL(array('preview' => 'welcome', 'pwdaction' => 'emailwelcome') + $this->params);
      default :
        throw new InvalidPOSTParamException(wfMsg('uadm-formsubmissionerrormsg'));
      case 'saveuser' :
        break;
    }
    
    $log = new LogPage( 'rights' );
    $changesMade = false;
    
    $userName = $user->getName();
    
    // Apply parameters that have changed
    if($user->getName() != $this->username)
    {
      $oldName = $user->getName();
      $user->setName($this->username);
      $newName = $user->getName();
      
      $log->addEntry( 
        'changedusernamelog',
        $user->getUserPage(),
        $this->reason,
        array(
          $this->userid,
          $oldName,
          $newName,
        )
      );

      $changesMade = true;
    }
    
    if($user->getRealName() != $this->realname)
    {
      $oldRealName = $user->getRealName();
      $user->setRealName($this->realname);      
      $newRealName = $user->getRealName();
      
      $log->addEntry( 
        'changeduserrealnamelog',
        $user->getUserPage(),
        $this->reason,
        array(
          $oldRealName,
          $newRealName,
        )
      );

      $changesMade = true;
    }
    
    if($user->getEmail() != $this->email)
    {
      $oldEmail = $user->getEmail();
      $user->setEmail($this->email);
      $newEmail = $user->getEmail();
      
      $log->addEntry( 
        'changeduseremaillog',
        $user->getUserPage(),
        $this->reason,
        array(
          $oldEmail,
          $newEmail,
        )
      );

      $changesMade = true;
    }
   
    $successWikiText = array();
    if($changesMade)
      $successWikiText[] = wfMsg('uadm-changestousersuccessmsg', $this->username);
    
    switch($this->pwdaction)
    {
      case 'manual' :
        try {
          $user->setPassword($this->password1);
          $changesMade = true;
        }
        catch(PasswordError $pe)
        {
          return $this->getPOSTRedirectURL(false, wfMsg('uadm-passworderrormsg') . $pe->getText());
        }
        $log->addEntry( 
          'changeduserpasswordlog',
          $user->getUserPage(),
          $this->reason,
          array(
          )
        );
        $successWikiText[] = wfMsg('uadm-passwordchangesuccessmsg',$this->username);
        break;
      
      case 'email' :
        $result = self::mailPassword($user);

        if( WikiError::isError( $result ) )
          return $this->getPOSTRedirectURL(false, wfMsg( 'uadm-mailerror', $result->getMessage() ) );

        $changesMade = true;
        
        $log->addEntry( 
          'emailpasswordlog',
          $user->getUserPage(),
          $this->reason,
          array(
          )
        );
        $successWikiText[] = wfMsg('uadm-passwordemailsuccessmsg', $this->username, $this->email);
        break;
        
      case 'emailwelcome' :
        $result = self::mailWelcomeAndPassword($user);

        if( WikiError::isError( $result ) )
          return $this->getPOSTRedirectURL( false, wfMsg( 'uadm-mailerror', $result->getMessage() ) );

        $changesMade = true;
        
        $log->addEntry( 
          'emailwelcomelog',
          $user->getUserPage(),
          $this->reason,
          array(
          )
        );
        $successWikiText[] = wfMsg('uadm-welcomeemailsuccessmsg', $this->username, $this->email);
        break;
    }
    
    $user->loadGroups();
    $currentGroups = $user->getGroups();
    $remove = array();
    $add = array();
    foreach($currentGroups as $groupName)
    {
      if(!in_array($groupName, $this->groups))
        $remove[] = $groupName;
    }
    foreach($this->groups as $groupName)
    {
      if(!in_array($groupName, $currentGroups))
        $add[] = $groupName;
    }
    
    if(count($remove) > 0|| count($add) > 0)
    {
      $userrightsPage = new UserrightsPage;    
      $userrightsPage->doSaveUserGroups($user, $add, $remove, $this->reason);
      $successWikiText[] = wfMsg('uadm-changestogroupsuccessmsg', $this->username);
    }
    
    if($changesMade)
      $user->saveSettings();
    
    $successWikiText = implode('<br/>', $successWikiText);
    
    return $this->getPOSTRedirectURL(true, $successWikiText);
  }
}