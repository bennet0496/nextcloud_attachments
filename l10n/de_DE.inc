<?php

$labels['login_failed'] = 'Nextcloud Anmeldung fehlgeschlagen. Bitte versuchen Sie es später erneut!';
$labels['logging_in'] = 'Melde bei Nextcloud an.';
$labels['no_login_link'] = 'Keinen Link empfangen. Bitte versuchen Sie es später erneut.';
$labels['logged_in'] = 'Erfolgreich bei Nextcloud angemeldet.';
$labels['login'] = 'Anmelden';
$labels['login_and_link_file'] = 'Anmelden und Link teilen';

$labels['file_too_big'] = 'Datei zu groß';

$labels['file_too_big_not_logged_in_explain'] = 'Die Datei ist größer als %limit% und kann nicht angehangen werden. Nachdem verbinden Ihres Nextcloud Kontos unten, können Sie die Datei jedoch stattdessen als Datei-Link teilen.';

$labels['file_too_big_explain'] = ' Die Datei ist größer als %limit% und kann nicht angehangen werden. Sie können die Datei dennoch teilen, in dem Sie sie als Datei-Link versenden.';

$labels['file_big'] = 'Wie möchten Sie diese Datei teilen?';
$labels['file_big_explain'] = 'Die Datei ist ziemlich groß: %size%. Sie können Ihre E-Mails klein halten, in dem Sie sie über Nextcloud als Datei-Link teilen.';
$labels['file_big_not_logged_in_explain'] = 'Die Datei ist ziemlich groß: %size%. Sie können Ihre E-Mails klein halten, in dem Sie unten ihren Nextcloud Account verbinden und stattdessen einen Datei-Link teilen.';

$labels['link_file'] = 'Hochladen und als Link teilen';
$labels['attach'] = 'Als Kopie anfügen';

$labels['upload_success_link_inserted'] = 'erfolgreich zu Nextcloud hochgeladen und Link eingefügt';

$labels['upload_failed_title'] = 'Hochladen zu Nextcloud fehlgeschlagen';
$labels['upload_warning_title'] = 'Nextcloud Warnung';
$labels['upload_failed'] = 'Hochladen zu Nextcloud fehlgeschlagen:';

$labels['delete_ok'] = 'Datei erfolgreich in Nextcloud gelöscht';
$labels['delete_error_explain'] = 'Das löschen der Datei produzierte folgenden Fehler: <b>%reason%</b>. Wenn Sie diesen Fehler ignorieren möchten, können Sie die Datei dennoch aus der Liste entfernen.';
$labels['remove_attachment'] = 'Nextcloud Anhang löschen';
$labels['remove_from_list'] = 'Ignorieren und aus Liste entfernen';
$labels['remove_from_nextcloud_question'] = 'Dies ist ein Nextcloud-Anhang. Wollen Sie diesen in Nextcloud ebenfalls löschen? Sie werden die den Anhang dennoch selbst aus dem Nachrichtentext entfernen müssen';
$labels['remove_from_nextcloud'] = 'In Nextcloud entfernen';
$labels['keep_in_nextcloud'] = 'In Nextcloud behalten und aus Liste entfernen';



$labels['missing_config'] = 'Nextcloud Erweiterung ist nicht konfiguriert. Bitte kontaktieren Sie den Administrator!';
$labels['cannot_mkdir'] = 'Unterordner zum Hochladen konnte nicht erstellt werden:';
$labels['folder_access'] = 'Es konnte nicht auf den Ordner zugegriffen werden! Bitte prüfen Sie die Berechtigungen oder fragen Sie einen Administrator';
$labels['cannot_find_unique_name'] = 'Es konnte kein einzigartiger Dateiname gefunden werden. Versuchen Sie die Datei umzubenennen oder den Ordner in Nextcloud aufzuräumen';
$labels['cannot_link'] = 'Das hochladen der Datei war erfolgreich, es konnte aber kein Link erstellt werden. Bitte versuchen Sie manuell einen Link zu erstellen oder fragen Sie den Administrator.';

$labels['cloud_attachments'] = 'Cloud Anhänge';
$labels['cloud_server'] = 'Cloud Server';
$labels['status'] = 'Status';
$labels['connected_as'] = 'Verbunden als';
$labels['disconnect'] = 'trennen';
$labels['connect'] = 'verbinden';
$labels['not_connected'] = 'Nicht verbunden';

$labels['server_default'] = 'Server Standard: ';

$labels['folder_layout'] = 'Ordner Struktur';
$labels['folder_layout_flat'] = 'Alles in einem Ordner';
$labels['folder_layout_hash'] = 'Sortieren nach führenden Datei-hash bytes';
$labels['folder_layout_date'] = 'Sortieren nach Datum';
$labels['folder_layout_date_Y'] = 'Sortieren nach Jahr';
$labels['folder_layout_date_Y/LLLL'] = 'Sortieren nach Jahr und Monat';
$labels['folder_layout_date_Y/LLLL/dd'] = 'Sortieren nach Jahr, Monat und Woche des Monats';
$labels['folder_layout_date_Y/LL'] = 'Sortieren nach Jahr und Monat';
$labels['folder_layout_date_Y/LL/dd'] = 'Sortieren nach Jahr, Monat und Woche des Monats';
$labels['folder_layout_date_Y/ww'] = 'Sortieren nach Jahr und Kalenderwoche';
$labels['folder_layout_date_Y/ww/EEEE'] = 'Sortieren nach Jahr, Kalenderwoche und Wochentag';
$labels['folder_layout_date_Y/ww/E'] = 'Sortieren nach Jahr, Kalenderwoche und Wochentag';

$labels['password_protected_links'] = 'Erzeuge Passwort-geschützte Links';
$labels['expire_links'] = 'Setzte ein Ablaufdatum für Links';
$labels['expire_links_after'] = 'Tage nach denen der Link abläuft';

$labels['attached_html_lang'] = "Sprache des Anhangs";
$labels['display_language'] = "Anzeigesprache";
$labels['attached_html_lang_explain'] = "Sprache, welche für den Text im Anhang verwendet wird, welche dem*der Empfänger*in beschreibt, wo die Datei zu finden ist. Nicht übersetzte Sprachen fallen auf Englisch (Vereinigte Staaten) zurück";

$labels['password'] = 'Passwort';
$labels['valid_until'] = 'Gültig bis';

$labels['filelink_attachment'] = 'Datei-Link Anhang';
$labels['file_is_filelink_download_below'] = 'Die Datei $filename wurde als Datei-Link angehangen. Sie kann über den nachfolgenden Link heruntergeladen werden.';
$labels['size'] = 'Größe';
$labels['link'] = 'Link';
$labels['checksum'] = 'Prüfsumme';
$labels['available_until'] = 'Verfügbar bis';
$labels['valid_until_expires'] = '$validuntil, nach welchem der Downloadlink ungültig wird';
$labels['attachment_disclaimer'] = 'Anhänge werden von $serverurl zur Verfügung gestellt und unterliegen den Nutzungsbedingungen und Datenschutzbestimmungen des*der Anbieter*in. <b>Zusätzlich dazu, dass der Anhang nach dem Ablaufdatum nicht mehr verfügbar ist, kann der*die Absender*in den Anhang jederzeit löschen; laden Sie deshalb die Datei herunter und speichern Sie sie, um eine lokale Kopie zu behalten.</b> Seien Sie vorsichtig, wessen Anhängen sie trauen, insbesondere bei ausführbaren Dateien und Office Dokumenten.';
$labels['copyright'] = 'Erstellt mit $repolocation für Roundcube von $author';
$labels['icons'] = 'Icons';
$labels['deletion'] = 'Löschung';