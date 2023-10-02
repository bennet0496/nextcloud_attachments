#!/bin/bash

curl -sSL https://raw.githubusercontent.com/apache/httpd/trunk/docs/conf/mime.types |\
       	awk -F '\t' '!/^#/{print $NF}' | grep -Pv '^$' |\
       	tr ' ' '\n' | sort > mime.extensions
