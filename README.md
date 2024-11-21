# Nextcloud Attachments for Roundcube

Upload large attachments to Nextcloud and automatically create share link.
Files that exceed `$config['max_message_size']`/1.33 will automatically be uploaded to
the configured nextcloud server and linked in the email body. If the user has 2FA
configured in Nextcloud, the plugin will ask to log in to create an app password.

This plugin is meant for environment where users have the same login for 
email and Nextcloud, e.g. company Installations

![Screenshots](https://github.com/bennet0496/nextcloud_attachments/assets/4955327/c2852c4e-30ca-444c-bf24-172ecc25d75f)

## Config

The plugin itself has a handful core settings. The server, the username strategy and the sub folder.

```php
<?php
// Full URL to the Nextcloud server 
// e.g. https://example.com/nextcloud if in subpath
// or https://cloud.example.com if in root
$config["nextcloud_attachment_server"] = "";

// Username resolving strategy from internal Roundcube
// username which usually is the email address e.g. user@example.com or IMAP User
// Placeholders are replaced as following
// %s => verbatim RC username as reported by rcmail->get_user_name(). Depending on config loginuser@domain or login
// %i => username used to log in to imap. usually equal to %s (since 1.3)
// %e => user email (since 1.3)
// %l, %u => email local part (%u is for backward compatibility to <1.3) (since 1.3)
// %d => email domain (since 1.3)
// %h => IMAP Host (since 1.3)
$config["nextcloud_attachment_username"] = "%u";

// Name for the sub-folder to upload to
// Defaults to "Mail Attachments"
// Can't be sub-folder of sub-folder link folder/sub
//
// The folder name can also be translated according to the users configured locale
// by setting the value to a locale-keyed array like
// ["en_US" => "Mail Attachments", "de_DE" => "E-Mail Anhänge"]
// If locale doesn't exist, en_US or first will be used
$config["nextcloud_attachment_folder"] = "Mail Attachments";

// Folder Layout
// "flat"            => Flat folder layout, everything in the Folder
// "date:format"     => Create folder hierarchy according to format. See https://unicode-org.github.io/icu/userguide/format_parse/datetime/
//                      Use "/" as the folder separator. Defaults to date:Y/LLLL
//                      Dateformat is localized
// "hash:algo:depth" => Use "algo"-hash of file, creating sub folder for every of the "depth" bytes
//                      see https://www.php.net/manual/en/function.hash-algos.php
//                      Defaults to hash:sha1:2
// Defaults to flat
$config["nextcloud_attachment_folder_layout"] = "flat";

// Don't try the email password at all, because we know it won't work
// e.g. due to mandatory 2FA
// Defaults to false, i.e. try the password
// Since version 1.3
$config["nextcloud_attachment_dont_try_mail_password"] = false;
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
// has to be smaller than $config['max_message_size']
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

### Nextcloud password policy
If you have a password policy governing share-passwords set up in Nextcloud, then you can control the generated password with the
following parameters

```php
// Generated password length
// Passwords will be alphanumerical strings with upper and lower case letters and numbers
// Defaults to 12
$config["nextcloud_attachment_password_length"] = 12;

// The alphabets from which to generate password
// It is guaranteed that at least one character of each set is used,
// as long as password_length > |password_alphabets|
// Default: ["123456789", "ABCDEFGHJKLMNPQRSTUVWXYZ", "abcdefghijkmnopqrstuvwxyz"]
// (Lookalikes are 0O and Il are excluded)
$config["nextcloud_attachment_password_alphabets"] = ["123456789", "ABCDEFGHJKLMNPQRSTUVWXYZ", "abcdefghijkmnopqrstuvwxyz"];
```

### Nextcloud Brute-Force protection
By default, this plugin, tests whether it can use the mail credentials for the Nextcloud login. If lots of users can't log in with
their mail credentials to Nextcloud, e.g., due to high adoption of 2FA or a high percentage of user that are denied form using
Nextcloud (via LDAP groups or smth), this will inevitably lead to Nextcloud locking out the Roundcube server because it considers
these logins, as login bruteforce attempts.

You can disable the behavior of trying the mail password since version 1.3
```php
// Don't try the email password at all, because we know it won't work
// e.g. due to mandatory 2FA
// Defaults to false, i.e. try the password
// Since version 1.3
$config["nextcloud_attachment_dont_try_mail_password"] = false;
```

However, you might also want to consider, adding you Roundcube server to the Bruteforce allow-list of the Nextcloud server.
To do that you have to [enable the bruteforce settings app](https://docs.nextcloud.com/server/latest/admin_manual/configuration_server/bruteforce_configuration.html#the-brute-force-settings-app)
and then as an administrator, under Setting and Security, add your Server's IP to the allow list.

<img width="500" src="https://github.com/bennet0496/nextcloud_attachments/assets/4955327/044fe17d-d400-42ca-b23f-258d8fdd119d">

### Excluding users

You can also exclude users from being able to interact with the plugin, which can be useful if they either won't
be able to use the cloud anyway, have a small quota or can't share link or what ever might be the reason, that they
should be unable to share file links.

There are 4 strategies to exclude users. The first and most straight forward one is the direct exclude list
```php
$config["nextcloud_attachment_exclude_users_in_addr_books"] = ["demo@example.com", "demouser"];
```
If either their, IMAP (login) username, email or mapped username matches an entry in the list, they won't be
able to use the plugin (version <1.3 only matched the login usernames).

The other 3 strategies (only available in version >=1.3) involve using address books, which essentially allow retrieving 
the status from LDAP. Which is useful because that has probably the information who can log in where anyway. Option one 
is to exclude any user listed with their uid or email in a given address book, allowing you to create a (hidden) address 
book that filters the users that should not be able to use the plugin
```php
$config["nextcloud_attachment_exclude_users_in_addr_books"] = ["nocloud"];
```
You can create a hidden address book by setting its hidden value in the global config.
```php
$config['ldap_public']['nocloud'] = array(
        'name'              => 'Nextcloud Denylist Book',
        'hosts'             => array('ldap.example.com'),
        'port'              => 389,
        'user_specific'     => false,
        'base_dn'           => 'ou=users,dc=example,dc=com',
        'hidden'            => true,
        'filter'            => '(&(objectClass=posixAccount)(mail=*)(cloudLogin=no))',
        'search_fields' => array(
                'mail',
                'cn',
                ),
        'name_field' => 'displayName',
        'email_field' => 'mail',
        'vlv' => true,
        'sort' => 'displayName',
        'fieldmap' => [
                 'email' => 'mail:*',
                 'uid' => 'uid'
        ]
);
```
Option 2 is to exclude users with a specific mapped value in an address book. Allowing you that if you have a public 
address book anyway to map a value instead of creating a new address book. This is particularly using with directory
server supporting the `memberOf` overlay to directly indicate group memberships in the user entry, however currently
this may or may not work with multivalued attributes.
```php
$config["nextcloud_attachment_exclude_users_with_addr_book_value"] = [["public", "memberOf", "cn=no_cloud,ou=groups,dc=example,dc=com"]];
```
Add a mapped value with the `fieldset` as shown above.

And Option 3 is to exclude users that are in a specific group, when group mapping is set up for the address book.
```php
$config["nextcloud_attachment_exclude_users_in_addr_book_group"] = [["public", "nocloud"]];
```
You can set up group mapping in the global LDAP config.
```php
$config['ldap_public']['public'] = array(
        'name'              => 'Public LDAP Addressbook',
        'hosts'             => array('ldap.example.com'),
        'port'              => 389,
        'user_specific'     => false,
        'base_dn'           => 'ou=users,dc=example,dc=com',
        'bind_dn'           => '',
        'bind_pass'         => '',
        'filter'            => '(&(objectClass=posixAccount)(mail=*))',
        'groups'            => array(
                'base_dn'         => 'ou=groups,dc=example,dc=com',     // in this Howto, the same base_dn as for the contacts is used
                'filter'          => '(objectClass=posixGroup)',
                'object_classes'  => array("top", "posixGroup"),
                'member_attr'     => 'memberUid',
                'name_attr'       => 'cn',
                'scope'           => 'sub',
                ),
        'search_fields' => array(
                'mail',
                'cn',
                ),
        'name_field' => 'displayName',
        'email_field' => 'mail',
        'vlv' => true,
        'sort' => 'displayName',
        'fieldmap' => [
                    'name'        => 'displayName',
                    'surname'     => 'sn',
                    'firstname'   => 'givenName',
                    'jobtitle'    => 'loginShell',
                    'email'       => 'mail:*',
                    'phone:home'  => 'homePhone',
                    'phone:work'  => 'telephoneNumber',
                    'phone:mobile' => 'mobile',
                    'phone:pager' => 'pager',
                    'phone:workfax' => 'facsimileTelephoneNumber',
                    'street'      => 'street',
                    'zipcode'     => 'postalCode',
                    'region'      => 'st',
                    'locality'    => 'l',
                    // if you country is a complex object, you need to configure 'sub_fields' below
                    'country'      => 'c',
                    'organization' => 'o',
                    'department'   => 'ou',
                    'notes'        => 'description',
                    'photo'        => 'jpegPhoto',
                    // these currently don't work:
                    // 'manager'       => 'manager',
                    // 'assistant'     => 'secretary',
                    'uid' => 'uid'
        ]
);
```

## Planned Features
 - [ ] Give the option for (additional) user specific Nextcloud servers
 - [ ] Wrap WebDAV request for easier adaptation to other Servers
 - [ ] Allow to define global user
 - [ ] Translatable attachment template
 - [ ] Translate to more languages
 - [x] Add folder to `sync-exclude.lst` to prevent desktop clients from (automatically) downloading the folder

## Credits

- Icons for Attachment body
  - [Ubuntu Yaru](https://github.com/ubuntu/yaru) (CC BY-SA 4.0) 
  - [Material Icons](https://developers.google.com/fonts/docs/material_icons) (Apache License 2.0)
- HTTP library: [Guzzle HTTP](https://github.com/guzzle/guzzle) (MIT)
- Loading Animation: [decode](https://dev.to/dcodeyt/create-a-button-with-a-loading-spinner-in-html-css-1c0h)
- HTML minimization: [StackOverflow](https://stackoverflow.com/a/6225706)
