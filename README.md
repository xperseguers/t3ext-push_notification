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

## Generate a certificate for Apple devices

Create a certificate signing request as explained on
https://help.apple.com/developer-account/#/devbfa00fef7

Now go to https://developer.apple.com/account/resources/certificates/list and
click the "+" link next to "Certificates":

1. Tick the radio button Services > Apple Push Notification service SSL (Sandbox
   & Production).
2. On the next screen, select your application in the dropdown list.
3. Choose your Certificate Signing Request
4. Download the certificate and double-click to import it (select "login" as
   target keychain).

We now need to convert the public and private keys for use with this extension.

1. In Keychain Access, locate your certificate.
2. Select the certificate "Apple Push Services: your-app" and choose Export from
   the context menu. Be sure to choose file format "Privacy Enhanced Mail
   (.pem)". Suggested name is `newfile.crt.pem`.
3. Select the private key and choose Export from the context menu.
4. Choose an export password. This password will be the one to configure in
   `ext_conf_template.txt` for `iOS_certificate_production_passphrase` or
   `iOS_certificate_development_passphrase`.
5. Convert the private certificate from `.p12` to `.pem` format using:

   ```bash
   openssl pkcs12 -in path.p12 -out newfile.key.pem -nocerts -nodes
   ```

6. Now combine both certificate a private key using:

   ```bash
   cat newfile.crt.pem > newfile.pem
   grep --a 200 "PRIVATE KEY" newfile.key.pem >> newfile.pem
   ```

### Useful commands

Check the validity of the certificate:

```bash
openssl x509 -in newfile.pem -noout -text
```

Check you have both the correct public and private keys in the certificate:

```bash
openssl x509 -noout -modulus -in newfile.pem | openssl md5
openssl rsa -noout -modulus -in newfile.pem | openssl md5
```
