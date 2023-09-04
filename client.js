class MaskedFile extends File {
    get size() {
        return 0;
    }
}

rcmail.addEventListener("plugin.nc_file_link", console.log)

rcmail.addEventListener('init', function(evt) {
    console.log(evt, this);
    this.rcmail.__file_upload = this.rcmail.file_upload;
    this.rcmail.file_upload = function(files, post_args, props) {
        console.log(this, files, post_args, props);
        console.log(this.editor.get_content());

        let f, i, fname, numfiles = files.length,
            formdata = new FormData(),
            fieldname = props.name || '_file[]',
            limit = props.single ? 1 : files.length;

        let args = $.extend({_remote: 1, _from: this.env.action}, post_args || {});

        let ts = 'upload' + new Date().getTime(),
            label = numfiles > 1 ? this.get_label('uploadingmany') : fname,
            // jQuery way to escape filename (#1490530)
            content = $('<span>').text(label).html();

        args._uploadid = ts;
        args._unlock = props.lock;

        let size = files.map(f => f.size).reduce((sum, val) =>  sum + val, 0);

        files.map(f => {
            formdata.append(fieldname, f);
        })

        let os = this.env.max_filesize;

        if(size > this.env.max_filesize) {
            // this.simple_dialog("content", "title", null, null);

            this.env.max_filesize += 2 * size;
            // console.log(files.map(f => Object.assign(new MaskedFile(), f) ));
            post_args = {_target: "cloud", ...post_args};
        }

        let position_element, cursor_pos, p = -1,
            message = this.editor.get_content(),
            sig = this.env.identity;

        if(!this.editor.is_html()){
            if(sig && this.env.signatures && this.env.signatures[sig]) {
                sig = this.env.signatures[sig].text;
                sig = sig.replace(/\r\n/g, '\n');

                p = this.env.top_posting ? message.indexOf(sig) : message.lastIndexOf(sig);

                message = message.substring(0, p) + files.map(file => file.name).join(", ") + "\n" + message.substring(p, message.length);
                this.editor.set_content(message);
            }
        } else {
            let sigElem = this.editor.editor.dom.get("_rc_sig");
            let body = this.editor.editor.getBody();

            for(let file of files) {
                console.log(file);
                let link = document.createElement("a");
                link.href="#"
                link.innerText = "📎 " + file.name;

                let paragraph = document.createElement("p");
                paragraph.append(link);

                this.editor.editor.getBody().insertBefore(paragraph, sigElem);
                // this.editor.editor.getBody().insertBefore(document.createElement("br"), sigElem);
            }
        }

        let ret = this.__file_upload(files, post_args, props);

        this.env.max_filesize = os;

        return ret;
    }

});

rcmail.addEventListener('send-attachment', function(evt) {
    console.log(evt);
});