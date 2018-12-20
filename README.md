# Alarming
This code will enable you to setup custom alarming based on events for Exivo.
Exivo is a product of dormakaba (https://www.dormakaba.com/exivo), this code acts 
as a target for the configurable webhook calls of exivo and stands in no relation with exivo or dormakaba.

# Get Started
Setup a webserver which hosts url.php.


Login to Exivo and go to API-Settings, there you can generate your API keys:
![d](https://i.imgur.com/kcWcpuT.png)
Edit alarm.php and fill in the following variables accordingly:

```
  $siteID   
  $apiKey   
  $secretKey
```

Under "API Events" go to "Webhook Settings"
![d](https://i.imgur.com/rcFALAp.png)
Fill in the URL where alarm.php will be hosted and set an authentication header, edit the following variable in alarm.php:
```
  $apiAuth
```

## Events
### monitoredEventsTimed
Add events which will lead to an alarm during the specified times (see monitoredHoursBetween)
### monitoredEventsAllways
Add events which will allways lead to an alarm, no matter the day or time
### monitoredEventsUrgent
Add events which will allways lead to a urgent alarm (meaning SMS), no matter the day or time

## Rules
### monitoredDays
Add the days which will be considered for monitoredEventsTimed
### monitoredHoursBetween
Add the time range which will be conisdered for monitoredEventsTimed. 


## Recipients
Fill in the recipients for mail and SMS notifications in 
```
  $alarmingRecipientsEmail
  $alarmingRecipientsSMS
```

## Notification Delivery
### Email
A simple SMTP mailer is implemented, use the following variables to configure the delivery
```
  $alarmingSMTPHost 
  $alarmingSMTPPort
  $alarmingSender
```

### SMS
Since SMS require some kind of gateway, this implementation is very specific to the provider which I use.
Check the function "sms" for a reference point. 
