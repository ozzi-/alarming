<?php

//  ini_set('display_startup_errors',1);
//  ini_set('display_errors',1);
//  error_reporting(-1);

  openlog('ZuKo', LOG_NDELAY, LOG_USER);

  $siteID    = "*******************************";
  $apiKey    = "******************************";
  $secretKey = "******************************";
  $apiURL    = "https://api.exivo.io/v1/";
  $apiAuth   = "*****@******";
  $authB64   = base64_encode($apiKey.":".$secretKey);

  date_default_timezone_set('Europe/Zurich');

  $monitoredEventsTimed   = array("componentUnlocked","componentNotReady","accessDenied","accessPermitted");
  $monitoredEventsAllways = array("sabotageAlarm","shortCircuitOfInputAlarm","interruptionOfInputAlarm");
  $monitoredEventsUrgent  = array("sabotageAlarm","shortCircuitOfInputAlarm","interruptionOfInputAlarm");
  $monitoredEvents        = array_merge($monitoredEventsTimed,$monitoredEventsAllways);
  $monitoredDays          = array(6,7);
  $monitoredHoursBetween  = array("22:30","05:30 nextDay");

  $alarmingRecipientsEmail = array("email1@domain.ch","email2@domain.ch");
  $alarmingRecipientsSMS   = array("+41794443322","+41771234567");
  $alarmingSMTPHost = "192.168.200.1";
  $alarmingSMTPPort = "25";
  $alarmingSender = "zuko@domain.ch";

  $monitoringDaysRes  = checkMonitoringDayRule();
  $monitoringHoursRes = checkMonitoringHoursRule();

  checkAuth($apiAuth);

  $receivedEvent = file_get_contents('php://input');
  $receivedEvent = json_decode($receivedEvent, true);
  checkEvent($receivedEvent);

  $eventName = $receivedEvent["name"];
  $eventTime = $receivedEvent["occurredAt"];
  $eventTime = parseOccuredAt($eventTime);
  $eventComponentId = $receivedEvent["payload"]["componentId"];


  if(!$monitoringDaysRes && !$monitoringHoursRes && !in_array($eventName,$monitoredEventsAllways)){
    die("0");
  }

  if(isMonitoredEvent($eventName)){
    $now = time();
    $day = idate('w', $now);

    $component=getLastUnlock($eventComponentId);
    if($component==null || !isset($component[0]["data"]["component"]["identifier"])){
      $componentIdentifier = "Unknown";
      $personLastUnlock = "Unknown";
      $personLastUnlockTime = "";
    }else{
      $componentIdentifier = $component[0]["data"]["component"]["identifier"];
      $personLastUnlock = $component[0]["data"]["person"]["firstName"]." ".$component[0]["data"]["person"]["lastName"];
      $personLastUnlockTime = $component[0]["data"]["person"]["firstName"].$component[0]["data"]["person"]["lastName"];
      $personLastUnlockTime = parseOccuredAt($component[0]["occurredAt"]);
    }

    $reason = $monitoringDaysRes?"Monitored Day":"";
    $reason = strlen($reason)>1?($reason." + "):"";
    $reason = $reason.($monitoringHoursRes?"Monitored Hours Range $monitoredHoursBetween[0]-$monitoredHoursBetween[1]":"");
    syslog(LOG_NOTICE, "eventTime: ".$eventTime." - eventName: ".$eventName." - eventComponentID: ".$eventComponentId." - reason: ".$reason." - personLastUnlocked: "
           .$personLastUnlock." @ ".$personLastUnlockTime." - componentIdentifier: ".$componentIdentifier);
    $subject = "ZuKo Alarm - $componentIdentifier - $eventTime";
    $message = $componentIdentifier." - ".$eventName." - ".$eventTime. " - due to ".$reason." - Last person that unlocked a door ".$personLastUnlock." @ ".$personLastUnlockTime;
    alarm($eventName, $subject, $message);
    echo("1");
  }else{
    echo("-1");
  }

// ---- Functions ----
function alarm($eventName,$subject,$message){
  global $alarmingRecipientsEmail, $alarmingSender, $alarmingRecipientsSMS, $alarmingSMTPHost, $alarmingSMTPPort;
  if(isMonitoredEventUrgent($eventName)){
    sms($alarmingRecipientsSMS,$message);
  }else{
    $recipients = implode(",",$alarmingRecipientsEmail);
    $message = "Subject: $subject\r\n"
    ."To: $recipients\r\n"
    ."From: $alarmingSender\r\n"
    ."Content-Type: text/html; charset=utf-8\r\n"
    ."Content-Transfer-Encoding: base64\r\n\r\n"
    .base64_encode($message)."\r\n";
    $res = smtpSend($alarmingRecipientsEmail, $alarmingSender, $message, $alarmingSMTPHost, $alarmingSMTPPort);
  }
}

function sms($alarmingRecipients,$msg){
  foreach ($alarmingRecipients as $recipient) {
    $url = "https://********.ch:8443/*******/sms/xml";
    $username = "*******";
    $pw = "********";
    $mobile = str_replace(' ','',$recipient);
    $msg = htmlspecialchars($msg, ENT_XML1);

    $request = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>
		<SMSBoxXMLRequest>
  		  <username>".$username."</username>
		  <password>".$pw."</password>
		  <command>WEBSEND</command>
		  <parameters>
		    <receiver>".$mobile."</receiver>
		    <service>ZUKO</service>
		    <text>".$msg."</text>
		    <guessOperator/>
    		  </parameters>
	         <metadata>
	           <forceSender>ZuKo</forceSender>
	         </metadata>
	     </SMSBoxXMLRequest>";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    try {
      $result = curl_exec($ch);
    }catch(Exception $e){
      syslog(LOG_ERR,"Failed to send SMS to ".$mobile." - exception opening connection to backend.");
      syslog(LOG_ERR,var_dump($e));
    }

    if(!$result){
      $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      syslog(LOG_ERR, "Failed to send SMS to ".$mobile." - could not open connection to backend - error code: ".$httpcode);
      return false;
    }
    curl_close($ch);
    if (strpos($result, "receiver status=\"OK\"") !== false) {
      syslog(LOG_NOTICE, "Sent SMS successfully to ".$mobile);
      return true;
    }else{
      syslog(LOG_ERR, "Failed to send SMS to ".$mobile. " - backend did not return success");
      syslog(LOG_ERR, $result);
      return false;
    }
  }
}

function smtpSend($to, $from, $message, $host, $port){
  $recipientString="";
  if ($h = fsockopen($host, $port, $errno, $errstr, 5)){
    $data = array();
    array_push($data, 0);
    array_push($data, "EHLO $host");
    array_push($data, "MAIL FROM: <$from>");
    if(is_array($to)){
      foreach ($to as $toRcpt) {
        array_push($data, "RCPT TO: <$toRcpt>");
        $recipientString = $recipientString.$toRcpt.",";
      }
    }else{
      array_push($data, "RCPT TO: <$to>");
      $recipientString = $to;
    }

    array_push($data, "DATA");
    array_push($data, $message."\r\n.");
    foreach($data as $c){
      $c && fwrite($h, "$c\r\n");
      do{
        $r = fgets($h, 256);
        if(substr($r,0,1)==="5"){
          syslog(LOG_ERR,"SMTP ERROR: ".$r);
        }
      }while(substr($r,3,1)==="-");
    }
    fwrite($h, "QUIT\r\n");
    $r = strtolower($r);
    if(substr($r,0,6)!=="250 ok"){
      syslog(LOG_ERR, "SMTP ERROR: Last response was not 250 OK but: ".$r);
    }else{
      syslog(LOG_NOTICE, "Successfully sent email to ".$recipientString);
    }
    return fclose($h);
  }
  syslog(LOG_ERR, "Could not send mail, fsockopen failed");
  return false;
}

function getComponentById($componentId){
  global $apiURL, $siteID, $eventComponentId;
  return exivoAPICall($apiURL.$siteID."/component/".$componentId);
}

function getLastUnlock($componentId){
  global $apiURL, $siteID, $eventComponentId;
  return exivoAPICall($apiURL.$siteID."/accesslog/component/".$componentId."?skip=0&limit=1&sortDir=asc");
}


function exivoAPICall($call){
  global $authB64;
  $ch = curl_init($call);
  $headers = [
    'Accept: application/json',
    'Authorization: Basic '.$authB64
  ];
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $server_output = curl_exec ($ch);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  $component = curl_exec($ch);
  curl_close($ch);
  return json_decode($component, true);
}

function checkAuth($auth){
  $authSent = isset($_SERVER["HTTP_AUTHENTICATION"])?$_SERVER["HTTP_AUTHENTICATION"]:"false";
  if(!hash_equals($authSent,$auth)){
    http_response_code(401);
    die("");
  }
}

function restrictIP($ip){
  // Exivo seems to use 212.243.16.133
  $source = isset($_SERVER["HTTP_X_FORWARDED_FOR"])?$_SERVER["HTTP_X_FORWARDED_FOR"]:"false";
  if(!hash_equals($source,$ip)){
    http_response_code(403);
    die();
  }
}

function checkEvent($event){
  if(!isset($event["name"]) || !isset($event["payload"]) || !isset($event["payload"]["componentId"])){
    http_response_code(400);
    die();
  }
}

function isMonitoredEvent($eventName){
  global $monitoredEvents;
  return in_array($eventName,$monitoredEvents);
}

function isMonitoredEventUrgent($eventName){
  global $monitoredEventsUrgent;
  return in_array($eventName,$monitoredEventsUrgent);
}

function contains($needle, $haystack){
    return strpos($haystack, $needle) !== false;
}

function checkMonitoringDayRule(){
  global $monitoredDays;
  $currentDayOfWeek = idate('N', time());
  $isMonitoredDay = in_array($currentDayOfWeek, $monitoredDays);
  return $isMonitoredDay;
}

function checkMonitoringHoursRule(){
  global $monitoredHoursBetween;
  $monitoredHourFrom = $monitoredHoursBetween[0];
  $monitoredHourTo   = $monitoredHoursBetween[1];

  $hoursToNextDay = contains(" nextDay",$monitoredHourTo);
  if($hoursToNextDay){
    $monitoredHourTo = str_replace(" nextDay","",$monitoredHourTo);
  }
  $hoursFrom = DateTime::createFromFormat('H:i', $monitoredHourFrom)
		->setTimeZone(new DateTimeZone(date_default_timezone_get()));
  $hoursTo   = DateTime::createFromFormat('H:i', $monitoredHourTo)
		->setTimeZone(new DateTimeZone(date_default_timezone_get()));
  $now = new DateTime('now', new DateTimeZone(date_default_timezone_get()));

  $hoursToND = clone $hoursTo;
  if($hoursToNextDay){
    $hoursToND->add(new DateInterval('P1D'));
  }else{
    $hoursToND->sub(new DateInterval('P999D'));
  }

  $hoursFromMatch = $hoursFrom <= $now;
  if(!$hoursFromMatch){
    return false;
  }

  $isMonitoredHour = $hoursTo>= $now || $hoursToND >= $now;
  return $isMonitoredHour;
}

function parseOccuredAt($eventTime){
  $eventTime = DateTime::createFromFormat("Y-m-d\TH:i:s.u\Z",$eventTime, new DateTimeZone('UTC'));
  $eventTime->setTimezone(new DateTimeZone(date_default_timezone_get()));
  return $eventTime->format('Y-m-d H:i:s');
}
?>
