<?php
/**
 * PiwikPlugin Plugin for LimeSurvey
 *
 * @author Steve Cohen <https://github.com/SteveCohen>
 * @author Denis Chenu <http://sondages.pro>
 *
 * @copyright 2015 Steve Cohen <https://github.com/SteveCohen>
 * @license GPL v3
 * @version 1.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 */
class PiwikPlugin extends PluginBase {
	protected $storage = 'DbStorage';
	static protected $description = 'Adds Piwik tracking codes to Limesurvey pages.';
	static protected $name = 'Piwik for Limesurvey';

	protected $settings = array(
		'piwik_title_BasicSettings'=>array(
			'type'=>'info',
			'content'=>'<legend><small>Basic Piwik settings</small></legend><p>Global settings for this plugin</p>'
		),
		'piwik_trackAdminPages' => array(
			'type'=>'select',
			'options'=>array(0=>'No',1=>'Yes'),
			'label'=>'Track Admin pages',
			'default'=> 1
		),
		'piwik_trackSurveyPages' => array(
			'type'=>'select',
			'options'=>array(0=>'No',1=>'Yes'),
			'label'=>'Track Survey pages by default <br><small>(Survey admins can override this)</small>',
			'default'=> 1
		),
		'piwik_piwikURL'=>array(
			'type'=>'string',
			'label'=>'URL to Piwik\'s directory <br/><small>(Can be relative or absolute)</small>',
			'default'=>'/piwik/'
		),
		'piwik_siteID'=>array(
			'type'=>'int',
			'label'=>"Piwik SiteId  <br/>(<small><a href='http://piwik.org/faq/general/faq_19212/' target='_new'>What should you put here?</a></small>)",
			'default'=>1
		),
		/* Coming soon: Enable or diable admins from changing settings.
        'piwik_suveyAdminCanChangeSettings' => array(
            'type'=>'select',
            'options'=>array(1=>'Yes',0=>'No'),
            'label'=>'Allow Survey Administrators to change these settings for their surveys',
            'default'=> 1
        ),
*/

		//---------- Event and Content Tracking settings----------
		'piwik_rewriteURLs'=>array(
			'type'=>'select',
			'options'=>array(0=>'No',1=>'Yes'),
			'label'=>'Rewrite URLs to store more information in Piwik',
			'default'=>1
		),
		'piwik_title_EventAndContentTracking'=>array(
			'type'=>'info',
			'content'=>'<legend><small>Content and Event tracking</small></legend>'
		),
		'piwik_trackContent' => array(
			'type'=>'select',
			'options'=>array(0=>'No',1=>'Yes'),
			'label'=>'Track respondents\' interactions with answer options<br/><small>Uses Piwik <i>content tracking</i> on answer options</small>',
			'default'=> 1
		),
		'piwik_trackEvents' => array(
			'type'=>'select',
			'options'=>array(0=>'No',1=>'Yes'),
			'label'=>'Track important survey events<br/><small>Uses Piwik <i>event tracking</i> on selected survey events</small>',
			'default'=> 1
		),
		'piwik_trackEventsCategory' => array(
			'type'=>'string',
			'label'=>'Piwik Event category to use for survey events',
			'default'=> 'Survey'
		),
/*
		'piwik_saveResponseIDtoPiwik'=>array(
			'type'=>'select',
			'options'=>array(0=>'No',1=>'Yes'),
			'label'=>'Store users\' ResponseID in Piwik<br/><small>Sets Piwik\'s <i><a href="http://piwik.org/docs/user-id/">User ID</a></i> to be the LimeSurvey Response ID',
			'default'=> 1
		)
*/
	);

	private $registeredTrackingCode;

	public function __construct(PluginManager $manager, $id)
	{
		parent::__construct($manager, $id);
		$this->subscribe('newSurveySettings');
		$this->subscribe('afterPluginLoad');
		$this->subscribe('beforeSurveyPage');
		$this->subscribe('beforeSurveySettings');
		$this->subscribe('afterSurveyComplete');
		$this->subscribe('beforeQuestionRender');
		$this->subscribe('newDirectRequest');
	}

	/******************** Core tracking code *************/
	/* This section contains core code to track URLs, Events and Content. */


	//**** Subscribe functions ***//
	public function beforeSurveySettings()
	{
		$event = $this->getEvent();
		$event->set("surveysettings.{$this->id}", array(
				'name' => get_class($this),
				'settings' => array(
					'piwik_Title_TrackingSettings'=>array(
						'type'=>'info',
						'content'=>'<legend><small>Tracking settings</small></legend>'
					),
					'piwik_trackThisSurvey' => array(
						'type' => 'select',
						'options'=>array(
							0=>'No',
							1=>'Yes'
						),
						'label' => 'Collect web analytics data from respondents',
						'current' => $this->get(
							'piwik_trackThisSurvey', 'Survey', $event->get('survey'), // Survey
							$this->get('piwik_trackSurveyPages', null, null, $this->settings['piwik_trackSurveyPages']['default']) // Global
						),
					)
				)
			));
	}

	public function newSurveySettings()
		{ //28/Jan/2015: To be honest,  I have NO idea what this function does. It's not documented anywhere, but after a lot of trial and error I discovered it _IS NECESSARY_ for any per-survey settings to actually hold. Todo: Perhaps this should be integrated into the core?
		$event = $this->getEvent();
		foreach ($event->get('settings') as $name => $value)
		{
			$this->set($name, $value, 'Survey', $event->get('survey'));
		}
	}

	public function beforeQuestionRender()
	{
		if($this->registeredTrackingCode)
		{
			$iSurveyId=$this->event->get('surveyId');
			$iQid=$this->event->get('qid');
			$sAction=$this->getParam('action');
			if($sAction=='previewquestion')
				$oQuestion = Question::model()->find("qid=:qid",array('qid'=>$iQid));
			$piwikCustomUrl="admin/preview/".$iSurveyId."/question/".$oQuestion->gid;

			$oSurvey=Survey::model()->findByPk($iSurveyId);
			switch ($oSurvey->format)
			{
			case 'A' :
				$piwikCustomUrl="survey/".$iSurveyId."/survey"; //Todo: Cull URL
				break;
			case 'G' :
				$oQuestion = Question::model()->find("qid=:qid",array('qid'=>$iQid));
				$piwikCustomUrl="survey/".$iSurveyId."/group/".$oQuestion->gid; //Todo: Cull URL
				break;
			default :
				$oQuestion = Question::model()->find("qid=:qid",array('qid'=>$iQid));
				$piwikCustomUrl="survey/".$iSurveyId."/group/".$oQuestion->gid."/question/".$iQid; //Todo: Cull URL
			}
			$this->setCustomURL($piwikCustomUrl);
		}
	}

	public function afterPluginLoad(){
		/* Find the active controller with Yii parseUrl */
		/* Remove for admin controller, review for plugins/direct */
		$oRequest=$this->pluginManager->getAPI()->getRequest();
		$sController=Yii::app()->getUrlManager()->parseUrl($oRequest);
		$sAction=$this->getParam('action');
		$bAdminPage= substr($sController, 0, 5)=='admin' || $sAction=='previewgroup' || $sAction=='previewquestion';

		if ( !$bAdminPage || $this->get('piwik_trackAdminPages', null, null, false))
		{
			$this->loadPiwikTrackingCode();
		}
	}

	public function beforeSurveyPage(){
		//Load tracking code on a survey page, or unload it if necessary.
		$event = $this->getEvent();
		$trackThisSurvey=$this->get(
			'piwik_trackThisSurvey', 'Survey', $event->get('surveyId'), // Get this survey setting
			$this->get('piwik_trackSurveyPages', null, null, // If not set the global setting
				$this->settings['piwik_trackSurveyPages']['default'] // If global is not set get the 'default' setting
			)
		);
		if (!$trackThisSurvey && $this->registeredTrackingCode) //Unload the tracking code if it is loaded and shouldn't be.
			{
			$this->unloadPiwikTrackingCode();
		}
		else
		{
			$this->loadEventTracking_moveButtons(); //Track use of prev/back/save/clear buttons in a survey.
		}
	}

	/* Fix some settings when save ir */
	public function saveSettings($settings)
	{
		foreach ($settings as $setting=>$aSetting)
		{
			if(isset($settings['piwik_piwikURL']) && !empty($settings['piwik_piwikURL']) && substr($settings['piwik_piwikURL'], -1)!="/")
			{
				$settings['piwik_piwikURL'].="/";
			}
		}
		parent::saveSettings($settings);
	}

	public function afterSurveyComplete(){
		$event = $this->getEvent();
		$iSurveyId=$event->get('surveyId');
		$responseID=$event->get('responseId');
		$trackThisSurvey=$this->get(
			'piwik_trackThisSurvey', 'Survey', $event->get('surveyId'), // Get this survey setting
			$this->get('piwik_trackSurveyPages', null, null, // If not set the global setting
				$this->settings['piwik_trackSurveyPages']['default'] // If global is not set get the 'default' setting
			)
		);
		$eventTracking=$this->get('piwik_trackEvents',null,null,false); //Todo: enable individual survey settings
		$respondentTracking=$this->get('piwik_saveResponseIDtoPiwik',null,null,false); //Todo: enable individual survey settings
		if($trackThisSurvey)
		{
			if ($eventTracking){
				$eventCategory=$this->get('piwik_trackEventsCategory',null,null,false);
				$js="_paq.push(['trackEvent', '$eventCategory', 'Survey-$iSurveyId', 'Completed']);"; //Event tracking of completed surveys.
				App()->getClientScript()->registerScript('piwikPlugin_Event_Completed',$js,CClientScript::POS_END);
			}
			if ($respondentTracking){
				//Give Piwik the respondent ID upon completion
				//Todo: this seems to be broken??
				//App()->getClientScript()->registerScript('piwik_setresponseID',"_paq.push(['setCustomVariable',1,'Respondent ID','Survey:$iSurveyId Respondent: $responseID','visit']);",CClientScript::POS_END); //Needs to be called before trackPageView, so put at the beginning of the page?
				//App()->getClientScript()->registerScript('piwik_readresponseID',"_paq.push([ function() { alert(this.getCustomVariable(1,'visit')); }]);",CClientScript::POS_END);

			}
			$piwikCustomUrl="survey/{$iSurveyId}/completed";
			$this->setCustomURL($piwikCustomUrl);
		}
	}

	private function getParam($sParam,$default=null)
	{
		$oRequest=$this->pluginManager->getAPI()->getRequest();
		if($oRequest->getParam($sParam))
			return $oRequest->getParam($sParam);
		$sController=Yii::app()->getUrlManager()->parseUrl($oRequest);
		$aController=explode('/',$sController);
		if($iPosition=array_search($sParam,$aController))
			return isset($aController[$iPosition+1]) ? $aController[$iPosition+1] : $default;
		return $default;
	}


	function loadPiwikTrackingCode(){
		$piwikID=trim($this->get('piwik_siteID', null, null, false));
		$piwikURL=trim($this->get('piwik_piwikURL', null, null, false));

		//For comment: Could check if the last character is a slash to ensure it's a directory.
		if (substr($piwikURL,-1)<>"/"){ $piwikURL=""; }

		//Some basic error checking...
		if (!$piwikID || !$piwikURL ) // piwikID must be up to 0 and piwikURL can not be in same directory than LimeSurvey
			{
			App()->getClientScript()->registerScript('piwikPlugin_TrackingCodeError',"console.log('Piwik plugin has not been correctly set up. Please check the settings.');");
		}
		else
		{
			//Generate the tracking code.
			$baseTrackingCode='_paq = _paq || [];'; //PAQ could be set before this from other functions like setCustomURL; if it's not, create it.
			//Continue with the rest of the script.
			$baseTrackingCode="_paq.push(['trackPageView']);
  _paq.push(['enableLinkTracking']);
  (function() {
    var u='".$piwikURL."';
    _paq.push(['setTrackerUrl', u+'piwik.php']);
    _paq.push(['setSiteId', ".$piwikID."]);
    var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];
    g.type='text/javascript'; g.async=true; g.defer=true; g.src=u+'piwik.js'; s.parentNode.insertBefore(g,s);
  })();";
			App()->getClientScript()->registerScript('piwikPlugin_TrackingCode',$baseTrackingCode,CClientScript::POS_END);
			$this->registeredTrackingCode=true; //Not sure really needed but keep track
		}
		$sController=App()->getUrlManager()->parseUrl(App()->getRequest());

		// Construct a custom url
		if(empty($sController))
		{
			$sController=Yii::app()->defaultController;
		}
		$aController=explode("/",$sController);

		$iSurveyId=$this->getParam('sid');
		if(!$iSurveyId)
			$iSurveyId=$this->getParam('surveyid');

		$piwikCustomUrl=$aController[0];
		switch ($piwikCustomUrl)
		{
		case 'survey':
			$piwikCustomUrl.="/".$iSurveyId;
			if($this->getParam('newtest'))
				$piwikCustomUrl.="/new";
			elseif($this->getParam('clearall')){
				$piwikCustomUrl.="/clear";
				$this->trackEventUsingJS('Exit and Clear responses');
			}
			elseif($this->getParam('loadall')){
				$piwikCustomUrl.="/load";
				$this->trackEventUsingJS('Load previous responses (Login page)');
			}
			else
				$piwikCustomUrl.="/".$this->getParam('move','unknown');
			break;
		case "optout":
		case "optin":
			$piwikCustomUrl="survey/".$iSurveyId."/".$piwikCustomUrl; //Todo: Cull URL
			break;
		case "statistics_user":
			$piwikCustomUrl.="/".$iSurveyId;
			break;
		case "printanswers" :
			$piwikCustomUrl.="/".$iSurveyId;
			break;
		case "surveys": // Actually : only survey list
			break;
		case 'plugins': // T
			$piwikCustomUrl=$sController; // Validate if admin, or direct etc ...
			break;
		default:
			$piwikCustomUrl=$sController; // Reset to controller
			break;
		}
		// TODO : Option to set language

		$this->setCustomURL($piwikCustomUrl);
		$this->loadContentTracking_questionanswers();
	}

	function unloadPiwikTrackingCode(){
		App()->getClientScript()->registerScript('piwikPlugin_TrackingCode','',CClientScript::POS_END);
		$this->registeredTrackingCode=false; //false
		//Todo: Unload event tracking, too. Low priority: unloading the tracking code will prevent event tracking from working anyway.
	}



	/******* Page Tracking functions ********/

	function setCustomURL($piwikCustomUrl){
		if ($this->get('piwik_rewriteURLs', null, null, false)){
			App()->getClientScript()->registerScript('piwikCustomUrlVariable',"
            var _paq = _paq || [];
            _paq.push(['setCustomUrl','$piwikCustomUrl']);",CClientScript::POS_BEGIN);
		}
	}

	/******* Event Tracking handlers ********/

	function trackEventUsingPHP(){
		//Tracks an event using PHP
		return false;
	}

	function trackEventUsingJS($eventName,$eventValue=null){
		//Tracks an event immediately, by adding the JS to the page. This then causes the respondent to request piwik.php and track the event.
		//Contrasts to the trackeventUsingphp, which does not involve the respondent at all but does it directly.

		$iSurveyId=$this->getParam('sid');
		if(!$iSurveyId)
			$iSurveyId=$this->getParam('surveyid');
		$eventCategory=$this->get('piwik_trackEventsCategory',null,null,false);
		$scriptName="piwikEventTracking_".$eventName.$eventValue.(string)rand(1,20000); //add a random number to ensure multiple identical events are tracked.
		$js="_paq.push(['trackEvent', '$eventCategory', 'Survey-$iSurveyId', '$eventName','$eventValue']);";
		App()->getClientScript()->registerScript($scriptName,$js,CClientScript::POS_END);
		return $scriptName; //return the name of the script in case we want to edit later.
	}


	function loadEventTracking_moveButtons(){
		//Adds event tracking code to the #moveprevbtn and #movenextbtn.
		//see https://developer.piwik.org/api-reference/events
		//This assumes the IDs in application/helpers/frontend_helper.php will remain constant.
		$iSurveyId=$this->getParam('sid');
		if(!$iSurveyId)
			$iSurveyId=$this->getParam('surveyid');
		$eventTracking=$this->get('piwik_trackEvents',null,null,false);
		if ($eventTracking){
			$eventCategory=$this->get('piwik_trackEventsCategory',null,null,false);
			$js="$('#clearall').on('click',function(){ _paq.push(['trackEvent', '$eventCategory', 'Survey-$iSurveyId', 'Button: Clear responses']); });
$('#saveallbtn').on('click',function(){ _paq.push(['trackEvent', '$eventCategory', 'Survey-$iSurveyId', 'Button: Save responses']); });
$('#moveprevbtn').on('click',function(){ _paq.push(['trackEvent', '$eventCategory', 'Survey-$iSurveyId', 'Button: Previous page']); });
$('#movenextbtn').on('click',function(){_paq.push(['trackEvent', '$eventCategory', 'Survey-$iSurveyId', 'Button: Next page']);});"; //TOODO: add the page it was used on.
			//Do not add an event to'#movesubmitbtn': this is done in the afterSurveyComplete event once all required answers have been answered and submission is confirmed.
			App()->getClientScript()->registerScript('piwikPlugin_Event_ButtonUse',$js,CClientScript::POS_END);
		}
	}

	/******* Content Tracking handlers ********/

	function loadContentTracking_questionanswers(){
		//Tags question input elements as content, to track interactions with.
		//See http://developer.piwik.org/guides/content-tracking
		$trackContent=$this->get('piwik_trackContent',null,null,false);
		if ($trackContent){
			$iSurveyId=$this->getParam('sid');
			if(!$iSurveyId)
				$iSurveyId=$this->getParam('surveyid');

			//Tags all inputs as content, to track content interactions
			$js="//Label the Piwik Content tracking pieces
    _paq.push(['trackVisibleContentImpressions']);
        $('.question-wrapper').find('input').each(function(){
            $(this).attr('data-track-content','');
            thisid=$(this).attr('id');
            itemName=$(this).attr('name').toLowerCase();
            $(this).attr('data-content-name','Survey-$iSurveyId');
            $(this).attr('data-content-piece',itemName);
        });
    ";
			App()->getClientScript()->registerScript('piwikPlugin_InputContentTracking',$js,CClientScript::POS_END);
		}
	}

	/****************** Display code *****************/
	/*Code below handles display of paradata within limesurvey*/

	public function newDirectRequest(){
		//Handles additional plugin pages, used to display
		$event = $this->event;
		$request = $event->get('request');
		$functionToCall = $event->get('function');
		$content = call_user_func(array($this,$functionToCall));
		$event->setContent($this, $content);
	}

	function paradataHelp(){
		$html = "<div class='header ui-widget-header'>Paradata help</div> Coming soon..."; //Todo: include theory and rationale for use of paradata, and guidelines for interpretation.
		return $html;
	}

	function displayPiwikDashboard(){
		//Simply displays the Piwik Dashboard within Limesurvey. User must already be logged in.

		//$thisURL=$this->api->createUrl('plugins/direct', array('plugin' => 'pluginClass', 'function' => 'functionName'));
		//http://192.168.2.2/limesurvey/index.php/plugins/direct?plugin=piwikPlugin&function=displayPiwikDashboard

		//Todo: Choose the survey to filter based on URLs
		//Todo: Choose the statistics timeframe window  (day, month, year, etc), possibly based on when the survey opened.
		//Create Segmentation vars; see http://developer.piwik.org/api-reference/reporting-api-segmentation

		$html = "<div class='header ui-widget-header'>Survey Statistics</div>";
		$piwikID=trim($this->get('piwik_siteID', null, null, false)); //Todo: Allow this to change per-survey
		$piwikURL=trim($this->get('piwik_piwikURL', null, null, false));
		$html = '<iframe src="'.$piwikURL.'index.php?module=Widgetize&action=iframe&moduleToWidgetize=Dashboard&actionToWidgetize=index&idSite='.$piwikID.'&period=week&date=yesterday&token_auth=ac3912281474c0a40c6b1709e2dce467" frameborder="0" marginheight="0" marginwidth="0" width="100%" height="500"></iframe>';
		return $html;

	}



}
