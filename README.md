# Nextcloud Attachments for Roundcube

Upload large attachments to Nextcloud and automatically create share link.
Files that exceed `$config['max_message_size']`/1.33 will automatically be uploaded to
the configured nextcloud server and linked in the email body. If the user has 2FA
configured in Nextcloud, the plugin will ask to log in to create an app password.

This plugin is meant for environment where users have the same login for 
email and Nextcloud, e.g. company Installations

![Screenshots](https://github.com/bennet0496/nextcloud_attachments/assets/4955327/c2852c4e-30ca-444c-bf24-172ecc25d75f)



## Config

The plugin itself has 3 core settings. The server, the username strategy and the sub folder.

```php
<?php
// Full URL to the Nextcloud server 
// e.g. https://example.com/nextcloud if in subpath
// or https://cloud.example.com if in root
$config["nextcloud_attachment_server"] = "";

// Username resolveing stategy from internal Roundcube
// username which usually is the email address e.g. user@example.com
// %u -> email localpart
// %s -> email as is
$config["nextcloud_attachment_username"] = "%u";

// Name for the subfolder to upload to
// Defaults to "Mail Attachments"
// Can't be subfolder of subfolder link folder/sub
$config["nextcloud_attachment_folder"] = "Mail Attachments";
```
However, it also depends on the general config 

```php
// Message size limit. Note that SMTP server(s) may use a different value.
// This limit is verified when user attaches files to a composed message.
// Size in bytes (possible unit suffix: K, M, G)
$config['max_message_size'] = '25M';
```
Files larger than that will/must be uploaded to Nextcloud, so you should set it to
the desired value. With the following two additional options you can control the behavior 
of the plugin. Should it behave like Google Mail and automatically without any further 
user input, or should it behave like Outlook.com with a soft-limit suggesting the user to upload
and forcing them when the hard limit `$config['max_message_size']` is reached

```php
// Limit to show a warning at for large attachments.
// has to be smaller then $config['max_message_size']
// set to null to disable
$config["nextcloud_attachment_softlimit"] = "12M";

// Behavior if $config['max_message_size'] is hit.
// "prompt" to show dialog a la outlook or apple
// "upload" to automatically upload without asking a la google
// Defaults to "prompt"
$config["nextcloud_attachment_behavior"] = "prompt";
```

Remember, you will need to modify `post_max_size` and `upload_max_filesize` 
in your `php.ini` to allow large uploads in general

__When enabling the plugin make sure to place it before any other attachment plugins like `filesystem_attachments`__ E.g.
```php
$config['plugins'] = array('nextcloud_attachments', /*...*/ 'filesystem_attachments', /*...*/ 'vcard_attachments' /*...*/);
```

## Planned Features
 - [x] Give a selector to upload or attach for any upload similar to Outlook.com
 - [x] Manage the Nextcloud connection in the user settings
   - currently, if the user removes the password in Nextcloud, the plugin will fail as it
     thinks it has a password, but it does not work
 - [ ] Give the option for (additional) user specific Nextcloud servers
 - [ ] Give the option configure password protected links (system or user)
 - [ ] Wrap WebDAV request for easier adaptation to other Servers
 - [ ] Allow to define global user
 - [ ] Add folder to `sync-exclude.lst` to prevent desktop clients from (automatically) downloading the folder
 - [ ] Option for folder organization
   - by year and month
   - by hash
 - [x] i18n, l10n

## Credits

- Icons for Attachment body
  - [Ubuntu Yaru](https://github.com/ubuntu/yaru) (CC BY-SA 4.0) 
  - [Material Icons](https://developers.google.com/fonts/docs/material_icons) (Apache License 2.0)
- HTTP library: [Guzzle HTTP](https://github.com/guzzle/guzzle) (MIT)
- Loading Animation: [decode](https://dev.to/dcodeyt/create-a-button-with-a-loading-spinner-in-html-css-1c0h)
- HTML minimization: [StackOverflow](https://stackoverflow.com/a/6225706)
