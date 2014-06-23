svn up
chmod 700 .
chmod 700 postcommit.py
chmod 600 auth.py
chmod 600 .htaccess
setfacl -R -b .
setfacl -m g:http:rwx .
setfacl -R -m g:http:rx postcommit.py
setfacl -R -m g:http:r .htaccess
