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

rcmail.addEventListener("plugin.nextcloud_login", function(data) {
    if (data.status === "ok") {
        rcmail.env.nextcloud_login_flow = data.url;
    } else {
        rcmail.display_messge("Nextcloud login failed. Please try again later!", "error", 10000);
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
rcmail.addEventListener("plugin.nextcloud_upload_result", function(event) {
    //server finished upload
    if (event.status === "ok") {
        let position_element, cursor_pos, p = -1,
            message = this.rcmail.editor.get_content(),
            sig = this.rcmail.env.identity;

        //convert to human-readable file size
        let size = event.result?.file?.size;
        const unit = ["", "k", "M", "G", "T"];
        let i = 0;
        for(i = 0; size > 800 && i < unit.length; i++) {
            size /= 1024;
        }

        //insert link to plaintext editor
        if(!this.rcmail.editor.is_html()){
            let attach_text = "\n" + event.result?.file?.name +
                " (" + size.toFixed(1) + " " + unit[i] + "B) <"
                + event.result?.url + ">" + "\n";
            //insert before signature if one exsists
            if(sig && this.rcmail.env.signatures && this.rcmail.env.signatures[sig]) {
                sig = this.rcmail.env.signatures[sig].text;
                sig = sig.replace(/\r\n/g, '\n');

                p = this.rcmail.env.top_posting ? message.indexOf(sig) : message.lastIndexOf(sig);

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
            let link = document.createElement("a");
            link.href = event.result?.url
            link.innerText = "📎 " + event.result?.file?.name + " (" + size.toFixed(1) + " " + unit[i] + "B)";

            let paragraph = document.createElement("p");
            paragraph.append(link);

            rcmail.editor.editor.getBody().insertBefore(paragraph, sigElem);
        }
        rcmail.display_message(event.result?.file?.name + " successfully uploaded to Nextcloud and link inserted", "confirmation", 5000)
        // this.rcmail.remove_from_attachment_list("rcmfile" + event.result?.file?.id);
    } else {
        // rcmail.show_popup_dialog(JSON.stringify(event), "Nextcloud Upload Failed");
        console.log(event);
        switch(event.status) {
            case "no_config":
                rcmail.display_message("Nextcloud plugin config missing. Contact Admin!", "notice", 2000);
                break;
            case "mkdir_error":
                rcmail.show_popup_dialog("couldn't create sub folder for upload: " + event.message, "Nextcloud Upload Failed");
                break;
            case "folder_error":
                rcmail.display_message("Couldn't access upload folder! Check permissions or ask the admin", "error", 2000);
                break;
            case "name_error":
                rcmail.show_popup_dialog("couldn't find a unique upload filename. Try renaming the file or files in you Nextcloud folder", "Nextcloud Upload Failed");
                break;
            case "upload_error":
                rcmail.display_message("Nextcloud Upload Failed: " + event.message, "error", 10000);
                break;
            case "link_error":
                rcmail.show_popup_dialog("upload of file succeeded, but we couldn't create a share link. Try to create the link manually or ask the admin for assistance.", "Nextcloud Warning");
                break;
        }
    }
});

rcmail.addEventListener('init', function(evt) {
    //retrieve nextcloud login status
    rcmail.http_get("plugin.nextcloud_checklogin");

    //intercept file_upload
    rcmail.__file_upload = rcmail.file_upload;

    rcmail.file_upload = function(files, post_args, props) {

        //server side isn't configured. skip to original method
        if (rcmail.env.nextcloud_upload_available !== true && rcmail.env.nextcloud_upload_login_available !== true) {
            return rcmail.__file_upload(files, post_args, props);
        }

        //calculate file size
        let size = files.map(f => f.size).reduce((sum, val) =>  sum + val, 0);

        //original max_filesize
        let os = rcmail.env.max_filesize;

        //intercept if files are too large
        if(size > rcmail.env.max_filesize) {
            //server indicated, it can't use the known username and password from the session
            //to login to nextcloud. Probably because, 2FA is active.
            if (rcmail.env.nextcloud_upload_available !== true && rcmail.env.nextcloud_upload_login_available === true) {
                rcmail.show_popup_dialog("<p>The file you tried to upload is too large. You can automatically upload "+
                    "large files by connecting to your cloud storage blow.</p>"+
                    "<p>After connecting the storage, please try uploading the file again.</p>", "File too big", [
                        {
                            text: "Login",
                            'class': 'mainaction login',
                            click: function(btn_evt){
                                //start login process
                                rcmail.http_post("plugin.nextcloud_login");
                                btn_evt.target.innerText = " ";
                                btn_evt.currentTarget.classList.add("button--loading");
                                //wait for login url and open it
                                setTimeout(function (t){
                                    if (rcmail.env.nextcloud_login_flow !== null) {
                                        if(!window.open(rcmail.env.nextcloud_login_flow, "", "noopener,noreferrer,popup")) {
                                            t.append("<p>Click <a href=\"" + rcmail.env.nextcloud_login_flow + "\">here</a> if no window opened</p>");
                                        } else {
                                            t.dialog('close');
                                            rcmail.display_message("Logged-in to Nextcloud", "loading", 10000);
                                        }
                                        rcmail.env.nextcloud_login_flow = null;
                                    } else {
                                        t.dialog('close');
                                        rcmail.display_message("No login link received. Please try again later.", "error", 10000);
                                    }
                                }, 1000, $(this))

                                //wait for login to finish
                                window.nextcloud_poll_interval = setInterval(function (t) {
                                    if(rcmail.env.nextcloud_upload_login_available === true && rcmail.env.nextcloud_upload_available === true) {
                                        t.dialog('close');
                                        clearInterval(window.nextcloud_poll_interval);
                                        rcmail.display_message("Successfully logged-in to Nextcloud", "confirmation", 10000);
                                    }
                                }, 1000, $(this));
                            }
                        }]);
                //pass on to original function, as we can't feasibly delay until the finishes, if they do at all
                return rcmail.__file_upload(files, post_args, props);
            }

            //if we can log in to nextcloud, we temporally increase the limit
            //so the checks in the original function will pass
            rcmail.env.max_filesize += 2 * size;
            //mark for callback that we want this function to go to the cloud
            post_args = {_target: "cloud", ...post_args};
        }

        //upload the files
        let ret = rcmail.__file_upload(files, post_args, props);

        //restore the old limit
        rcmail.env.max_filesize = os;

        return ret;
    }

});