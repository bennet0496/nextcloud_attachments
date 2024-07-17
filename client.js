// Copyright (c) 2023 Bennet Becker <dev@bennet.cc>
//
// Permission is hereby granted, free of charge, to any person obtaining a copy
// of this software and associated documentation files (the "Software"), to deal
// in the Software without restriction, including without limitation the rights
// to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
// copies of the Software, and to permit persons to whom the Software is
// furnished to do so, subject to the following conditions:
//
// The above copyright notice and this permission notice shall be included in all
// copies or substantial portions of the Software.
//
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
// IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
// AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
// OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
// SOFTWARE.
// noinspection JSUnresolvedReference

rcmail.addEventListener("plugin.nextcloud_login", function(data) {
    if (data.status === "ok") {
        rcmail.env.nextcloud_login_flow = data.url;
    } else {
        rcmail.display_messge(rcmail.gettext("login_failed", "nextcloud_attachments"), "error", 10000);
    }
});

rcmail.addEventListener("plugin.nextcloud_login_result", function (event) {
    if (event.status === "ok") {
        this.rcmail.env.nextcloud_upload_login_available = true;
        this.rcmail.env.nextcloud_upload_available = true;
    } else if (event.status === "login_required") {
        this.rcmail.env.nextcloud_upload_login_available = true;
        this.rcmail.env.nextcloud_upload_available = false;
    } else {
        this.rcmail.env.nextcloud_upload_login_available = false;
        this.rcmail.env.nextcloud_upload_available = false;
    }
});

rcmail.addEventListener("plugin.nextcloud_delete_result", function (event) {
    if (event.status === "ok") {
        rcmail.display_message(rcmail.gettext("delete_ok", "nextcloud_attachments"), "success", 2000);
    } else {
        const dialog = rcmail.show_popup_dialog(
            rcmail.gettext("delete_error_explain", "nextcloud_attachments").replace("%reason%", event.message ?? "Unspecified"),
            rcmail.gettext("remove_attachment", "nextcloud_attachments"), [
                {
                    text: rcmail.gettext("remove_from_list", "nextcloud_attachments"),
                    'class': '',
                    click: (e) => {
                        rcmail.remove_from_attachment_list("rcmfile" + event.file);
                        dialog.dialog('close');
                    }

                },
                {
                    text: rcmail.gettext("cancel"),
                    'class': 'cancel',
                    click: () => {
                        dialog.dialog('close');
                    }
                }]
        );
        // rcmail.display_message(rcmail.gettext("delete_error", "nextcloud_attachments"), "error", 2000);
    }
});

rcmail.addEventListener("plugin.nextcloud_upload_result", function(event) {
    //server finished upload
    if (event.status === "ok") {
        let message = this.rcmail.editor.get_content(),
            sig = this.rcmail.env.identity;

        //convert to human-readable file size
        let size = event.result?.file?.size;
        const unit = ["", "k", "M", "G", "T"];

        for(let i = 0; size > 800 && i < unit.length; i++) {
            size /= 1024;
        }

        //insert link to plaintext editor
        if(!this.rcmail.editor.is_html()){
            let attach_text = "\n" + event.result?.file?.name +
                " (" + size.toFixed(1).toLocaleString() + " " + unit[i] + "B) <"
                + event.result?.url + ">" + "\n";
            console.log(event.result);
            if (event.result?.file?.password !== undefined && event.result?.file?.password !== null) {
                attach_text += rcmail.gettext("password", "nextcloud_attachments") + ": " + event.result.file.password + "\n";
            }

            if (event.result?.file?.expireDate !== undefined && event.result?.file?.expireDate !== null) {
                const dt = new Date(event.result.file.expireDate);
                attach_text += rcmail.gettext("valid_until", "nextcloud_attachments") + ": " + dt.toLocaleDateString() + "\n";
            }

            //insert before signature if one exists
            if(sig && this.rcmail.env.signatures && this.rcmail.env.signatures[sig]) {
                sig = this.rcmail.env.signatures[sig].text;
                sig = sig.replace(/\r\n/g, '\n');

                const p = this.rcmail.env.top_posting ? message.indexOf(sig) : message.lastIndexOf(sig);

                message = message.substring(0, p) + attach_text + message.substring(p, message.length);
            } else {
                message += attach_text;
            }
            //update content
            rcmail.editor.set_content(message);
        } else { // insert to HTML editor
            let sigElem = rcmail.editor.editor.dom.get("_rc_sig");

            if(!sigElem) {
                sigElem = rcmail.editor.editor.dom.get("v1_rc_sig");
            }



            //create <a> link element
            const link = document.createElement("a");
            link.href = event.result?.url;
            link.style.cssText = "text-decoration: none; color: black; display: grid; grid-template-columns: auto 1fr auto 0fr; grid-auto-rows: min-content; align-items: baseline; background: rgb(220,220,220); max-width: 400px; padding: 1em; border-radius: 10px; font-family: sans-serif";

            const fn = document.createElement("span");
            fn.innerText = event.result?.file?.name + "\n";
            fn.style.cssText = "grid-area: 1 / 1;font-size: medium; max-width: 280px; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; width: fit-content";
            link.append(fn);

            const se = document.createElement("span");
            se.innerText = size.toFixed(1) + " " + unit[i] + "B\n";
            se.style.cssText = "grid-area: 1 / 2;margin-left: 1em; color: rgb(100,100,100); font-size: x-small; width: fit-content";
            link.append(se);

            const url = document.createElement("span");
            url.innerText = event.result?.url;
            url.style.cssText = "grid-area: 2 / 1 / span 1 / span 3;color: rgb(100,100,100); align-self: end; font-size: small; /*max-width: 320px; text-overflow: ellipsis; overflow: hidden;*/ white-space: nowrap; width: fit-content";
            link.append(url);

            let has_pass = false;
            if (event.result?.file?.password !== undefined && event.result?.file?.password !== null) {
                const pwe = document.createElement("span");
                const pwtt = document.createElement("code");
                pwtt.style.fontFamily = "monospace";
                pwtt.style.fontSize = "13px";
                pwtt.innerText = event.result.file.password
                pwe.innerText = rcmail.gettext("password", "nextcloud_attachments") + ": ";
                pwe.append(pwtt);
                pwe.style.cssText = "grid-area: 3 / 1 / span 1 / span 3;color: rgb(100,100,100); align-self: end; font-size: small; max-width: 320px; text-overflow: ellipsis; overflow-x: hidden; width: fit-content";
                link.append(pwe);
                has_pass = true;
            }

            let has_expire = false;
            if (event.result?.file?.expireDate !== undefined && event.result?.file?.expireDate !== null) {
                const row = has_pass ? 4 : 3;
                const dte = document.createElement("span");
                const dt = new Date(event.result.file.expireDate);
                dte.innerText = rcmail.gettext("valid_until", "nextcloud_attachments") + ": " + dt.toLocaleDateString();
                dte.style.cssText = "grid-area: " + row + " / 1 / span 1 / span 3;color: rgb(100,100,100); align-self: end; font-size: small; max-width: 320px; text-overflow: ellipsis; overflow-x: hidden; width: fit-content";
                link.append(dte);
                has_expire = true;
            }

            const imgs = document.createElement("span");
            const rowspan = 3 + (has_pass ? 1 : 0) + (has_expire ? 1 : 0);
            imgs.style.cssText = "grid-area: 1 / 4 / span "+rowspan+" / span 1; align-self: center";

            const img = document.createElement("img");
            img.setAttribute('src', "data:image/png;base64," + event.result?.file?.mimeicon);

            imgs.append(img);
            link.append(imgs);

            const paragraph = document.createElement("p");
            paragraph.append(link);

            rcmail.editor.editor.getBody().insertBefore(paragraph, sigElem);
        }
        rcmail.display_message(event.result?.file?.name + " " + rcmail.gettext("upload_success_link_inserted", "nextcloud_attachments"), "confirmation", 5000)
        const fid = event.result?.file?.id;
        if (fid && rcmail.env.attachments["rcmfile" + fid]) {
            rcmail.env.attachments["rcmfile" + fid].isNextcloudAttachment = true;
        }
    } else {
        // rcmail.show_popup_dialog(JSON.stringify(event), "Nextcloud Upload Failed");
        // console.log(event);
        switch(event.status) {
            case "no_config":
                rcmail.display_message(rcmail.gettext("missing_config", "nextcloud_attachments"), "notice", 2000);
                break;
            case "mkdir_error":
                rcmail.show_popup_dialog(rcmail.gettext("cannot_mkdir", "nextcloud_attachments")  + " " + event.message, rcmail.gettext("upload_failed_title", "nextcloud_attachments"));
                break;
            case "folder_error":
                rcmail.display_message(rcmail.gettext("folder_access", "nextcloud_attachments"), "error", 2000);
                break;
            case "name_error":
                rcmail.show_popup_dialog(rcmail.gettext("cannot_find_unique_name", "nextcloud_attachments"), rcmail.gettext("upload_failed_title", "nextcloud_attachments"));
                break;
            case "upload_error":
                rcmail.display_message(rcmail.gettext("upload_failed", "nextcloud_attachments") + " " + event.message, "error", 10000);
                break;
            case "link_error":
                rcmail.show_popup_dialog(rcmail.gettext("cannot_link", "nextcloud_attachments"), rcmail.gettext("upload_warning_title", "nextcloud_attachments"));
                break;
        }
    }
});

rcmail.nextcloud_login_button_click_handler = function(btn_evt, dialog, files = null, post_args = null, props = null) {
    //start login process
    rcmail.http_post("plugin.nextcloud_login");
    if(btn_evt !== null) {
        btn_evt.target.innerText = " ";
        btn_evt.currentTarget.classList.add("button--loading");
    }
    //wait for login url and open it
    //potentially bad for slow network connections
    //however we cannot open popups outside the onclick handler
    setTimeout(function (t){
        if (rcmail.env.nextcloud_login_flow !== null) {
            const hw = window.screen.availWidth / 2, hh = window.screen.availHeight / 2;
            const x = window.screenX + hw - 300, y = window.screenY + hh - 400;
            const pos = "screenX=" + x + ",screenY=" + y;
            // noinspection SpellCheckingInspection
            const popup = window.open(rcmail.env.nextcloud_login_flow, "", "noopener,noreferrer,popup,width=600,height=800,"+pos);
            if(!popup) {
                t?.append("<p>Click <a href=\"" + rcmail.env.nextcloud_login_flow + "\">here</a> if no window opened</p>");
            } else {
                t?.dialog('close');
                rcmail.display_message(rcmail.gettext("logging_in", "nextcloud_attachments"), "loading", 10000);
            }
            rcmail.env.nextcloud_login_flow = null;
        } else {
            t?.dialog('close');
            rcmail.display_message(rcmail.gettext("no_login_link", "nextcloud_attachments"), "error", 10000);
        }
    }, 1000, dialog)

    //wait for login to finish
    window.nextcloud_poll_interval = setInterval(function (t) {
        rcmail.refresh();
        if(rcmail.env.nextcloud_upload_login_available === true && rcmail.env.nextcloud_upload_available === true) {
            if(rcmail.task === "settings") {
                rcmail.command('save');
            }else{
                t.dialog('close');
            }
            clearInterval(window.nextcloud_poll_interval);
            rcmail.display_message(rcmail.gettext("logged_in", "nextcloud_attachments"), "confirmation", 10000);
            // console.log(files);
            if(files !== null) {
                const os = rcmail.env.max_filesize;
                const size = files.map(f => f.size).reduce((sum, val) =>  sum + val, 0);
                //if we can log in to nextcloud, we temporally increase the limit
                //so the checks in the original function will pass
                rcmail.env.max_filesize += 2 * size;
                rcmail.__file_upload(files, post_args, props);
                //restore limit
                rcmail.env.max_filesize = os;
            } else {
                rcmail.command('save');
            }
        }
    }, 500, dialog);
}

// noinspection JSUnusedLocalSymbols
rcmail.addEventListener('init', function(evt) {
    //retrieve nextcloud login status
    // noinspection SpellCheckingInspection
    rcmail.http_get("plugin.nextcloud_checklogin");

    //intercept file_upload
    rcmail.__file_upload = rcmail.file_upload;

    rcmail.file_upload = function(files, post_args, props) {

        //server side isn't configured. skip to original method
        if (rcmail.env.nextcloud_upload_available !== true && rcmail.env.nextcloud_upload_login_available !== true) {
            return rcmail.__file_upload(files, post_args, props);
        }

        // console.log(files);
        files = Array.from(files);
        //calculate file size
        const size = files.map(f => f.size).reduce((sum, val) =>  sum + val, 0);

        let human_size = size;
        const unit = ["", "k", "M", "G", "T"];
        let unit_idx;
        for(unit_idx = 0; human_size > 800 && unit_idx < unit.length; unit_idx++) {
            human_size /= 1024;
        }

        let human_limit = rcmail.env.max_filesize;
        let limit_unit_idx;
        for(limit_unit_idx = 0; human_limit > 800 && limit_unit_idx < unit.length; limit_unit_idx++) {
            human_limit /= 1024;
        }

        //original max_filesize
        const os = rcmail.env.max_filesize;

        //intercept if files are too large
        if(size > rcmail.env.max_filesize) {
            //server indicated, it can't use the known username and password from the session
            //to login to nextcloud. Probably because, 2FA is active.
            if (rcmail.env.nextcloud_upload_available !== true && rcmail.env.nextcloud_upload_login_available === true) {
                // noinspection SpellCheckingInspection
                const dialog = rcmail.show_popup_dialog(
                    rcmail.gettext("file_too_big_not_logged_in_explain", "nextcloud_attachments")
                        .replace("%limit%", human_limit.toFixed(0) + " " + unit[unit_idx] + "B"),
                    rcmail.gettext("file_too_big", "nextcloud_attachments"), [
                    {
                        text: rcmail.gettext("login", "nextcloud_attachments"),
                        'class': 'mainaction login',
                        click: (e) => {
                            post_args = {_target: "cloud", ...post_args};
                            rcmail.nextcloud_login_button_click_handler(e, dialog, files, post_args, props);
                        }

                    },
                    {
                        text: rcmail.gettext("close"),
                        'class': 'cancel',
                        click: () => {
                            dialog.dialog('close');
                        }
                    }]);
                //We didn't do anything yet, we may upload later though
                return false;
            } else  // We can upload, and do so automatically
                if (rcmail.env.nextcloud_attachment_behavior === "upload") {
                //mark for callback that we want this function to go to the cloud
                post_args = {_target: "cloud", ...post_args};
                //if we can log in to nextcloud, we temporally increase the limit
                //so the checks in the original function will pass
                rcmail.env.max_filesize += 2 * size;
                // upload file
                const ret = rcmail.__file_upload(files, post_args, props);
                // restore limit
                rcmail.env.max_filesize = os;

                return ret;
            } else // We can upload, but we ask the user if this is what they want
            {
                const dialog = rcmail.show_popup_dialog(
                    rcmail.gettext("file_too_big_explain", "nextcloud_attachments")
                        .replace("%limit%", human_limit.toFixed(0) + " " + unit[unit_idx] + "B"),
                    rcmail.gettext("file_too_big", "nextcloud_attachments"),
                    [
                        {
                            text: rcmail.gettext("link_file", "nextcloud_attachments"),
                            'class': 'mainaction',
                            click: () => {
                                //mark for callback that we want this function to go to the cloud
                                post_args = {_target: "cloud", ...post_args};
                                //if we can log in to nextcloud, we temporally increase the limit
                                //so the checks in the original function will pass
                                rcmail.env.max_filesize += 2 * size;
                                // upload file
                                rcmail.__file_upload(files, post_args, props);
                                // restore limit
                                rcmail.env.max_filesize = os;
                                dialog.dialog('close');
                            }
                        },
                        {
                            text: rcmail.gettext("cancel"),
                            'class': 'cancel',
                            click: () => {
                                dialog.dialog('close');
                            }
                        }
                    ]);
            }
            // We didn't do anything
            return false;
        } else // Soft limit hit, prompt the user to hopefully share link instead.
            if(rcmail.env.nextcloud_attachment_softlimit && size * 1.33 > rcmail.env.nextcloud_attachment_softlimit) {
                //server indicated, it can't use the known username and password from the session
                //to login to nextcloud. Probably because, 2FA is active.
                if (rcmail.env.nextcloud_upload_available !== true && rcmail.env.nextcloud_upload_login_available === true) {
                    // noinspection SpellCheckingInspection
                    const dialog = rcmail.show_popup_dialog(
                        rcmail.gettext("file_big_not_logged_in_explain", "nextcloud_attachments")
                            .replace("%size%", human_limit.toFixed(0) + " " + unit[unit_idx] + "B"),
                        rcmail.gettext("file_big", "nextcloud_attachments"), [
                            {
                                text: rcmail.gettext("login_and_link_file", "nextcloud_attachments"),
                                'class': 'mainaction login',
                                click: (e) => {
                                    post_args = {_target: "cloud", ...post_args};
                                    rcmail.nextcloud_login_button_click_handler(e, dialog, files, post_args, props);
                                }

                            },
                            {
                                text: rcmail.gettext("attach", "nextcloud_attachments"),
                                'class': 'secondary',
                                click: () => {
                                    rcmail.__file_upload(files, post_args, props);
                                    dialog.dialog('close');
                                }
                            },
                        ]);
                } else {
                    // noinspection SpellCheckingInspection
                    const dialog = rcmail.show_popup_dialog(
                        rcmail.gettext("file_big_explain", "nextcloud_attachments")
                            .replace("%size%", human_size.toFixed(0) + " " + unit[unit_idx] + "B"),
                        rcmail.gettext("file_big", "nextcloud_attachments"), [
                            {
                                text: rcmail.gettext("link_file", "nextcloud_attachments"),
                                'class': 'mainaction',
                                click: () => {
                                    post_args = {_target: "cloud", ...post_args};
                                    rcmail.__file_upload(files, post_args, props);
                                    dialog.dialog('close');
                                }
                            },
                            {
                                text: rcmail.gettext("attach", "nextcloud_attachments"),
                                'class': 'secondary',
                                click: () => {
                                    rcmail.__file_upload(files, post_args, props);
                                    dialog.dialog('close');
                                }
                            },
                        ]);
                }
            //pass on to original function, as we can't feasibly delay until the finishes, if they do at all
            return false;
        }//upload the files

        // file needs no handling, pass on
        return rcmail.__file_upload(files, post_args, props);
    }

    rcmail.__remove_attchment = rcmail.remove_attachment;

    rcmail.remove_attachment = function (name) {
        if (name && rcmail.env.attachments[name]?.isNextcloudAttachment) {
            const dialog = rcmail.show_popup_dialog(
                rcmail.gettext("remove_from_nextcloud_question", "nextcloud_attachments"),
                rcmail.gettext("remove_attachment", "nextcloud_attachments"), [
                    {
                        text: rcmail.gettext("remove_from_nextcloud", "nextcloud_attachments"),
                        'class': 'mainaction delete',
                        click: (e) => {
                            this.http_post('remove-attachment', { _id:rcmail.env.compose_id, _file:name, _ncremove: true });
                            dialog.dialog('close');
                        }

                    },
                    {
                        text: rcmail.gettext("keep_in_nextcloud", "nextcloud_attachments"),
                        'class': '',
                        click: () => {
                            rcmail.__remove_attchment(name);
                            dialog.dialog('close');
                        }
                    }]
            );
        } else {
            rcmail.__remove_attchment(name);
        }
    }

});