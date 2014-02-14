amazon-s3-migrator for IPC
--------------------------

How to use
==========

s3cmd is already installed on inspirewp-qa-web-01. When running the configure command for your user, you'll need to set the proxy host and proxy port to be `proxyhost` and `8080`:

    s3cmd --configure

To copy the images from local to s3, do the following. You'll need to do it by month as there are way to many files otherwise for most sites we have:

    s3cmd put -r /nfs/wordpressdata/inspirewp/sites/2/2014/02/ s3://testbasket/wp-content/uploads/sites/2/2014/02/  --add-header="Expires:`date -u +"%a, %d %b %Y %H:%M:%S GMT" --date "10 Years"`" --add-header='Cache-Control:max-age=315360000, public'
    s3cmd setacl s3://testbasket/wp-content/uploads/sites/2/2014/02/ --acl-public --recursive
