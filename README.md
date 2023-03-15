Push Notification Service for iOS and Android
=============================================

This TYPO3 extension provides an API to send notifications to iOS and Android
devices:

- iOS devices are notified using Apple Push Notification Service (APNS);
- Android devices are notified using Google Cloud Messaging (GCM).

You are advised to regularly purge outdated iOS device tokens using the built-in
scheduler task. This will prevent you from trying to notify devices which are
not valid anymore. Outdated GCM tokens however will automatically be deactivated
when you try to send a notification to them.


## API

This extension provides a few APIs to let you register/unregister devices and
send notification:

```
// Instantiante a notification service
$notificationService = \Causal\PushNotification\Service\NotificationService::getInstance();

// Register a device:
$deviceToken = 'abc123...';
$userId = 123;  // This is your own id to identify users
$notificationService->registerDevice($deviceToken, $userId);

// Send a notification:
$notificationId = 1;
$userId = 123;
$title = 'Hello World!';
$message = 'This is my first notification, enjoy!';
$notificationService->notify($notificationId, $userId, $title, $message);
```

## Generate a key for Apple devices

Go to https://developer.apple.com/account/resources/authkeys/list and  click the
"+" link next to "Keys":

1. Tick the checkbox for Apple Push Notifications service (APNs).
2. Complete the process until you can download the .p8 file.
